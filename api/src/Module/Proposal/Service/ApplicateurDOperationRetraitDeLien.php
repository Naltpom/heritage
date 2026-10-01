<?php

declare(strict_types=1);

namespace App\Module\Proposal\Service;

use App\Module\Core\Contract\Lecteur;
use App\Module\Core\Enum\SubjectKind;
use App\Module\Proposal\Contract\ApplicateurDOperation;
use App\Module\Proposal\Contract\DemandeEnCours;
use App\Module\Proposal\Contract\EcritureDeRevision;
use App\Module\Proposal\Contract\FiliationEtablie;
use App\Module\Proposal\Contract\FiliationIncompatible;
use App\Module\Proposal\Contract\LienARetirer;
use App\Module\Proposal\Contract\LienRetire;
use App\Module\Proposal\Contract\OpEnAttente;
use App\Module\Proposal\Contract\RetraitDeLien;
use App\Module\Proposal\Enum\OpKind;
use App\Module\Proposal\Enum\RevisionOperation;
use App\Module\Proposal\Exception\Conflit;
use App\Module\Proposal\Service\Port\PortArbre;

/**
 * ═══════════════════════════════════════════════════════════════════════════════
 * `retrait_lien` — l'application à la validation.
 *
 * Greffé par le tag de {@see ApplicateurDOperation}, écrivant par le seul port de l'arbre,
 * comme ses frères de la note, de la citation et de la suppression. Il **ne décide pas** :
 * le lien inexistant, le seul époux d'une union qui porte des données, le couple restant
 * qui en doublerait un autre ont été refusés au dépôt
 * ({@see ConstructeurDeRetraitDeLien}) puis remesurés au contrôle préalable
 * ({@see AnalyseDeDemande}), qui en fait une caducité. Ici, on écrit ou on annule tout.
 *
 * ## Les révisions, et pourquoi il y en a plusieurs par lien
 *
 * - une `lien_retire` sur **la famille** — la ligne écrite, sa version d'avant et
 *   d'après (`null` si l'union est supprimée) ;
 * - une `lien_retire` sur **chaque personne** que le lien reliait : l'enfant et ses
 *   parents, ou les deux époux. L'historique d'une fiche (§ 6.11) se lit par
 *   `(subject_kind, subject_id)` ; une révision posée sur la seule famille n'apparaîtrait
 *   sur aucune fiche, et « il n'est plus son enfant » est précisément ce qu'on vient
 *   chercher sur la fiche, six mois plus tard ;
 * - une `suppression` sur la famille quand elle est partie, comme quand une filiation la
 *   vide ({@see ApplicateurDeCreation}) ;
 * - quand un enfant ne perdait qu'un parent et a été rattaché à l'autre : la famille
 *   d'arrivée (créée ou complétée) et le lien ajouté, comme pour un ajout — c'est le même
 *   chemin, `etablirFiliation()`, donc le même invariant.
 *
 * Toutes portent la phrase de {@see RetraitDeLien::enUneLigne()} : celle que
 * l'administrateur a lue avant de cliquer.
 */
