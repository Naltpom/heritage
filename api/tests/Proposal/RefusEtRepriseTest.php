<?php

declare(strict_types=1);

namespace App\Tests\Proposal;

use App\Module\Core\Contract\Lecteur;
use App\Module\Core\Enum\SubjectKind;
use App\Module\Proposal\Entity\ChangeRequest;
use App\Module\Proposal\Enum\RequestStatus;
use App\Module\Proposal\Exception\Conflit;
use App\Module\Proposal\Exception\DonneesInvalides;

/**
 * Refuser — **message obligatoire** — et refaire une demande morte.
 *
 * Les deux vont ensemble : un refus sans motif et une caducité sans reprise sont les
 * deux façons de perdre un contributeur, et « un membre dont la première contribution
 * se perd n'en dépose pas de seconde ».
 */
final class RefusEtRepriseTest extends BaseDeDemandes
{
    /**
     * **Le message est obligatoire au refus.** Absent, vide, ou fait d'espaces : `422`,
     * avec le champ nommé.
     *
     * La chaîne d'espaces n'est pas un détail : la contrainte en base emploie `btrim`,
     * et un service qui ne l'appliquerait pas rendrait une `500` là où une `422` était
     * due.
     */
    public function testUnRefusSansMessageEstRejete(): void
    {
        foreach ([null, '', '   ', "\n\t "] as $motif) {
            $demande = $this->deposer();

            try {
                $this->refus->refuser($demande, $this->admin(), $motif, null);
                self::fail('Un refus sans motif doit être rejeté.');
            } catch (DonneesInvalides $erreur) {
                self::assertSame(422, $erreur->statutHttp());
                self::assertSame(
                    ['message' => 'Le motif du refus est obligatoire.'],
                    $erreur->champs(),
                );
            }

            // La demande n'a pas bougé : un refus raté n'est pas un refus.
            self::assertSame(RequestStatus::EnAttente, $this->recharger($demande)->getStatus());
        }
    }

    /** Le motif parvient à l'auteur dans sa notification — c'est l'exigence du § 6.3 du dossier. */
    public function testLeMotifDuRefusParvientALAuteur(): void
    {
        $demande = $this->deposer();
        $motif = 'Cette union est déjà enregistrée sous la famille n° 28.';

        $this->refus->refuser($demande, $this->admin(), $motif, null);

        self::assertSame(RequestStatus::Refusee, $demande->getStatus());
        self::assertSame($motif, $demande->getDecisionMessage());
        self::assertSame('Natron Romarin', $demande->getDecidedByLabel());

        $refus = $this->notificateur->de('demande_refusee');
        self::assertCount(1, $refus);
        self::assertSame($motif, $refus[0]['message'], 'Le motif part tel quel, jamais résumé.');
    }

    /** Une demande refusée **reste consultable par son auteur, avec son motif**. */
    public function testUneDemandeRefuseeResteConsultableAvecSonMotif(): void
    {
        $demande = $this->deposer();
        $this->refus->refuser($demande, $this->admin(), 'La graphie de l\'acte est bien « Sureau ».', null);

        $charge = $this->serialiseur->pourAuteur($this->recharger($demande));

        self::assertSame('refusee', $charge['status']);
        self::assertSame('La graphie de l\'acte est bien « Sureau ».', $charge['decisionMessage']);
        self::assertNotNull($charge['decidedAt']);
    }

    /**
     * Refuser **en renvoyant à une autre demande** : l'auteur lit « votre proposition
     * rejoint la demande n° … » plutôt qu'un refus sec. C'est exactement à cela que sert
     * le motif obligatoire quand deux cousins proposent la même chose.
     */
    public function testUnRefusPeutRenvoyerAUneAutreDemande(): void
    {
        $premiere = $this->deposer(self::OPALE);
        $seconde = $this->depot->deposer(
            $this->corpsDeModification([$this->opTexte('nickname', null, 'Mimi')]),
            $this->lecteur(self::TUF),
        );

        $this->refus->refuser(
            $seconde,
            $this->admin(),
            'Votre proposition rejoint celle d\'Opale.',
            $premiere->getId(),
        );

        self::assertSame($premiere->getId(), $seconde->getDuplicateOf()?->getId());
    }

    /** Une demande ne peut pas être le doublon d'elle-même. */
    public function testUneDemandeNePeutPasEtreSonPropreDoublon(): void
    {
        $demande = $this->deposer();

        $this->expectException(DonneesInvalides::class);

        $this->refus->refuser($demande, $this->admin(), 'Doublon.', $demande->getId());
    }

    /** Une demande déjà tranchée ne se refuse pas une seconde fois. */
    public function testUneDemandeDejaTrancheeNeSeRefusePas(): void
    {
        $demande = $this->deposer();
        $this->refus->refuser($demande, $this->admin(), 'Premier refus.', null);

        try {
            $this->refus->refuser($demande, $this->admin(), 'Second refus.', null);
            self::fail('Une demande refusée ne se refuse pas deux fois.');
        } catch (Conflit $conflit) {
            self::assertSame('demande_deja_decidee', $conflit->codeErreur());
        }

        self::assertSame('Premier refus.', $demande->getDecisionMessage());
    }

