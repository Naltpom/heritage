<?php

declare(strict_types=1);

namespace App\Module\Proposal\Service;

use App\Module\Core\Enum\SubjectKind;
use App\Module\Proposal\Entity\ChangeRequest;
use App\Module\Proposal\Entity\DraftPerson;
use App\Module\Proposal\Entity\Revision;
use App\Module\Proposal\Enum\RequestStatus;
use App\Module\Proposal\Repository\ChangeRequestOpRepository;
use App\Module\Proposal\Repository\ChangeRequestRepository;
use App\Module\Proposal\Repository\DraftLinkRepository;
use App\Module\Proposal\Repository\DraftPersonRepository;
use App\Module\Proposal\Repository\RevisionRepository;
use App\Module\Proposal\Service\Port\PortMedias;

/**
 * La forme exacte des charges utiles du § 6 du contrat d'API.
 *
 * ── La règle de langue, mécanique et sans interprétation (§ 1.2) ─────────────────
 *
 * > **Une clé qui correspond à une colonne porte le nom de la propriété Doctrine**
 * > (`surname`, `birth`, `submittedAt`). **Toute autre clé est en français**
 * > (`erreur`, `elements`, `liens`, `brouillons`, `resultat`, `enAttente`).
 *
 * Ce n'est pas un compromis mais une contrainte : le calque désigne les champs modifiés
 * par leur nom (`enAttente["surname"]`). Si la fiche exposait `nom` pendant que l'op dit
 * `surname`, il faudrait une table de correspondance entre deux jeux de noms —
 * exactement « l'endroit où les champs se perdent en silence quand on en ajoute un ».
 * Avec la règle, **la clé du calque est littéralement la clé du corps**, et le front
 * pose sa pastille sans aucune logique de traduction.
 *
 * ── L'identifiant de nœud d'un brouillon ─────────────────────────────────────────
 *
 * `noeudId` vaut `#<demandeId>/<localRef sans dièse>`, et **l'API le fabrique ici** :
 * le front ne le concatène jamais. `local_ref` n'est unique que **dans sa demande** —
 * `#p1` de la demande 12 et `#p1` de la demande 13 n'ont aucun rapport —, or un auteur
 * peut avoir deux demandes en attente et un administrateur les a toutes. Le back, qui
 * lit une demande à la fois, ne voit pas la collision ; le front, qui reçoit une liste
 * plate, la subit (§ 17.3, divergence 7).
 */
