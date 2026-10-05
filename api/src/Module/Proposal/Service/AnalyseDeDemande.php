<?php

declare(strict_types=1);

namespace App\Module\Proposal\Service;

use App\Module\Core\Enum\SubjectKind;
use App\Module\Proposal\Contract\InventaireDeSuppression;
use App\Module\Proposal\Contract\LienARetirer;
use App\Module\Proposal\Contract\RetraitDeLien;
use App\Module\Proposal\Entity\ChangeRequest;
use App\Module\Proposal\Entity\ChangeRequestOp;
use App\Module\Proposal\Entity\DraftLink;
use App\Module\Proposal\Enum\OpKind;
use App\Module\Proposal\Enum\RequestStatus;
use App\Module\Proposal\Repository\ChangeRequestOpRepository;
use App\Module\Proposal\Repository\DraftLinkRepository;
use App\Module\Proposal\Repository\DraftPersonRepository;
use App\Module\Proposal\Service\Port\EtatCible;
use App\Module\Proposal\Service\Port\PortArbre;

/**
 * Le contrôle préalable du § 9.4, point 2 — **aucune écriture**.
 *
 * Il répond à une seule question, et la réponse tient en six valeurs : cette demande
 * s'applique-t-elle telle quelle, se rejoue-t-elle, est-elle caduque, sa cible a-t-elle
 * disparu, attend-elle qu'une **autre demande** soit validée d'abord, ou cette autre
 * demande est-elle morte ?
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
 * ── Les liens, qui n'ont pas d'op à confronter ──────────────────────────────────
 *
 * Une création ne porte **aucun op** : tout ce qu'elle affirme est dans ses liens, et
 * ceux-ci désignent trois sortes de choses — des personnes de l'arbre, des fiches de la
 * demande elle-même, et depuis `Version20260917190000` des fiches proposées par
 * **d'autres demandes en attente**. Les deux premières n'ont rien de neuf à contrôler ;
 * la troisième introduit un **ordre de validation** entre deux demandes, et c'est
 * {@see dependancesDe()} qui le dit avant le premier clic.
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
        private DraftPersonRepository $brouillons,
        private PortArbre $arbre,
        private NormaliseurDeValeur $normaliseur,
        private SurfaceEditable $surface,
        private InventaireDeLaSuppression $inventaire,
        private MesureDesRetraits $retraits,
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
        $liens = $this->liens->pourDemande($demande);
        $disparues = $plan->disparues;

        foreach ($liens as $lien) {
            foreach ($this->referencesReelles($lien) as [$kind, $id]) {
                if (null === $this->arbre->chargerCible($kind, $id)) {
                    $disparues[] = ['kind' => $kind->value, 'id' => $id];
                }
            }
        }

        $dependances = $this->dependancesDe($liens);

        if ($disparues === $plan->disparues && [] === $dependances) {
            return $plan;
        }

        return new PlanDeValidation(
            $this->trancherAvecLesLiens($plan->resolution, $disparues, $dependances),
            $plan->ops,
            $plan->cibles,
            $disparues,
            $dependances,
        );
    }

    /**
     * La résolution, une fois les liens pris en compte.
     *
     * **L'ordre des tests est la règle elle-même**, et il prolonge celui de
     * {@see trancher()} : ce qui condamne l'emporte sur ce qui fait attendre. Une
     * dépendance en attente n'est pas un malheur — c'est un ordre de validation —, et
     * l'annoncer alors qu'un champ a par ailleurs bougé sous les pieds de l'auteur
     * enverrait l'administrateur attendre une autre demande pour découvrir ensuite que
     * celle-ci était morte depuis le début.
     *
     * @param list<array{kind: string, id: int}>                                                  $disparues
     * @param list<array{draftPersonId: int, demandeId: int|null, libelle: string, etat: string}> $dependances
     */
    private function trancherAvecLesLiens(
        ResolutionPrevue $resolution,
        array $disparues,
        array $dependances,
    ): ResolutionPrevue {
        if ([] !== $disparues) {
            return ResolutionPrevue::CibleDisparue;
        }

        $mortes = array_filter(
            $dependances,
            static fn (array $dependance): bool => \in_array($dependance['etat'], ['morte', 'disparue'], true),
        );

        if ([] !== $mortes) {
            return ResolutionPrevue::DependanceMorte;
        }

        if ($resolution->estCaduque()) {
            return $resolution;
        }

        $attendues = array_filter(
            $dependances,
            static fn (array $dependance): bool => 'en_attente' === $dependance['etat'],
        );

        return [] === $attendues ? $resolution : ResolutionPrevue::DependanceEnAttente;
    }

    /**
     * Les fiches proposées par **d'autres** demandes que celle-ci désigne, et l'état de
     * chacune.
     *
     * C'est la moitié manquante du contrôle préalable depuis qu'une extrémité de lien
     * peut désigner une fiche qui n'existe ni dans l'arbre ni dans la demande courante.
     * Quatre états, et chacun mène ailleurs :
     *
     * | État | Ce que ça veut dire | Ce qui en découle |
     * |---|---|---|
     * | `resolue` | l'autre demande est validée, la personne existe | on applique, l'identifiant est connu |
     * | `en_attente` | l'autre demande n'est pas tranchée (soumise, ou encore en brouillon) | `dependance_en_attente` — on attend |
     * | `morte` | refusée, retirée ou caduque | caducité : la personne ne naîtra pas |
     * | `disparue` | la ligne `prp_draft_person` n'est plus là | caducité, pour la même raison |
     *
     * La lecture se fait **sans portée d'auteur** : on est au contrôle préalable d'une
     * validation, c'est-à-dire du côté de l'administrateur, et la question n'est pas
     * « qui a le droit de voir cette fiche » mais « cette fiche deviendra-t-elle une
     * personne ». Le filtre du § 13.3 s'applique au dépôt, où il a un sens.
     *
     * @param list<DraftLink> $liens
     *
     * @return list<array{draftPersonId: int, demandeId: int|null, libelle: string, etat: string}>
     */
    private function dependancesDe(array $liens): array
    {
        $vues = [];
        $dependances = [];

        foreach ($liens as $lien) {
            $references = [
                $lien->getSubjectDraftPersonId(),
                $lien->getOtherDraftPersonId(),
                $lien->getOther2DraftPersonId(),
            ];

            foreach ($references as $id) {
                // Une même fiche peut être désignée par plusieurs liens — père de l'un,
                // conjoint de l'autre. On ne la compte qu'une fois : l'écran de décision
                // afficherait sinon trois fois la même phrase.
                if (null === $id || isset($vues[$id])) {
                    continue;
                }

                $vues[$id] = true;
                $dependances[] = $this->etatDeLaDependance($id);
            }
        }

        return $dependances;
    }

    /** @return array{draftPersonId: int, demandeId: int|null, libelle: string, etat: string} */
    private function etatDeLaDependance(int $draftPersonId): array
    {
        $brouillon = $this->brouillons->find($draftPersonId);

        if (null === $brouillon) {
            return [
                'draftPersonId' => $draftPersonId,
                'demandeId' => null,
                // Le libellé ne peut pas être relu : la ligne n'est plus là. Le laisser
                // vide plutôt que d'inventer « personne n° 3 » — un numéro de ligne
                // interne n'apprend rien à l'auteur qui lira la notification.
                'libelle' => '',
                'etat' => 'disparue',
            ];
        }

        $autre = $brouillon->getRequest();

        $etat = match (true) {
            null !== $brouillon->getResolvedPersonId() => 'resolue',
            // Un brouillon non envoyé n'est pas mort : son auteur peut encore l'envoyer.
            RequestStatus::EnAttente === $autre->getStatus(),
            RequestStatus::Brouillon === $autre->getStatus() => 'en_attente',
            default => 'morte',
        };

        return [
            'draftPersonId' => $draftPersonId,
            'demandeId' => $autre->getId(),
            'libelle' => $brouillon->nomPropose(),
            'etat' => $etat,
        ];
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

            // Un op de **média** (`ajout_media`) vise une ligne de `med_media`, pas une
            // fiche de l'arbre : il n'a ni version ni valeur à confronter, et le port de
            // l'arbre refuse ce type de cible (il levait, et l'écran d'administration de
            // toute demande « Ajout d'un document » tombait en 500 — 03/10/2026). Son
            // existence est vérifiée à l'application, par le module Media.
            if (SubjectKind::Media === $op->getSubjectKind()) {
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

        if (OpKind::RetraitLien === $op->getOpKind()) {
            // La mesure **d'aujourd'hui** : ce que défaire ce lien ferait maintenant —
            // union conservée ou supprimée, enfants qui ne garderaient qu'un parent, lien
            // déjà défait par quelqu'un d'autre. C'est elle que l'écran d'administration
            // affiche à côté de la mesure du dépôt.
            $lien = $this->lienARetirer($op);

            return null === $lien ? null : $this->retraits->de($lien)?->versJson();
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
     * ── Trois natures d'op se comparent, et pas les six autres ───────────────────────
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
     * `retrait_lien` se compare pour la même raison que la suppression : sa mesure dit ce
     * que le retrait **fera** — l'union conservée ou supprimée, les enfants qui ne
     * garderont qu'un parent —, et un enfant arrivé ou une date posée depuis le dépôt lui
     * ferait faire autre chose que ce que l'administrateur a lu.
     *
     * Les six autres natures ne se comparent pas : elles **ajoutent**, et ce qu'elles
     * ajoutent n'existe pas encore. Il n'y a rien à confronter à l'état courant.
     */
    private function aBouge(ChangeRequestOp $op, mixed $valeurCourante): bool
    {
        return match ($op->getOpKind()) {
            OpKind::Champ => $valeurCourante !== $this->normaliserStocke($op),
            OpKind::SuppressionPersonne => $valeurCourante !== $this->inventaireStocke($op),
            OpKind::RetraitLien => $valeurCourante !== $this->retraitStocke($op),
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

        /* @var array<string, mixed> $stocke */
        return InventaireDeSuppression::depuisJson($stocke)->versJson();
    }

    /**
     * La mesure du dépôt d'un retrait de lien, repassée par son propre constructeur —
     * même détour, même raison que {@see inventaireStocke()}.
     */
    private function retraitStocke(ChangeRequestOp $op): mixed
    {
        $stocke = $op->getOldValue();

        if (!\is_array($stocke)) {
            return null;
        }

        /* @var array<string, mixed> $stocke */
        return RetraitDeLien::depuisJson($stocke)->versJson();
    }

    /** Le lien qu'un op `retrait_lien` désigne, relu depuis sa mesure du dépôt. */
    private function lienARetirer(ChangeRequestOp $op): ?LienARetirer
    {
        $stocke = $op->getOldValue();

        if (!\is_array($stocke)) {
            return null;
        }

        /** @var array<string, mixed> $stocke */
        return RetraitDeLien::depuisJson($stocke)->lien();
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

        if (OpKind::RetraitLien === $op->getOpKind()) {
            // La phrase que l'administrateur lit avant de cliquer, composée depuis la
            // mesure courante quand elle existe — la même sera journalisée après.
            $courant = \is_array($valeurCourante) ? $valeurCourante : null;
            $stocke = \is_array($op->getOldValue()) ? $op->getOldValue() : [];

            /** @var array<string, mixed> $source */
            $source = $courant ?? $stocke;

            return RetraitDeLien::depuisJson($source)->enUneLigne();
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
