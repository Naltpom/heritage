<?php

declare(strict_types=1);

namespace App\Module\Proposal\Service;

use App\Module\Assistant\Contract\ChangementVerifie;
use App\Module\Assistant\Contract\DemandeIrrecevable;
use App\Module\Assistant\Contract\DemandeVerifiee;
use App\Module\Assistant\Contract\DepotPourLAssistant;
use App\Module\Core\Enum\Sex;
use App\Module\Core\Enum\SubjectKind;
use App\Module\Proposal\Enum\OpKind;
use App\Module\Proposal\Enum\RequestKind;
use App\Module\Proposal\Enum\ValueKind;
use App\Module\Proposal\Exception\ErreurDeDemande;
use App\Module\Proposal\Service\Port\EtatCible;
use App\Module\Proposal\Service\Port\PortArbre;

/**
 * ═══════════════════════════════════════════════════════════════════════════════
 *  L'ASSISTANT PROPOSE, `Proposal` DÉPOSE — et par le même chemin que tout le monde.
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * L'adaptateur du contrat `Assistant\Contract\DepotPourLAssistant`. Il fait trois choses
 * que l'assistant n'a ni le droit ni les moyens de faire :
 *
 * 1. **poser les deux verrous de l'ADR 0004** sur chaque op de modification —
 *    `baseVersion` et `oldValue` sont lus ICI, sur la fiche courante, au moment de
 *    l'analyse. Le modèle ne les fournit pas et ne pourrait pas les fournir juste : il
 *    ne connaît que ce que les outils lui ont montré, et une valeur recopiée par un
 *    modèle est une valeur qu'il a pu reformuler ;
 * 2. **valider sans écrire**, par {@see DepotDeDemande::verifierSansEcrire()} — donc par
 *    les constructeurs mêmes du dépôt, pas par une seconde liste de règles ;
 * 3. **déposer** au clic du membre, par {@see DepotDeDemande::deposer()} — le service de
 *    `POST /api/demandes`, avec sa file, sa notification, son `author_label` figé.
 *
 * Le lecteur est résolu ici, depuis la session ({@see FabriqueDeLecteur}), et jamais
 * reçu de l'appelant : c'est le choix de `DepotDeReponsesAuxQuestions`, pour la même
 * raison.
 *
 * ── Ce que ce chemin n'ajoute PAS : un contrôle de cercle ───────────────────────
 *
 * Le commanditaire a tranché que l'assistant peut proposer une modification sur **toute**
 * personne, vivants hors cercle compris — la demande reste soumise à un administrateur,
 * qui voit tout. Le dépôt habituel ne refuse pas ces cibles (les constructeurs lisent
 * l'arbre par `PortArbre`, qui n'est pas filtré) : il n'y a donc **rien à assouplir**
 * ici, et `POST /api/demandes` n'a pas bougé d'une ligne. Ce qui protège le membre, c'est
 * que les valeurs lues ici ne lui sont pas montrées (voir `Assistant\Service\VueDuLecteur`).
 */
