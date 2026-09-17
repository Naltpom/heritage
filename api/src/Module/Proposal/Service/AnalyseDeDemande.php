<?php

declare(strict_types=1);

namespace App\Module\Proposal\Service;

use App\Module\Core\Enum\SubjectKind;
use App\Module\Proposal\Contract\InventaireDeSuppression;
use App\Module\Proposal\Entity\ChangeRequest;
use App\Module\Proposal\Entity\ChangeRequestOp;
use App\Module\Proposal\Entity\DraftLink;
use App\Module\Proposal\Enum\OpKind;
use App\Module\Proposal\Repository\ChangeRequestOpRepository;
use App\Module\Proposal\Repository\DraftLinkRepository;
use App\Module\Proposal\Service\Port\EtatCible;
use App\Module\Proposal\Service\Port\PortArbre;

/**
 * Le contrôle préalable du § 9.4, point 2 — **aucune écriture**.
 *
 * Il répond à une seule question, et la réponse tient en quatre valeurs : cette demande
 * s'applique-t-elle telle quelle, se rejoue-t-elle, est-elle caduque, ou sa cible
 * a-t-elle disparu ?
 *
 * ── La règle, en deux temps ──────────────────────────────────────────────────────
 *
 * 1. pour chaque op, comparer `version` courante à `op.base_version`. **Si toutes
 *    concordent, on applique** : c'est le cas ordinaire ;
 * 2. si une version a bougé, **on ne condamne pas la demande pour autant**. On compare
 *    **op par op** `old_value` à la valeur courante du champ visé :
 *    - **toutes** correspondent encore → **rejeu automatique** sur la version courante.
 *      C'est le cas des champs disjoints, qui est le cas fréquent ;
 *    - **une seule** a changé → **`caduque`**, et on sort sans rien écrire. On n'écrase
 *      jamais.
 *
 * ── Pourquoi la comparaison porte sur `old_value` et pas sur `base_version` ──────
 *
 * Le verrou optimiste porte sur la **ligne**, pas sur le champ : il part
 * systématiquement en conflit dès qu'une autre écriture a touché la fiche, **même sur
 * des champs disjoints**. S'arrêter là condamnerait la seconde de deux demandes
 * déposées la même semaine, alors que contribuer est le but du produit (arbitrage C).
 * C'est `old_value`, stocké **par op**, qui permet de dire mieux que « la version a
 * changé ».
 *
 * ── La comparaison elle-même ─────────────────────────────────────────────────────
 *
 * Elle se fait sur la **forme JSON normalisée** des deux valeurs, jamais sur les objets
 * PHP. `UncertainDate` n'a pas d'égalité structurelle en PHP (deux `DateTimeImmutable`
 * distincts pour la même date ne sont pas `===`), et `==` sur des objets comparerait
 * des propriétés privées dans un ordre non garanti. Passer par
 * {@see NormaliseurDeValeur::versJson()} rend la comparaison identique à celle que fait
 * `JSONB` en base, ce qui est exactement ce que le § 9.4 attendait de ce type.
 */
