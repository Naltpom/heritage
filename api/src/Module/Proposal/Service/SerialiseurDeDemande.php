<?php

declare(strict_types=1);

namespace App\Module\Proposal\Service;

use App\Module\Proposal\Entity\ChangeRequest;
use App\Module\Proposal\Entity\DraftPerson;
use App\Module\Proposal\Entity\Revision;
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
            'targetLabel' => $demande->getTargetLabel(),
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
        return [
            'id' => $demande->getId(),
            'kind' => $demande->getKind()->value,
            'status' => $demande->getStatus()->value,
            'authorUserId' => $demande->getAuthorUserId(),
            'authorLabel' => $demande->getAuthorLabel(),
            'targetKind' => $demande->getTargetKind()?->value,
            'targetId' => $demande->getTargetId(),
            'targetLabel' => $demande->getTargetLabel(),
            'message' => $demande->getMessage(),
            'autoValidated' => $demande->isAutoValidated(),
            'submittedAt' => $this->horodatage($demande->getSubmittedAt()),
            'createdAt' => $this->horodatage($demande->getCreatedAt()),
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

        $charge['resolutionPrevue'] = $plan->resolution->value;
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
     * Une révision de l'historique d'une fiche (§ 6.11).
     *
     * `changes` est rendu **formaté**, pas en brut : c'est un historique lisible, pas un
     * vidage. `requestId` à `null` signale un **import GEDCOM**, jamais un raccourci
     * d'écriture — il n'existe aucun chemin d'écriture applicatif qui contourne le
     * mécanisme, l'édition directe d'un administrateur créant elle aussi une demande
     * auto-validée (ADR 0004).
     *
     * @return array<string, mixed>
     */
    public function revision(Revision $revision): array
    {
        return [
            'id' => $revision->getId(),
            'operation' => $revision->getOperation()->value,
            'subjectKind' => $revision->getSubjectKind()->value,
            'subjectId' => $revision->getSubjectId(),
            'subjectLabel' => $revision->getSubjectLabel(),
            'changes' => $revision->getChanges(),
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
            $rendus[] = [
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
                'subjectLabel' => $this->nommerLExtremite($lien->getSubjectDraftRef(), $lien->getSubjectLabel(), $noms),
                'otherPersonId' => $lien->getOtherPersonId(),
                'otherDraftRef' => $lien->getOtherDraftRef(),
                'otherLabel' => $this->nommerLExtremite($lien->getOtherDraftRef(), $lien->getOtherLabel(), $noms),
                'other2PersonId' => $lien->getOther2PersonId(),
                // Sans cette clé, le second parent d'une filiation n'était lisible que
                // par `other2Label` : rien ne disait s'il désignait une personne de
                // l'arbre ou une autre fiche de la même demande.
                'other2DraftRef' => $lien->getOther2DraftRef(),
                'other2Label' => $this->nommerLExtremite($lien->getOther2DraftRef(), $lien->getOther2Label(), $noms),
                'familyId' => $lien->getFamilyId(),
                // Le couple d'ancrage est stocké avec son libellé depuis
                // `designerCoupleExistant()`. Ne rendre que `familyId` affichait un
                // entier nu — « rattaché à la famille 2 » — là où la base porte le nom
                // des deux époux.
                'familyLabel' => $lien->getFamilyLabel(),
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
                'otherLabel' => $this->nommerLExtremite($lien->getOtherDraftRef(), $lien->getOtherLabel(), $noms),
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
     * @param array<string, string> $noms
     */
    private function nommerLExtremite(?string $draftRef, ?string $stocke, array $noms): ?string
    {
        if (null === $draftRef) {
            return $stocke;
        }

        return $noms[$draftRef] ?? $stocke;
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