final readonly class DemandesDeLAssistant implements DepotPourLAssistant
{
    /** Les seules natures que l'assistant propose. Jamais de suppression. */
    private const array NATURES = [
        RequestKind::CreationPersonne,
        RequestKind::ModificationPersonne,
        RequestKind::AjoutLien,
    ];

    public function __construct(
        private DepotDeDemande $depot,
        private FabriqueDeLecteur $lecteurs,
        private PortArbre $arbre,
        private SurfaceEditable $surface,
        private NormaliseurDeValeur $normaliseur,
        private SerialiseurDeDemande $serialiseur,
    ) {
    }

    public function preparer(array $corps): DemandeVerifiee
    {
        $nature = RequestKind::tryFrom(\is_string($corps['kind'] ?? null) ? $corps['kind'] : '');

        if (null === $nature || !\in_array($nature, self::NATURES, true)) {
            throw new DemandeIrrecevable('L’assistant ne propose que des ajouts de personne, des modifications de fiche et des liens de parenté.');
        }

        $changements = [];

        if (RequestKind::ModificationPersonne === $nature) {
            [$corps, $changements] = $this->completerLesOps($corps);
        }

        try {
            $this->depot->verifierSansEcrire($corps, $this->lecteurs->courant());
        } catch (ErreurDeDemande $e) {
            throw new DemandeIrrecevable(self::expliquer($e), $e);
        }

        return new DemandeVerifiee($corps, $changements);
    }

    public function deposer(array $corps): array
    {
        $corps['soumettre'] = true;

        return $this->serialiseur->pourAuteur($this->depot->deposer($corps, $this->lecteurs->courant()));
    }

    /**
     * Les ops d'une modification, **complétés sur la fiche courante**.
     *
     * Trois gestes, et chacun a sa raison :
     *
     * - `valueKind` découle du champ (liste blanche de {@see SurfaceEditable}) : un champ
     *   hors liste est refusé ici, avec un message qui le nomme ;
     * - un op dont la valeur proposée **est déjà** celle de la fiche est retiré. Le
     *   modèle recopie volontiers une naissance déjà juste en « confirmant » un acte, et
     *   le dépôt refuserait toute la demande pour ce seul op (« une modification qui ne
     *   modifie rien ») ;
     * - une **date de décès** posée sur une fiche marquée vivante entraîne l'op
     *   « personne décédée ». Le formulaire refuse de cocher la case à la place du
     *   contributeur ; ici, c'est un document qui atteste le décès, et le membre voit les
     *   deux lignes dans la relecture avant de déposer. Sans cet op, la demande serait
     *   refusée par `ConstructeurDeModification::verifierQueLeDecesEstCoherent()`.
     *
     * @param array<string, mixed> $corps
     *
     * @return array{array<string, mixed>, list<ChangementVerifie>}
     */
    private function completerLesOps(array $corps): array
    {
        $ops = $corps['ops'] ?? null;

        if (!\is_array($ops) || [] === $ops) {
            throw new DemandeIrrecevable('Aucun changement n’était proposé.');
        }

        /** @var array<int, EtatCible> $cibles */
        $cibles = [];
        $completes = [];
        $changements = [];

        foreach (array_values($ops) as $index => $op) {
            if (!\is_array($op) || !\is_int($op['subjectId'] ?? null) || !\is_string($op['field'] ?? null)) {
                throw new DemandeIrrecevable('Un changement proposé est incomplet.');
            }

            $sujet = $op['subjectId'];
            $champ = $op['field'];
            $nature = $this->surface->natureDe(SubjectKind::Personne, $champ);

            if (null === $nature) {
                throw new DemandeIrrecevable(\sprintf('Le champ « %s » ne se modifie pas par une demande.', $champ));
            }

            $cible = $cibles[$sujet] ??= $this->arbre->chargerCible(SubjectKind::Personne, $sujet)
                ?? throw new DemandeIrrecevable(\sprintf('La personne n° %d n’existe pas dans l’arbre.', $sujet));

            try {
                $nouvelle = $this->normaliseur->versJson(
                    $nature,
                    $this->normaliseur->versPhp($nature, $op['newValue'] ?? null, "ops[$index].newValue"),
                );
            } catch (ErreurDeDemande $e) {
                throw new DemandeIrrecevable(self::expliquer($e), $e);
            }

            $ancienne = $this->normaliseur->versJson($nature, $cible->valeurs[$champ] ?? null);

            if ($ancienne === $nouvelle) {
                continue;
            }

            $completes[] = $this->op($sujet, $champ, $nature, $ancienne, $nouvelle, $cible->version);
        }

        $completes = $this->avecLeDecesSiUneDateLAtteste($completes, $cibles);

        if ([] === $completes) {
            throw new DemandeIrrecevable('Tout ce qui était proposé figure déjà sur la fiche.');
        }

        foreach ($completes as $rang => $op) {
            $completes[$rang]['seq'] = $rang + 1;
            $nature = ValueKind::from($op['valueKind']);

            $changements[] = new ChangementVerifie(
                $op['subjectId'],
                $op['field'],
                $this->surface->libelle($op['field']),
                $this->enClair($op['field'], $nature, $op['oldValue']),
                $this->enClair($op['field'], $nature, $op['newValue']),
            );
        }

        $corps['ops'] = $completes;
        $corps['targetKind'] = SubjectKind::Personne->value;
        $corps['targetId'] = $completes[0]['subjectId'];

        return [$corps, $changements];
    }

    /**
     * @param list<array{seq: int, opKind: string, subjectKind: string, subjectId: int, field: string, valueKind: string, oldValue: mixed, newValue: mixed, baseVersion: int}> $ops
     * @param array<int, EtatCible> $cibles
     *
     * @return list<array{seq: int, opKind: string, subjectKind: string, subjectId: int, field: string, valueKind: string, oldValue: mixed, newValue: mixed, baseVersion: int}>
     */
    private function avecLeDecesSiUneDateLAtteste(array $ops, array $cibles): array
    {
        $ajouts = [];

        foreach ($ops as $op) {
            if ('death' !== $op['field'] || !self::porteUneDate($op['newValue'])) {
                continue;
            }

            $sujet = $op['subjectId'];
            $cible = $cibles[$sujet] ?? null;

            if (null === $cible || true === (bool) ($cible->valeurs['deceased'] ?? false)) {
                continue;
            }

            foreach ($ops as $autre) {
                if ('deceased' === $autre['field'] && $autre['subjectId'] === $sujet) {
                    continue 2;
                }
            }

            $ajouts[] = $this->op($sujet, 'deceased', ValueKind::Booleen, false, true, $cible->version);
        }

        return [...$ops, ...$ajouts];
    }

    /**
     * @return array{seq: int, opKind: string, subjectKind: string, subjectId: int, field: string, valueKind: string, oldValue: mixed, newValue: mixed, baseVersion: int}
     */
    private function op(int $sujet, string $champ, ValueKind $nature, mixed $ancienne, mixed $nouvelle, int $version): array
    {
        return [
            'seq' => 0,
            'opKind' => OpKind::Champ->value,
            'subjectKind' => SubjectKind::Personne->value,
            'subjectId' => $sujet,
            'field' => $champ,
            'valueKind' => $nature->value,
            'oldValue' => $ancienne,
            'newValue' => $nouvelle,
            'baseVersion' => $version,
        ];
    }

    private function enClair(string $champ, ValueKind $nature, mixed $valeur): string
    {
        if ('sex' === $champ && \is_string($valeur) && null !== ($sexe = Sex::tryFrom($valeur))) {
            return $sexe->label();
        }

        return $this->normaliseur->enUneLigne($nature, $valeur);
    }

    /** Le critère de `ck_tree_person_deces_coherent` : `raw`, `min` ou `max` renseigné. */
    private static function porteUneDate(mixed $bloc): bool
    {
        if (!\is_array($bloc)) {
            return false;
        }

        foreach (['raw', 'min', 'max'] as $cle) {
            if (null !== ($bloc[$cle] ?? null) && '' !== $bloc[$cle]) {
                return true;
            }
        }

        return false;
    }

    /**
     * Le message du constructeur, **celui du champ** plutôt que l'intitulé générique :
     * « Les données envoyées sont invalides » n'apprend rien au membre, « Une date de
     * décès implique le décès » lui dit quoi corriger.
     */
    private static function expliquer(ErreurDeDemande $e): string
    {
        foreach ($e->champs() ?? [] as $message) {
            if (\is_string($message) && '' !== $message) {
                return $message;
            }
        }

        return $e->getMessage();
    }
}