final readonly class SerialiseurDeDemande
{
    public function __construct(
        private ChangeRequestOpRepository $ops,
        private ChangeRequestRepository $demandes,
        private DraftPersonRepository $brouillons,
        private DraftLinkRepository $liens,
        private RevisionRepository $revisions,
        private NormaliseurDeValeur $normaliseur,
        private AnalyseDeDemande $analyse,
        private PortMedias $medias,
        private ValeursMasquees $masques,
        private RattachementDesFiliations $filiations,
    ) {
    }

    /**
     * Une demande vue par son auteur (§ 6.5).
     *
     * @return array<string, mixed>
     */
    public function pourAuteur(ChangeRequest $demande): array
    {
        return [
            'id' => $demande->getId(),
            'kind' => $demande->getKind()->value,
            'status' => $demande->getStatus()->value,
            'authorUserId' => $demande->getAuthorUserId(),
            'authorLabel' => $demande->getAuthorLabel(),
            'targetKind' => $demande->getTargetKind()?->value,
            'targetId' => $demande->getTargetId(),
            // Le libellé stocké est le vrai `display_name` : une demande de l'assistant
            // peut viser un vivant hors du cercle de son auteur, qui n'en lit que le masque.
            'targetLabel' => $this->masques->libelle($demande->getTargetKind(), $demande->getTargetId(), $demande->getTargetLabel()),
            'message' => $demande->getMessage(),
            'decisionMessage' => $demande->getDecisionMessage(),
            'decidedByLabel' => $demande->getDecidedByLabel(),
            'decidedAt' => $this->horodatage($demande->getDecidedAt()),
            'autoValidated' => $demande->isAutoValidated(),
            'duplicateOfId' => $demande->getDuplicateOf()?->getId(),
            'submittedAt' => $this->horodatage($demande->getSubmittedAt()),
            'createdAt' => $this->horodatage($demande->getCreatedAt()),
            'ops' => $this->opsSimples($demande),
            'brouillons' => $this->brouillons($demande),
            'liens' => $this->liens($demande),
            'revisions' => array_map($this->revision(...), $this->revisions->pourDemande($demande)),
        ];
    }

    /**
     * La ligne d'une demande dans une liste — assez pour afficher la file sans ouvrir
     * chaque demande, et rien de plus.
     *
     * @return array<string, mixed>
     */
    public function resume(ChangeRequest $demande): array
    {
        return $this->resumes([$demande])[0];
    }

    /**
     * Les lignes d'une page de liste, le nom des personnes proposées chargé **en une
     * requête** pour toute la page (2026-10-04) : la file d'administration affiche
     * « Ajout de Jeanne Dupont, Paul Dupont » sans ouvrir chaque demande.
     *
     * @param list<ChangeRequest> $demandes
     *
     * @return list<array<string, mixed>>
     */
    public function resumes(array $demandes): array
    {
        $noms = $this->brouillons->nomsParDemande(array_values(array_filter(array_map(
            static fn (ChangeRequest $d): ?int => $d->getId(),
            $demandes,
        ))));

        return array_map(fn (ChangeRequest $d): array => $this->ligne($d, $noms[$d->getId() ?? 0] ?? []), $demandes);
    }

    /**
     * @param list<string> $nouvellesPersonnes
     *
     * @return array<string, mixed>
     */
    private function ligne(ChangeRequest $demande, array $nouvellesPersonnes): array
    {
        return [
            'id' => $demande->getId(),
            'kind' => $demande->getKind()->value,
            'status' => $demande->getStatus()->value,
            'authorUserId' => $demande->getAuthorUserId(),
            'authorLabel' => $demande->getAuthorLabel(),
            'targetKind' => $demande->getTargetKind()?->value,
            'targetId' => $demande->getTargetId(),
            // Le libellé stocké est le vrai `display_name` : une demande de l'assistant
            // peut viser un vivant hors du cercle de son auteur, qui n'en lit que le masque.
            'targetLabel' => $this->masques->libelle($demande->getTargetKind(), $demande->getTargetId(), $demande->getTargetLabel()),
            'message' => $demande->getMessage(),
            'autoValidated' => $demande->isAutoValidated(),
            'submittedAt' => $this->horodatage($demande->getSubmittedAt()),
            'createdAt' => $this->horodatage($demande->getCreatedAt()),
            // Les personnes que la demande ajoute à l'arbre, par leur nom tel que saisi
            // par l'auteur — pas une fiche de l'arbre, rien à masquer.
            'nouvellesPersonnes' => $nouvellesPersonnes,
        ];
    }

    /**
     * La vue d'administration (§ 6.7) : le § 6.5, plus **tout ce qu'il faut pour
     * décider**.
     *
     * Trois blocs que le modèle supposait sans les nommer, et sans lesquels l'écran
     * d'administration ne peut pas exister : `valeurCourante`/`aBouge` par op,
     * `cible.aChangeDepuisSoumission`, et surtout **`resolutionPrevue`**. Sans le
     * dernier, « l'administrateur découvre la caducité en cliquant, ce qui la rend
     * indistinguable d'une panne ».
     *
     * @return array<string, mixed>
     */
    public function pourAdministration(ChangeRequest $demande): array
    {
        $plan = $this->analyse->analyser($demande);
        $charge = $this->pourAuteur($demande);

        $charge['ops'] = array_map(
            fn (EtatDOp $etat): array => $this->opAvecEtat($etat),
            $plan->ops,
        );

        $cible = $this->cibleDuPlan($demande, $plan);
        if (null !== $cible) {
            $charge['cible'] = $cible;
        }

        // **Où chaque filiation atterrira**, dit avant le clic. Une filiation à un parent
        // ne va plus forcément là où le lien se lit : elle peut compléter la famille que
        // l'enfant a déjà, ou rejoindre l'union unique de son parent. L'administrateur
        // doit lire « sera rattachée au couple Jean-Marie × Hélène » ici, pas le découvrir
        // dans l'arbre. Clé absente quand rien ne se prévoit (parent pas encore créé).
        $annonces = $this->filiations->annonces($demande);

        if ([] !== $annonces && \is_array($charge['liens'])) {
            foreach ($charge['liens'] as $i => $lien) {
                $seq = \is_array($lien) ? ($lien['seq'] ?? null) : null;

                if (\is_int($seq) && isset($annonces[$seq]) && \is_array($lien)) {
                    $lien['filiationPrevue'] = $annonces[$seq];
                    $charge['liens'][$i] = $lien;
                }
            }
        }

        $charge['resolutionPrevue'] = $plan->resolution->value;
        // **Toujours un tableau**, vide quand la demande ne s'appuie sur personne : un
        // écran qui doit tester la présence de la clé avant son contenu finira par
        // écrire le second test sans le premier (§ 13.1, même raisonnement que le
        // calque). Les dépendances résolues y figurent aussi — « s'appuie sur la
        // demande n° 12, validée » est un contexte utile à qui décide.
        $charge['dependances'] = $plan->dependances;
        $charge['demandesConcurrentes'] = array_map(
            $this->demandeConcurrente(...),
            $this->demandes->concurrentesDe($demande),
        );
        $charge['doublonsProbables'] = array_map(
            $this->doublonProbable(...),
            $this->demandes->doublonsProbablesDe($demande),
        );

        return $charge;
    }

    /**
     * Le détail d'un nœud en attente (§ 6.6).
     *
     * Sans cette route, l'auteur voit son propre nœud dans le graphe et **ne peut pas
     * l'ouvrir** : `/api/arbre/personnes/{id}` exige `\d+` et la personne n'a pas encore
     * d'identifiant. « Une création qu'on ne voit pas dans l'arbre n'est pas une
     * contribution », et un nœud qu'on ne peut pas ouvrir n'en est pas une non plus.
     *
     * @return array<string, mixed>
     */
    public function brouillon(DraftPerson $brouillon): array
    {
        $demande = $brouillon->getRequest();
        $demandeId = $demande->getId() ?? 0;

        return [
            'demandeId' => $demandeId,
            'localRef' => $brouillon->getLocalRef(),
            'noeudId' => $this->noeudId($demandeId, $brouillon->getLocalRef()),
            'status' => $demande->getStatus()->value,
            'enAttente' => true,
            'authorLabel' => $demande->getAuthorLabel(),
            'givenNames' => $brouillon->getGivenNames(),
            'surname' => $brouillon->getSurname(),
            'marriedName' => $brouillon->getMarriedName(),
            'sex' => $brouillon->getSex()->value,
            'deceased' => $brouillon->isDeceased(),
            // `vivant` **est** envoyé, y compris sur une fiche restreinte : c'est le
            // « masquage est son propre indice » accepté par l'ADR 0003, et `.fiche.prive`
            // en dépend (point 4 du § 18).
            'vivant' => !$brouillon->isDeceased(),
            'birth' => $this->normaliseur->dateAvecTexte($brouillon->getBirth()),
            'death' => $this->normaliseur->dateAvecTexte($brouillon->getDeath()),
            'ligneDates' => $this->normaliseur->ligneDates($brouillon->getBirth(), $brouillon->getDeath()),
            'note' => $brouillon->getNote(),
            'liens' => $this->liensDuBrouillon($demande),
            'medias' => $this->medias->mediasDeBrouillon($demandeId, $brouillon->getLocalRef()),
            // Après validation, il porte l'identifiant réel et le front redirige vers
            // `/api/arbre/personnes/{id}` — c'est la raison d'être de cette colonne.
            'resolvedPersonId' => $brouillon->getResolvedPersonId(),
        ];
    }

    /**
     * Une fiche proposée telle qu'une **recherche** la rend (§ 6.6 bis).
     *
     * Volontairement **maigre** : de quoi nommer la personne dans une liste de choix et
     * la désigner ensuite, rien de plus. Pas de liens, pas de médias, pas de note — le
     * détail se lit sur `GET /api/demandes/{id}/brouillons/{ref}`, et une recherche qui
     * transporterait tout cela ferait dix jointures pour un bouton « Choisir ».
     *
     * `draftPersonId` est **l'identifiant de la ligne** `prp_draft_person`, et c'est la
     * seule chose qu'un client puisse reposter pour désigner cette fiche : `localRef` ne
     * vaut que dans sa demande. Les deux sont rendus quand même, parce que l'écran
     * affiche « proposée dans la demande n° 12 » et que le nœud du graphe se nomme
     * `#12/p1`.
     *
     * @return array<string, mixed>
     */
    public function resultatDeRecherche(DraftPerson $brouillon): array
    {
        $demande = $brouillon->getRequest();
        $demandeId = $demande->getId() ?? 0;

        return [
            'draftPersonId' => $brouillon->getId(),
            'demandeId' => $demandeId,
            'localRef' => $brouillon->getLocalRef(),
            'noeudId' => $this->noeudId($demandeId, $brouillon->getLocalRef()),
            'displayName' => $brouillon->nomPropose(),
            'sex' => $brouillon->getSex()->value,
            'deceased' => $brouillon->isDeceased(),
            'ligneDates' => $this->normaliseur->ligneDates($brouillon->getBirth(), $brouillon->getDeath()),
            'authorLabel' => $demande->getAuthorLabel(),
            // `false` pour un brouillon que son auteur n'a pas encore envoyé : le front le
            // dit à côté du nom, parce qu'une demande qui s'y appuie attendra qu'il le
            // soit. Il pose sa pastille sur ce que la charge dit, pas sur ce qu'il déduit
            // de la route qu'il a appelée.
            'enAttente' => RequestStatus::Brouillon !== $demande->getStatus(),
        ];
    }

    /**
     * Une révision de l'historique d'une fiche (§ 6.11).
     *
     * `changes` est rendu **formaté**, pas en brut : c'est un historique lisible, pas un
     * vidage. `requestId` à `null` signale une demande effacée hors de l'application,
     * jamais un raccourci d'écriture — il n'existe aucun chemin d'écriture applicatif qui
     * contourne le mécanisme, l'édition directe d'un administrateur créant elle aussi une
     * demande auto-validée (ADR 0004).
     *
     * @return array<string, mixed>
     */
    public function revision(Revision $revision): array
    {
        $kind = $revision->getSubjectKind();
        $changes = $revision->getChanges();

        // Une révision **sur un vivant hors cercle** ne se lit que dans la vue auteur d'une
        // demande de l'assistant (l'historique d'une telle fiche est un `404`, § 6.11). Son
        // nom y vaut le masque, et chaque valeur `avant` en est retirée — c'est l'`oldValue`
        // que {@see self::opsSimples()} omet déjà, et qu'une révision recopie.
        if ($this->masques->masquee($kind, $revision->getSubjectId())) {
            $changes = self::sansValeursDAvant($changes);
        }

        // ADR 0016 : la naissance d'un vivant que le lecteur ne voit pas entière ne
        // ressort pas par l'historique — ni avant, ni après.
        if ($this->masques->naissanceMasquee($kind, $revision->getSubjectId())) {
            $changes = self::sansNaissance($changes);
        }

        return [
            'id' => $revision->getId(),
            'operation' => $revision->getOperation()->value,
            'subjectKind' => $kind->value,
            'subjectId' => $revision->getSubjectId(),
            'subjectLabel' => $this->masques->libelle($kind, $revision->getSubjectId(), $revision->getSubjectLabel()),
            'changes' => $changes,
            'versionBefore' => $revision->getVersionBefore(),
            'versionAfter' => $revision->getVersionAfter(),
            'actorLabel' => $revision->getActorLabel(),
            'requestId' => $revision->getRequest()?->getId(),
            'appliedAt' => $this->horodatage($revision->getAppliedAt()),
        ];
    }

    /**
     * L'enveloppe de liste unique du § 1.5, pour **toutes** les listes paginées.
     *
     * @param list<array<string, mixed>> $elements
     *
     * @return array{elements: list<array<string, mixed>>, total: int, page: int, parPage: int}
     */
    public function enveloppe(array $elements, int $total, int $page, int $parPage): array
    {
        return [
            'elements' => $elements,
            // `total` est le nombre d'éléments **après** filtrage, jamais avant : sans
            // cela, un lecteur déduit du compte ce que la liste lui cache.
            'total' => $total,
            'page' => $page,
            'parPage' => $parPage,
        ];
    }

    public function noeudId(int $demandeId, string $localRef): string
    {
        return '#'.$demandeId.'/'.ltrim($localRef, '#');
    }

    /** @return list<array<string, mixed>> */
    private function opsSimples(ChangeRequest $demande): array
    {
        $rendus = [];

        foreach ($this->ops->pourDemande($demande) as $op) {
            $rendu = [
                'seq' => $op->getSeq(),
                'opKind' => $op->getOpKind()->value,
                'subjectKind' => $op->getSubjectKind()->value,
                'subjectId' => $op->getSubjectId(),
                'subjectDraftRef' => $op->getSubjectDraftRef(),
                'field' => $op->getField(),
                'valueKind' => $op->getValueKind()->value,
                'oldValue' => $this->normaliseur->pourAffichage($op->getValueKind(), $op->getOldValue()),
                'newValue' => $this->normaliseur->pourAffichage($op->getValueKind(), $op->getNewValue()),
                'baseVersion' => $op->getBaseVersion(),
            ];

            // Une demande déposée par l'assistant peut viser un vivant hors du cercle de
            // son auteur : la valeur d'avant, lue en base, ne lui est pas rendue. Clés
            // **omises**, jamais mises à `null` — `null` est une valeur de champ, et
            // l'écran la lirait « vide » (§ 1.3). La vue d'administration, qui remplace
            // ces ops par `opAvecEtat()`, n'est pas concernée : l'administrateur voit tout.
            if ($this->masques->masquee($op->getSubjectKind(), $op->getSubjectId())) {
                unset($rendu['oldValue'], $rendu['baseVersion']);
            }

            // ADR 0016 : la valeur d'avant d'une naissance que l'auteur ne voit pas entière.
            if ('birth' === $op->getField() && $this->masques->naissanceMasquee($op->getSubjectKind(), $op->getSubjectId())) {
                unset($rendu['oldValue']);
            }

            $rendus[] = $rendu;
        }

        return $rendus;
    }

    /** @return array<string, mixed> */
    private function opAvecEtat(EtatDOp $etat): array
    {
        $op = $etat->op;

        return [
            'seq' => $op->getSeq(),
            'opKind' => $op->getOpKind()->value,
            'subjectKind' => $op->getSubjectKind()->value,
            'subjectId' => $op->getSubjectId(),
            'subjectDraftRef' => $op->getSubjectDraftRef(),
            'field' => $op->getField(),
            'valueKind' => $op->getValueKind()->value,
            'oldValue' => $this->normaliseur->pourAffichage($op->getValueKind(), $op->getOldValue()),
            'newValue' => $this->normaliseur->pourAffichage($op->getValueKind(), $op->getNewValue()),
            'baseVersion' => $op->getBaseVersion(),
            // Calculées à la lecture, jamais stockées : elles dépendent de l'état
            // courant de l'arbre, qui bouge sans que la demande bouge.
            'valeurCourante' => $this->normaliseur->pourAffichage($op->getValueKind(), $etat->valeurCourante),
            'aBouge' => $etat->aBouge,
            'libelleDiff' => $etat->libelleDiff,
        ];
    }

    /** @return array{version: int, versionALaSoumission: int|null, aChangeDepuisSoumission: bool}|null */
    private function cibleDuPlan(ChangeRequest $demande, PlanDeValidation $plan): ?array
    {
        $kind = $demande->getTargetKind();
        $id = $demande->getTargetId();

        if (null === $kind || null === $id) {
            return null;
        }

        $cible = $plan->cibles[$kind->value.':'.$id] ?? null;

        if (null === $cible) {
            return null;
        }

        return [
            'version' => $cible->version,
            'versionALaSoumission' => $plan->versionALaSoumission(),
            // Ce booléen **allume le bandeau** « cette fiche a changé depuis la
            // demande ». Il ne dit pas que la demande est morte : `resolutionPrevue` le
            // dit, et les deux ne se déduisent pas l'un de l'autre.
            'aChangeDepuisSoumission' => $plan->uneVersionABouge(),
        ];
    }

    /** @return array<string, mixed> */
    private function demandeConcurrente(ChangeRequest $autre): array
    {
        $champs = [];

        foreach ($this->ops->pourDemande($autre) as $op) {
            $champ = $op->getField();

            if (null !== $champ) {
                $champs[] = $champ;
            }
        }

        return [
            'id' => $autre->getId(),
            'authorLabel' => $autre->getAuthorLabel(),
            'kind' => $autre->getKind()->value,
            'submittedAt' => $this->horodatage($autre->getSubmittedAt()),
            'champs' => $champs,
        ];
    }

    /**
     * Un doublon probable ne porte **aucun `personId`**, puisque la personne n'existe
     * pas : c'est un rapprochement par `dedup_key` (nom + année de naissance), pas une
     * identification.
     *
     * @return array<string, mixed>
     */
    private function doublonProbable(ChangeRequest $autre): array
    {
        return [
            'id' => $autre->getId(),
            'authorLabel' => $autre->getAuthorLabel(),
            'libelle' => $autre->getTargetLabel() ?? $autre->getDedupKey(),
            'dedupKey' => $autre->getDedupKey(),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function brouillons(ChangeRequest $demande): array
    {
        $demandeId = $demande->getId() ?? 0;
        $rendus = [];

        foreach ($this->brouillons->pourDemande($demande) as $brouillon) {
            $rendus[] = [
                'localRef' => $brouillon->getLocalRef(),
                'noeudId' => $this->noeudId($demandeId, $brouillon->getLocalRef()),
                'givenNames' => $brouillon->getGivenNames(),
                'surname' => $brouillon->getSurname(),
                'marriedName' => $brouillon->getMarriedName(),
                'nickname' => $brouillon->getNickname(),
                'sex' => $brouillon->getSex()->value,
                'deceased' => $brouillon->isDeceased(),
                'birth' => $this->normaliseur->dateAvecTexte($brouillon->getBirth()),
                'death' => $this->normaliseur->dateAvecTexte($brouillon->getDeath()),
                // La justification écrite fiche par fiche — une homonymie père/fils
                // signalée, une déduction marquée comme supposition. Elle est stockée
                // depuis toujours et n'était **rendue nulle part** : l'administrateur
                // décidait sur le seul `message` global de la demande, et les notes de
                // détail, qui portent précisément ce sur quoi il doit trancher, ne
                // quittaient pas la base.
                'note' => $brouillon->getNote(),
                'resolvedPersonId' => $brouillon->getResolvedPersonId(),
            ];
        }

        return $rendus;
    }

    /**
     * Les liens **dont cette fiche proposée est le sujet**, sous la forme complète du
     * § 6.5 — pour le rapprochement des doublons (§ 6.3 ter), qui compare la parenté
     * saisie à celle de la demande existante.
     *
     * @return list<array<string, mixed>>
     */
    public function liensDuSujet(DraftPerson $brouillon): array
    {
        return array_values(array_filter(
            $this->liens($brouillon->getRequest()),
            static fn (array $lien): bool => $lien['subjectDraftRef'] === $brouillon->getLocalRef(),
        ));
    }

    /** @return list<array<string, mixed>> */
    private function liens(ChangeRequest $demande): array
    {
        $noms = $this->nomsDesBrouillons($demande);
        $rendus = [];

        foreach ($this->liens->pourDemande($demande) as $lien) {
            $rendus[] = [
                'seq' => $lien->getSeq(),
                'kind' => $lien->getKind()->value,
                'subjectPersonId' => $lien->getSubjectPersonId(),
                'subjectDraftRef' => $lien->getSubjectDraftRef(),
                'subjectLabel' => $this->nommerLExtremite($lien->getSubjectDraftRef(), $lien->getSubjectLabel(), $noms, $lien->getSubjectDraftPersonId(), $lien->getSubjectPersonId()),
                'subjectDraftPersonId' => $lien->getSubjectDraftPersonId(),
                'otherPersonId' => $lien->getOtherPersonId(),
                'otherDraftRef' => $lien->getOtherDraftRef(),
                'otherLabel' => $this->nommerLExtremite($lien->getOtherDraftRef(), $lien->getOtherLabel(), $noms, $lien->getOtherDraftPersonId(), $lien->getOtherPersonId()),
                // La fiche proposée par une **autre** demande. Sans cette clé, elle se
                // lisait comme une personne de l'arbre dont l'identifiant manquait — et
                // le front n'avait aucun moyen de dire « proposée par la demande n° 12 ».
                'otherDraftPersonId' => $lien->getOtherDraftPersonId(),
                'other2PersonId' => $lien->getOther2PersonId(),
                // Sans cette clé, le second parent d'une filiation n'était lisible que
                // par `other2Label` : rien ne disait s'il désignait une personne de
                // l'arbre ou une autre fiche de la même demande.
                'other2DraftRef' => $lien->getOther2DraftRef(),
                'other2DraftPersonId' => $lien->getOther2DraftPersonId(),
                'other2Label' => $this->nommerLExtremite($lien->getOther2DraftRef(), $lien->getOther2Label(), $noms, $lien->getOther2DraftPersonId(), $lien->getOther2PersonId()),
                'familyId' => $lien->getFamilyId(),
                // Le couple d'ancrage est stocké avec son libellé depuis
                // `designerCoupleExistant()`. Ne rendre que `familyId` affichait un
                // entier nu — « rattaché à la famille 2 » — là où la base porte le nom
                // des deux époux.
                // Et le libellé est fait du nom des deux époux : un époux vivant hors du
                // cercle du lecteur y prend le masque (révision du 2026-10-01).
                'familyLabel' => null === $lien->getFamilyId()
                    ? $lien->getFamilyLabel()
                    : $this->masques->libelleDeFamille($lien->getFamilyId(), $lien->getFamilyLabel()),
                'marriage' => $this->normaliseur->dateAvecTexte($lien->getMarriage()),
                'divorced' => $lien->isDivorced(),
                'divorce' => $lien->getDivorce()->isKnown()
                    ? $this->normaliseur->dateAvecTexte($lien->getDivorce())
                    : null,
                'siblingOrder' => $lien->getSiblingOrder(),
                'note' => $lien->getNote(),
                'resolvedFamilyId' => $lien->getResolvedFamilyId(),
            ];
        }

        return $rendus;
    }

    /**
     * Les liens tels que le détail d'un nœud en attente les montre (§ 6.6).
     *
     * L'index des noms est bâti **une fois pour la demande** et non par lien : le rendu
     * en fait autant qu'il y a de liens, et une branche de vingt-deux personnes en
     * compte une trentaine.
     *
     * @return list<array<string, mixed>>
     */
    private function liensDuBrouillon(ChangeRequest $demande): array
    {
        $noms = $this->nomsDesBrouillons($demande);
        $rendus = [];

        foreach ($this->liens->pourDemande($demande) as $lien) {
            $rendus[] = [
                'seq' => $lien->getSeq(),
                'kind' => $lien->getKind()->value,
                'otherPersonId' => $lien->getOtherPersonId(),
                'otherDraftRef' => $lien->getOtherDraftRef(),
                'otherDraftPersonId' => $lien->getOtherDraftPersonId(),
                'otherLabel' => $this->nommerLExtremite($lien->getOtherDraftRef(), $lien->getOtherLabel(), $noms, $lien->getOtherDraftPersonId(), $lien->getOtherPersonId()),
                'note' => $lien->getNote(),
            ];
        }

        return $rendus;
    }

    /**
     * Le nom de chaque fiche proposée de la demande, **suffixé de sa condition**, indexé
     * par référence provisoire.
     *
     * ── Pourquoi relire plutôt que se fier au libellé stocké ────────────────────────
     *
     * `subject_label` et consorts sont dénormalisés au dépôt, et
     * {@see ConstructeurDeCreation} y écrit désormais le nom. Mais les liens **déjà en
     * base** portent la forme d'avant — `'Personne proposée #p1'` —, et c'est
     * exactement le cas qui a été mesuré : vingt-deux fiches validées sans que leur
     * parenté ait été lisible. Un correctif qui ne vaudrait que pour les demandes à
     * venir ne réparerait pas l'écran sur lequel le défaut a été constaté.
     *
     * Relire la fiche à chaque rendu a un second mérite, celui-là permanent : un libellé
     * dénormalisé est une photographie, et « il n'est jamais une source de vérité »
     * (ADR 0002, compensation 3). Ici la source est à un `getRepository` de distance et
     * ne peut pas dater.
     *
     * ── Pourquoi la mention « (proposée) » est ajoutée ici et pas stockée ───────────
     *
     * Un lecteur qui ne regarde pas `subjectDraftRef` doit pouvoir distinguer, dans une
     * liste de liens, la personne de l'arbre de celle que la demande invente. Mais la
     * mention serait fausse en base : {@see ApplicateurDeCreation::libelleDuLien()}
     * recopie ces colonnes dans `prp_revision.subject_label` **au moment même où la
     * personne cesse d'être proposée**, et l'historique dirait « (proposée) » d'une
     * fiche validée. Elle appartient donc au rendu, jamais à la colonne.
     *
     * @return array<string, string>
     */
    private function nomsDesBrouillons(ChangeRequest $demande): array
    {
        $noms = [];

        foreach ($this->brouillons->pourDemande($demande) as $brouillon) {
            $noms[$brouillon->getLocalRef()] = $brouillon->nomPropose().' (proposée)';
        }

        return $noms;
    }

    /**
     * Le nom d'une extrémité de lien : celui de la fiche proposée si elle en est une, le
     * libellé stocké sinon.
     *
     * `$stocke` est le repli et non l'inverse : une extrémité qui désigne une personne
     * de l'arbre n'a pas de `draftRef`, et son libellé est filtré comme la personne
     * (§ 0.4) — le relire ici court-circuiterait ce filtre.
     *
     * ── Une personne de l'arbre hors du cercle ne se nomme pas ──────────────────────
     *
     * Le libellé stocké d'une extrémité `personId` est son vrai `display_name`. Depuis la
     * révision du 2026-10-01 de l'ADR 0003, un vivant hors du cercle du lecteur n'expose
     * plus que le masque — et une demande de l'assistant peut en citer un : le libellé
     * passe donc par {@see ValeursMasquees::libelle()}.
     *
     * ── Une fiche proposée **ailleurs** se relit aussi ─────────────────────────────
     *
     * Et pour la même raison : le libellé stocké est une photographie du dépôt, or la
     * fiche citée vit dans une demande que son auteur peut avoir modifiée depuis. La
     * relire coûte un `find` par extrémité citée — quelques-uns par demande — et évite
     * qu'un écran de décision affiche un nom que plus personne ne propose.
     *
     * @param array<string, string> $noms
     */
    private function nommerLExtremite(
        ?string $draftRef,
        ?string $stocke,
        array $noms,
        ?int $draftPersonId = null,
        ?int $personId = null,
    ): ?string {
        if (null !== $personId && null === $draftRef && null === $draftPersonId) {
            return $this->masques->libelle(SubjectKind::Personne, $personId, $stocke);
        }

        if (null !== $draftRef) {
            return $noms[$draftRef] ?? $stocke;
        }

        if (null === $draftPersonId) {
            return $stocke;
        }

        $brouillon = $this->brouillons->find($draftPersonId);

        return null === $brouillon ? $stocke : $brouillon->nomPropose().' (proposée)';
    }

    /**
     * Les `changes` d'une révision, privés des champs de naissance (ADR 0016).
     *
     * @param array<string, mixed> $changes
     *
     * @return array<string, mixed>
     */
    private static function sansNaissance(array $changes): array
    {
        foreach (array_keys($changes) as $champ) {
            if (str_starts_with((string) $champ, 'birth')) {
                unset($changes[$champ]);
            }
        }

        return $changes;
    }

    /**
     * Les `changes` d'une révision, privés de chaque valeur `avant`.
     *
     * Forme du § 6.11 : `{ champ: { avant, apres } }`. Clé **omise**, jamais mise à
     * `null` — `null` est une valeur de champ, et l'écran la lirait « vide » (§ 1.3).
     *
     * @param array<string, mixed> $changes
     *
     * @return array<string, mixed>
     */
    private static function sansValeursDAvant(array $changes): array
    {
        foreach ($changes as $champ => $changement) {
            if (\is_array($changement)) {
                unset($changement['avant']);
                $changes[$champ] = $changement;
            }
        }

        return $changes;
    }

    /**
     * « vers 1889 – 1972 » : composée à la sérialisation, **jamais stockée**, ce qui
     * garantit qu'elle ne peut pas afficher un point là où la base a un intervalle
     * (§ 9.1).
     */
    private function horodatage(?\DateTimeImmutable $moment): ?string
    {
        return $moment?->format(\DateTimeInterface::ATOM);
    }
}