final readonly class AnalyseDeDemande
{
    public function __construct(
        private ChangeRequestOpRepository $ops,
        private DraftLinkRepository $liens,
        private PortArbre $arbre,
        private NormaliseurDeValeur $normaliseur,
        private SurfaceEditable $surface,
        private InventaireDeLaSuppression $inventaire,
    ) {
    }

    public function analyser(ChangeRequest $demande): PlanDeValidation
    {
        $plan = $this->analyserOps($this->ops->pourDemande($demande));

        return $this->verifierLesLiens($demande, $plan);
    }

    /**
     * Passe 1 du § 9.5, la moitié qui ne porte pas sur des ops : **toute référence
     * réelle d'un lien existe-t-elle encore**?
     *
     * Une demande de création n'a aucun op — le § 6.5 du contrat la montre avec
     * `"ops": []` — et pourtant elle vise des personnes et des familles : le conjoint
     * désigné, les enfants rattachés, le couple existant. Si l'une de ces lignes a
     * disparu entre le dépôt et la validation, la demande pointe vers le vide.
     *
     * **C'est une caducité, pas un refus** : personne n'a refusé, le monde a bougé
     * (ADR 0004). Le cas est normalement traité à l'événement publié par `Tree`, avant
     * même qu'on ouvre la demande ; ce contrôle-ci est la **seconde barrière**, et il
     * existe parce qu'un abonné qui rate un événement laisserait sinon une demande
     * s'appliquer sur une référence pendante.
     */
    private function verifierLesLiens(ChangeRequest $demande, PlanDeValidation $plan): PlanDeValidation
    {
        $disparues = $plan->disparues;

        foreach ($this->liens->pourDemande($demande) as $lien) {
            foreach ($this->referencesReelles($lien) as [$kind, $id]) {
                if (null === $this->arbre->chargerCible($kind, $id)) {
                    $disparues[] = ['kind' => $kind->value, 'id' => $id];
                }
            }
        }

        if ($disparues === $plan->disparues) {
            return $plan;
        }

        return new PlanDeValidation(ResolutionPrevue::CibleDisparue, $plan->ops, $plan->cibles, $disparues);
    }

    /**
     * Les extrémités d'un lien qui désignent une ligne **existante**. Une extrémité qui
     * désigne un brouillon n'a rien à vérifier : la ligne n'existe pas encore, et c'est
     * normal.
     *
     * @return list<array{SubjectKind, int}>
     */
    private function referencesReelles(DraftLink $lien): array
    {
        $references = [];

        foreach ([$lien->getSubjectPersonId(), $lien->getOtherPersonId(), $lien->getOther2PersonId()] as $personneId) {
            if (null !== $personneId) {
                $references[] = [SubjectKind::Personne, $personneId];
            }
        }

        if (null !== $lien->getFamilyId()) {
            $references[] = [SubjectKind::Famille, $lien->getFamilyId()];
        }

        return $references;
    }

    /**
     * @param list<ChangeRequestOp> $ops
     */
    public function analyserOps(array $ops): PlanDeValidation
    {
        $cibles = [];
        $disparues = [];
        $etats = [];
        $uneValeurABouge = false;
        $uneVersionABouge = false;

        foreach ($ops as $op) {
            $sujet = $op->getSubjectId();

            // Un op qui vise un brouillon n'a rien à confronter : la ligne n'existe pas
            // encore, et c'est normal. **On ne suppose jamais que la cible d'une demande
            // existe déjà en base** — c'est ce qui permettra à la création de personne de
            // se greffer sur cette machinerie sans la refaire (§ 6.4 du dossier).
            if (null === $sujet) {
                $etats[] = new EtatDOp($op, null, false, false, $this->libelle($op, null));

                continue;
            }

            $cle = EtatCible::cleDe($op->getSubjectKind(), $sujet);

            if (!\array_key_exists($cle, $cibles)) {
                $etat = $this->arbre->chargerCible($op->getSubjectKind(), $sujet);

                if (null === $etat) {
                    $disparues[] = ['kind' => $op->getSubjectKind()->value, 'id' => $sujet];
                }

                $cibles[$cle] = $etat;
            }

            $cible = $cibles[$cle];

            if (null === $cible) {
                $etats[] = new EtatDOp($op, null, false, false, $this->libelle($op, null));

                continue;
            }

            $versionABouge = null !== $op->getBaseVersion() && $op->getBaseVersion() !== $cible->version;
            $uneVersionABouge = $uneVersionABouge || $versionABouge;

            $valeurCourante = $this->valeurCourante($op, $cible);
            $aBouge = $this->aBouge($op, $valeurCourante);

            $uneValeurABouge = $uneValeurABouge || $aBouge;

            $etats[] = new EtatDOp($op, $valeurCourante, $aBouge, $versionABouge, $this->libelle($op, $valeurCourante));
        }

        /** @var array<string, EtatCible> $presentes */
        $presentes = array_filter($cibles, static fn (?EtatCible $etat): bool => null !== $etat);

        return new PlanDeValidation(
            $this->trancher([] !== $disparues, $uneVersionABouge, $uneValeurABouge),
            $etats,
            $presentes,
            $disparues,
        );
    }

    private function trancher(bool $cibleDisparue, bool $versionABouge, bool $valeurABouge): ResolutionPrevue
    {
        // L'ordre des tests est la règle elle-même : une cible disparue l'emporte sur
        // tout — il n'y a plus rien à comparer —, puis une valeur qui a bougé condamne,
        // puis une version qui a bougé fait rejouer, et le reste s'applique.
        return match (true) {
            $cibleDisparue => ResolutionPrevue::CibleDisparue,
            $valeurABouge => ResolutionPrevue::Caducite,
            $versionABouge => ResolutionPrevue::Rejeu,
            default => ResolutionPrevue::Application,
        };
    }

    /**
     * La valeur courante du champ visé, dans la forme comparable à `old_value`.
     *
     * Un champ absent de `EtatCible::valeurs` rend `null` — et c'est un cas qui doit se
     * voir : il fera diverger la comparaison et déclenchera une caducité plutôt qu'une
     * écriture à l'aveugle. Un port qui rendrait moins de champs que la surface éditable
     * rendrait indétectable une caducité sur les champs manquants ; le contrat de
     * {@see PortArbre} l'exige pour cette raison.
     */
    private function valeurCourante(ChangeRequestOp $op, EtatCible $cible): mixed
    {
        if (OpKind::SuppressionPersonne === $op->getOpKind()) {
            // L'inventaire **d'aujourd'hui**, relu à chaque lecture : c'est exactement ce
            // que `valeurCourante` veut dire partout ailleurs dans ce fichier, et c'est ce
            // que l'écran d'administration affiche à côté de l'inventaire du dépôt.
            //
            // `null` est impossible ici : une fiche disparue a déjà été tranchée en
            // `cible_disparue` par l'appelant, qui n'arrive pas jusqu'ici. Le `?->` est le
            // filet, pas le cas.
            $sujet = $op->getSubjectId();

            return null === $sujet ? null : $this->inventaire->de($sujet)?->versJson();
        }

        $champ = $op->getField();

        if (OpKind::Champ !== $op->getOpKind() || null === $champ) {
            return null;
        }

        if (!\array_key_exists($champ, $cible->valeurs)) {
            return null;
        }

        return $this->normaliseur->versJson($op->getValueKind(), $cible->valeurs[$champ]);
    }

    /**
     * La valeur de cet op a-t-elle bougé sous les pieds de son auteur ?
     *
     * ── Deux natures d'op se comparent, et pas les six autres ────────────────────────
     *
     * `champ` se compare parce que c'est tout ce qu'elle est : une ancienne valeur et une
     * nouvelle. `suppression_personne` se compare pour une raison différente et plus
     * forte — **l'inventaire de ce qu'elle emporte est une promesse faite à celui qui
     * valide**. Une note, une citation ou une preuve ajoutée entre le dépôt et la
     * validation ferait effacer une ligne que personne ne lui a montrée, et c'est
     * exactement ce que la règle « rien ne part en silence » interdit.
     *
     * Le prix est assumé : documenter une fiche pendant qu'une demande de suppression
     * l'attend rend cette demande **caduque**, et son auteur devra la refaire. C'est le
     * bon sens de l'erreur — une suppression qu'on redemande coûte un clic, une
     * suppression qui emporte une ligne non annoncée coûte la ligne.
     *
     * Les six autres natures ne se comparent pas : elles **ajoutent**, et ce qu'elles
     * ajoutent n'existe pas encore. Il n'y a rien à confronter à l'état courant.
     */
    private function aBouge(ChangeRequestOp $op, mixed $valeurCourante): bool
    {
        return match ($op->getOpKind()) {
            OpKind::Champ => $valeurCourante !== $this->normaliserStocke($op),
            OpKind::SuppressionPersonne => $valeurCourante !== $this->inventaireStocke($op),
            default => false,
        };
    }

    /**
     * L'inventaire du dépôt, repassé par son propre constructeur.
     *
     * Le détour a la même raison que celui de {@see normaliserStocke()} : `JSONB` rend
     * des tableaux dont **PostgreSQL** a choisi l'ordre des clés, et SQLite celui de
     * l'écriture. Comparer les deux formes brutes rendrait caduque une demande que rien ne
     * contredit, et le message ne pourrait nommer aucun champ en conflit — le pire des
     * deux mondes.
     */
    private function inventaireStocke(ChangeRequestOp $op): mixed
    {
        $stocke = $op->getOldValue();

        if (!\is_array($stocke)) {
            return null;
        }

        /** @var array<string, mixed> $stocke */
        return InventaireDeSuppression::depuisJson($stocke)->versJson();
    }

    /**
     * L'`old_value` telle qu'elle est stockée, repassée par le normaliseur.
     *
     * Le détour n'est pas inutile : `JSONB` rend des tableaux associatifs dont l'ordre
     * des clés dépend de PostgreSQL, et SQLite (en test) de l'ordre d'écriture. Les
     * renormaliser des deux côtés rend la comparaison indépendante de la base.
     */
    private function normaliserStocke(ChangeRequestOp $op): mixed
    {
        $stocke = $op->getOldValue();

        if (\is_array($stocke)) {
            return $this->normaliseur->versJson(
                $op->getValueKind(),
                $this->normaliseur->reconstruire($stocke),
            );
        }

        return $stocke;
    }

    /** « Naissance : 1987 → entre 1901 et 1909 » (§ 6.7). */
    private function libelle(ChangeRequestOp $op, mixed $valeurCourante): string
    {
        if (OpKind::SuppressionPersonne === $op->getOpKind()) {
            // La phrase que l'administrateur lit avant de cliquer : « Suppression de la
            // fiche « Untel » : emporte 3 notes, 2 citations ». Elle est composée depuis
            // l'inventaire **courant** quand il est là, et depuis celui du dépôt sinon —
            // la même phrase sera journalisée après, par l'applicateur.
            $courant = \is_array($valeurCourante) ? $valeurCourante : null;
            $stocke = \is_array($op->getOldValue()) ? $op->getOldValue() : [];

            /** @var array<string, mixed> $source */
            $source = $courant ?? $stocke;

            return InventaireDeSuppression::depuisJson($source)->enUneLigne();
        }

        $champ = $op->getField();

        if (null === $champ) {
            return $op->getOpKind()->value;
        }

        return \sprintf(
            '%s : %s → %s',
            $this->surface->libelle($champ),
            $this->normaliseur->enUneLigne($op->getValueKind(), $op->getOldValue()),
            $this->normaliseur->enUneLigne($op->getValueKind(), $op->getNewValue()),
        );
    }
}