    /**
     * Un brouillon ne se refuse pas : il n'a pas été soumis. Le service l'aurait passé à
     * `refusee` sans date de soumission, et PostgreSQL aurait rendu une `500`.
     */
    public function testUnBrouillonNeSeRefusePas(): void
    {
        $corps = $this->corpsDeModification([$this->opTexte('surname', 'Sureau', 'SUREAU')]);
        $corps['soumettre'] = false;
        $brouillon = $this->depot->deposer($corps, $this->lecteur(self::OPALE));

        try {
            $this->refus->refuser($brouillon, $this->admin(), 'Motif.', null);
            self::fail('Un brouillon ne se refuse pas.');
        } catch (Conflit $conflit) {
            self::assertSame('demande_deja_decidee', $conflit->codeErreur());
            self::assertSame(['statut' => 'brouillon'], $conflit->details());
        }

        self::assertSame(RequestStatus::Brouillon, $brouillon->getStatus());
    }

    /**
     * « Refaire cette demande » — **lecture seule**, pré-remplie sur les **valeurs
     * courantes** : `oldValue` et `baseVersion` sont rafraîchis, `newValue` conservé.
     */
    public function testRefaireUneDemandeCaduqueRafraichitLAncienneValeurEtLaVersion(): void
    {
        $demande = $this->deposer();

        // La fiche bouge : la demande devient caduque à la validation.
        $this->arbre->modifierAilleurs(SubjectKind::Personne, self::PERSONNE, 'surname', 'Sureaut');

        try {
            $this->validation->valider($demande, $this->admin());
        } catch (Conflit) {
            // Attendu : c'est la `409` qui écrit.
        }

        $ecrituresAvant = $this->arbre->ecritures();
        $corps = $this->reprise->corpsPreRempli($this->recharger($demande));

        self::assertSame('modification_personne', $corps['kind']);
        self::assertFalse($corps['soumettre'], 'On ne resoumet pas à l\'insu de l\'auteur.');
        self::assertSame('Sureaut', $corps['ops'][0]['oldValue'], 'L\'ancienne valeur est rafraîchie.');
        self::assertSame('SUREAU', $corps['ops'][0]['newValue'], 'La nouvelle valeur est conservée.');
        self::assertSame(8, $corps['ops'][0]['baseVersion'], 'La version de base est rafraîchie.');
        self::assertSame(['demandeId' => $demande->getId(), 'statut' => 'caduque'], $corps['origine']);

        // Aucune écriture : c'est un brouillon de corps, pas une demande.
        self::assertSame($ecrituresAvant, $this->arbre->ecritures());

        // Et le corps rendu est réellement postable — c'est ce que « pré-rempli » promet.
        $refaite = $this->depot->deposer(array_merge($corps, ['soumettre' => true]), $this->lecteur(self::OPALE));
        self::assertSame(RequestStatus::EnAttente, $refaite->getStatus());
    }

    /** `refaire` n'a de sens que sur une demande morte. */
    public function testUneDemandeEnAttenteNEstPasRefaisable(): void
    {
        $demande = $this->deposer();

        try {
            $this->reprise->corpsPreRempli($demande);
            self::fail('Une demande en attente n\'est pas refaisable.');
        } catch (Conflit $conflit) {
            self::assertSame('demande_non_refaisable', $conflit->codeErreur());
        }
    }

    /** Retirer sa demande : elle quitte la file sans être supprimée. */
    public function testRetirerSaDemandeLaSortDeLaFileSansLaSupprimer(): void
    {
        $demande = $this->deposer();

        $this->depot->retirer($demande, $this->lecteur(self::OPALE));

        self::assertSame(RequestStatus::Annulee, $demande->getStatus());
        self::assertNotNull($this->demandes->find((int) $demande->getId()), 'Une demande n\'est jamais supprimée.');
        self::assertSame(
            [],
            $this->calque->pour(SubjectKind::Personne, self::PERSONNE, $this->lecteur(self::OPALE)),
        );
    }

    /**
     * Retirer son brouillon : il n'a jamais été soumis, et le retrait ne lui invente pas de
     * date de soumission. `RetraitDUnBrouillonPostgresTest` vérifie que la base l'accepte.
     */
    public function testRetirerSonBrouillonLAnnuleSansDateDeSoumission(): void
    {
        $corps = $this->corpsDeModification([$this->opTexte('surname', 'Sureau', 'SUREAU')]);
        $corps['soumettre'] = false;
        $brouillon = $this->depot->deposer($corps, $this->lecteur(self::OPALE));
        self::assertSame(RequestStatus::Brouillon, $brouillon->getStatus());

        $this->depot->retirer($brouillon, $this->lecteur(self::OPALE));

        $this->em->clear();
        $relue = $this->demandes->find((int) $brouillon->getId());
        self::assertNotNull($relue, 'Une demande n\'est jamais supprimée.');
        self::assertSame(RequestStatus::Annulee, $relue->getStatus());
        self::assertNull($relue->getSubmittedAt());
    }

    private function deposer(int $auteur = self::OPALE): ChangeRequest
    {
        return $this->depot->deposer(
            $this->corpsDeModification([$this->opTexte('surname', 'Sureau', 'SUREAU')]),
            $this->lecteur($auteur),
        );
    }

    private function admin(): Lecteur
    {
        return new Lecteur(self::ADMIN, 'Natron Romarin', administrateur: true);
    }
}