final readonly class ApplicateurDOperationRetraitDeLien implements ApplicateurDOperation
{
    public function __construct(
        private PortArbre $arbre,
        private MesureDesRetraits $mesures,
    ) {
    }

    public function natures(): array
    {
        return [OpKind::RetraitLien];
    }

    public function appliquer(DemandeEnCours $demande, array $ops, Lecteur $lecteur): array
    {
        $ecritures = [];
        $supprimees = [];

        foreach ($ops as $op) {
            $lien = $this->lienVise($op);

            if (isset($supprimees[$lien->familleId])) {
                // Un lien précédent de la même demande a vidé puis supprimé cette union :
                // celui-ci est défait avec elle. Lever ici annulerait une validation qui a
                // obtenu exactement ce qu'elle demandait.
                continue;
            }

            // Les époux sont lus **avant** d'écrire : une union supprimée n'en a plus, et
            // leurs fiches doivent pourtant dire que ce lien est parti.
            $reliees = $this->personnesReliees($lien);

            try {
                $retire = $this->arbre->retirerLien($lien, $this->mesures->pourValider($lien, $demande->id));
            } catch (FiliationIncompatible $incompatible) {
                // Rien n'est écrit : la transaction du validateur est annulée tout entière,
                // et la demande reste en attente — la même issue qu'un ajout de lien.
                throw Conflit::filiationIncompatible((int) $demande->id, $incompatible->getMessage());
            }

            if ($retire->retrait->familleSupprimee) {
                $supprimees[$lien->familleId] = true;
            }

            array_push($ecritures, ...$this->revisions($retire, $reliees));
        }

        return $ecritures;
    }

    private function lienVise(OpEnAttente $op): LienARetirer
    {
        if (SubjectKind::Famille->value !== $op->subjectKind || null === $op->subjectId) {
            throw new \LogicException(\sprintf('L\'op %d de nature « retrait_lien » doit viser une famille existante.', $op->seq));
        }

        if (!\is_array($op->oldValue)) {
            throw new \LogicException(\sprintf('L\'op %d de nature « retrait_lien » ne porte pas le lien qu\'elle retire.', $op->seq));
        }

        /** @var array<string, mixed> $mesure */
        $mesure = $op->oldValue;
        $lien = RetraitDeLien::depuisJson($mesure)->lien();

        if ($lien->familleId !== $op->subjectId) {
            throw new \LogicException(\sprintf('L\'op %d retire un lien de la famille %d mais vise la famille %d.', $op->seq, $lien->familleId, $op->subjectId));
        }

        return $lien;
    }

    /**
     * @param array<int, string> $reliees
     *
     * @return list<EcritureDeRevision>
     */
    private function revisions(LienRetire $retire, array $reliees): array
    {
        $retrait = $retire->retrait;
        $phrase = $retrait->enUneLigne();
        $changes = ['lien' => ['avant' => $phrase, 'apres' => null]];

        $ecritures = [new EcritureDeRevision(
            SubjectKind::Famille,
            $retrait->familleId,
            $retrait->familleLibelle,
            RevisionOperation::LienRetire,
            $changes,
            $retire->versionAvant,
            $retire->versionApres,
        )];

        foreach ($reliees as $personneId => $libelle) {
            $ecritures[] = new EcritureDeRevision(SubjectKind::Personne, $personneId, $libelle, RevisionOperation::LienRetire, $changes);
        }

        if ($retrait->familleSupprimee) {
            $ecritures[] = new EcritureDeRevision(
                SubjectKind::Famille,
                $retrait->familleId,
                $retrait->familleLibelle,
                RevisionOperation::Suppression,
                ['couple' => ['avant' => $retrait->familleLibelle, 'apres' => null]],
                $retire->versionAvant,
            );
        }

        if (null !== $retire->filiation) {
            array_push($ecritures, ...$this->rattachement($retire->filiation));
        }

        return $ecritures;
    }

    /**
     * Les fiches dont l'historique doit dire que ce lien est parti : la personne du lien
     * et les époux de l'union — l'enfant et ses parents, ou les deux conjoints.
     *
     * @return array<int, string>
     */
    private function personnesReliees(LienARetirer $lien): array
    {
        $personnes = [];

        foreach ([$lien->personneId, ...$this->arbre->epouxDeFamille($lien->familleId)] as $id) {
            $personnes[$id] ??= $this->arbre->chargerCible(SubjectKind::Personne, $id)->libelle ?? '';
        }

        return array_filter($personnes, static fn (string $libelle): bool => '' !== $libelle);
    }

    /** @return list<EcritureDeRevision> */
    private function rattachement(FiliationEtablie $filiation): array
    {
        $ecritures = [];

        if ($filiation->creee) {
            $ecritures[] = new EcritureDeRevision(
                SubjectKind::Famille,
                $filiation->familleId,
                $filiation->libelle,
                RevisionOperation::Creation,
                ['couple' => ['avant' => null, 'apres' => $filiation->libelle]],
            );
        }

        if (null !== $filiation->libelleAvant) {
            $ecritures[] = new EcritureDeRevision(
                SubjectKind::Famille,
                $filiation->familleId,
                $filiation->libelle,
                RevisionOperation::Modification,
                ['couple' => ['avant' => $filiation->libelleAvant, 'apres' => $filiation->libelle]],
            );
        }

        $ecritures[] = new EcritureDeRevision(
            SubjectKind::Famille,
            $filiation->familleId,
            $filiation->libelle,
            RevisionOperation::LienAjoute,
            [
                'filiation' => ['avant' => null, 'apres' => $filiation->libelleDeLEnfant],
                'rattachement' => ['avant' => null, 'apres' => RattachementDesFiliations::phrase($filiation->motif, $filiation->libelle)],
            ],
        );

        return $ecritures;
    }
}
