<?php

declare(strict_types=1);

namespace App\Tests\Proposal;

use App\Module\Core\Enum\Sex;
use App\Module\Core\ValueObject\IdentiteComparee;
use App\Module\Core\ValueObject\UncertainDate;
use App\Module\Proposal\Enum\RequestStatus;
use App\Module\Proposal\Exception\Conflit;
use App\Module\Proposal\Exception\DonneesInvalides;
use App\Module\Proposal\Exception\ErreurDeDemande;
use App\Module\Proposal\Exception\Introuvable;
use App\Module\Proposal\Service\RessemblanceDesPropositions;
use App\Module\Proposal\Service\ResolutionPrevue;

/**
 * ═══════════════════════════════════════════════════════════════════════════════
 * DÉPOSER UN COMPLÉMENT — § 6.3 ter, ADR 0017.
 *
 * Tuf tape le nom de son arrière-grand-père ; la ressemblance lui apprend qu'**une
 * proposition semblable attend déjà** — celle d'Opale. Il ne peut pas la modifier, et ne
 * doit pas en déposer une seconde : il dépose un **complément**, une création ordinaire
 * qui porte `complementDe`, et que l'administrateur fusionnera
 * ({@see FusionDeComplementTest}).
 *
 * Ce fichier tient le dépôt, la vue d'administration, et les deux validations bloquées.
 */
final class ComplementDePropositionTest extends BaseDeCompletion
{
    // ---------------------------------------------------------------------------------
    // Le dépôt
    // ---------------------------------------------------------------------------------

    public function testUnMembreDeposeUnComplementALaPropositionDUnAutre(): void
    {
        $origine = $this->proposer(self::OPALE);
        $fiche = $this->ficheDe($origine);

        $complement = $this->completerPar(self::TUF, $origine, ['birth' => $this->blocDate($this->annee(1901))]);

        $relu = $this->recharger($complement);
        self::assertSame(RequestStatus::EnAttente, $relu->getStatus());
        self::assertSame($fiche->getId(), $relu->getComplementDe()?->getId());

        // La vue de son auteur : l'identifiant de la fiche, **rien de plus** — il ne voit pas
        // la proposition d'Opale.
        $vue = $this->serialiseur->pourAuteur($relu);
        self::assertSame(['draftPersonId' => $fiche->getId()], $vue['complementDe']);

        // Et dans « mes demandes », le numéro de la demande complétée.
        self::assertSame($origine->getId(), $this->serialiseur->resume($relu)['complementDeDemandeId']);

        // Une soumission comme une autre : les administrateurs sont prévenus.
        self::assertCount(2, $this->notificateur->de('demande_soumise'));
    }

    public function testUneDemandeOrdinaireNeCompleteRien(): void
    {
        $demande = $this->proposer(self::OPALE);

        self::assertNull($this->serialiseur->pourAuteur($demande)['complementDe']);
        self::assertNull($this->serialiseur->resume($demande)['complementDeDemandeId']);
    }

    public function testUnComplementNeDecritQuUneSeuleFiche(): void
    {
        $origine = $this->proposer(self::OPALE);

        $erreur = $this->echec(fn () => $this->depot->deposer([
            'kind' => 'creation_personne',
            'soumettre' => true,
            'complementDe' => $this->ficheDe($origine)->getId(),
            'brouillons' => [$this->fiche(), $this->fiche(['localRef' => '#p2', 'givenNames' => 'Céleste'])],
            'liens' => [$this->lienConjoint()],
        ], $this->lecteur(self::TUF)));

        self::assertInstanceOf(DonneesInvalides::class, $erreur);
        self::assertArrayHasKey('brouillons', (array) $erreur->champs());
    }

    /** Sa propre proposition se complète **sur place** — pas par un complément. */
    public function testOnNeCompletePasSaPropreProposition(): void
    {
        $origine = $this->proposer(self::OPALE);

        $erreur = $this->echec(fn () => $this->completerPar(self::OPALE, $origine));

        self::assertInstanceOf(DonneesInvalides::class, $erreur);
        self::assertStringContainsString('sur place', (string) ((array) $erreur->champs())['complementDe']);
    }

    /** Son propre brouillon non plus — et en `422`, pas en `404` : il le voit. */
    public function testOnNeCompletePasSonPropreBrouillon(): void
    {
        $origine = $this->proposer(self::OPALE, soumettre: false);

        $erreur = $this->echec(fn () => $this->completerPar(self::OPALE, $origine));

        self::assertInstanceOf(DonneesInvalides::class, $erreur);
    }

    /**
     * Un brouillon non envoyé n'est visible que de son auteur : celui d'un autre est
     * **introuvable**, exactement comme une fiche qui n'existe pas.
     */
    public function testLeBrouillonDUnAutreEstIntrouvable(): void
    {
        $origine = $this->proposer(self::OPALE, soumettre: false);

        $erreur = $this->echec(fn () => $this->completerPar(self::TUF, $origine));

        self::assertInstanceOf(Introuvable::class, $erreur);
        self::assertSame(404, $erreur->statutHttp());
    }

    public function testUneFicheInexistanteEstIntrouvable(): void
    {
        $erreur = $this->echec(fn () => $this->proposer(self::TUF, complementDe: 999999));

        self::assertInstanceOf(Introuvable::class, $erreur);
    }

    /**
     * Une proposition tranchée ne se complète plus — et la réponse ne dit pas comment elle
     * a été tranchée : l'auteur du complément ne voit pas la proposition d'un autre.
     */
    public function testUnePropositionTrancheeNeSeCompletePlusEtLeConflitNeDitRien(): void
    {
        $origine = $this->proposer(self::OPALE);
        $fiche = $this->ficheDe($origine)->getId();
        $this->refus->refuser($origine, $this->admin(), 'Déjà dans l\'arbre.', null);

        $erreur = $this->echec(fn () => $this->proposer(self::TUF, complementDe: $fiche));

        self::assertInstanceOf(Conflit::class, $erreur);
        self::assertSame('proposition_plus_en_attente', $erreur->codeErreur());
        self::assertSame(409, $erreur->statutHttp());
        self::assertNull($erreur->details(), 'Ni le statut, ni le motif, ni le numéro.');
    }

    /** Pas de chaîne : un complément se rattache à l'origine, pas à un autre complément. */
    public function testOnNeCompletePasUnComplement(): void
    {
        $origine = $this->proposer(self::OPALE);
        $complement = $this->completerPar(self::TUF, $origine);

        $erreur = $this->echec(fn () => $this->completerPar(self::ADMIN, $complement));

        self::assertInstanceOf(DonneesInvalides::class, $erreur);
        self::assertArrayHasKey('complementDe', (array) $erreur->champs());
    }

    /**
     * `complementDe` n'a de sens que sur une création : sur une autre nature, il serait lu
     * par personne — refusé plutôt qu'ignoré en silence.
     */
    public function testComplementDeHorsDUneCreationEstRefuse(): void
    {
        $fiche = $this->ficheDe($this->proposer(self::OPALE))->getId();

        $modification = $this->corpsDeModification([$this->opTexte('surname', 'Sureau', 'SUREAU')]) + ['complementDe' => $fiche];
        $erreur = $this->echec(fn () => $this->depot->deposer($modification, $this->lecteur(self::TUF)));
        self::assertArrayHasKey('complementDe', (array) $erreur->champs());

        $ajoutDeLien = [
            'kind' => 'ajout_lien',
            'soumettre' => true,
            'complementDe' => $fiche,
            'liens' => [['seq' => 1, 'kind' => 'conjoint_de', 'subjectPersonId' => self::ENFANT, 'otherPersonId' => self::JARGON]],
        ];
        $erreur = $this->echec(fn () => $this->depot->deposer($ajoutDeLien, $this->lecteur(self::TUF)));
        self::assertArrayHasKey('complementDe', (array) $erreur->champs());
    }

    public function testComplementDeEstUnEntier(): void
    {
        $erreur = $this->echec(fn () => $this->depot->deposer([
            'kind' => 'creation_personne',
            'complementDe' => '7',
            'brouillons' => [$this->fiche()],
            'liens' => [$this->lienConjoint()],
        ], $this->lecteur(self::TUF)));

        self::assertArrayHasKey('complementDe', (array) $erreur->champs());
    }

    /**
     * **Au moins un lien**, comme toute création : le complément doit rester validable seul,
     * parce que l'origine peut mourir avant la fusion.
     */
    public function testUnComplementSansLienEstRefuseALaSoumission(): void
    {
        $origine = $this->proposer(self::OPALE);

        $erreur = $this->echec(fn () => $this->completerPar(self::TUF, $origine, liens: []));

        self::assertSame('demande_sans_lien', $erreur->codeErreur());
    }

    /**
     * Un complément enregistré en brouillon se soumet plus tard — et la règle du dépôt vaut
     * à la soumission : sa proposition a pu être tranchée entre-temps.
     */
    public function testUnComplementEnBrouillonNeSeSoumetPlusQuandSonOrigineAEteTranchee(): void
    {
        $origine = $this->proposer(self::OPALE);
        $complement = $this->proposer(self::TUF, soumettre: false, complementDe: $this->ficheDe($origine)->getId());
        $this->refus->refuser($origine, $this->admin(), 'Déjà dans l\'arbre.', null);

        $erreur = $this->echec(fn () => $this->depot->soumettre($this->recharger($complement), $this->lecteur(self::TUF)));

        self::assertSame('proposition_plus_en_attente', $erreur->codeErreur());
        self::assertSame(RequestStatus::Brouillon, $this->recharger($complement)->getStatus());
    }

    /** Refaire un complément refusé en fait une création ordinaire : on ne rattache pas à l'aveugle. */
    public function testRefaireUnComplementNeReprendPasSonRattachement(): void
    {
        $origine = $this->proposer(self::OPALE);
        $complement = $this->completerPar(self::TUF, $origine);
        $this->refus->refuser($complement, $this->admin(), 'Rien de nouveau.', null);

        $corps = $this->reprise->corpsPreRempli($this->recharger($complement));

        self::assertArrayNotHasKey('complementDe', $corps);
    }

    // ---------------------------------------------------------------------------------
    // La vue d'administration
    // ---------------------------------------------------------------------------------

    public function testLaVueDAdministrationDeLOrigineListeSesComplementsAvecLeurApercu(): void
    {
        $origine = $this->proposer(self::OPALE);
        $complement = $this->completerPar(self::TUF, $origine, ['sex' => 'masculin', 'birth' => $this->blocDate($this->annee(1901))]);

        $vue = $this->serialiseur->pourAdministration($this->recharger($origine));

        self::assertCount(1, $vue['complements']);
        $ligne = $vue['complements'][0];
        self::assertSame(
            ['id', 'authorLabel', 'submittedAt', 'status', 'draftPersonId', 'origineDraftPersonId', 'displayName', 'ligneDates', 'apercu', 'empreinte'],
            array_keys($ligne),
        );
        self::assertSame($complement->getId(), $ligne['id']);
        self::assertSame('Compte '.self::TUF, $ligne['authorLabel']);
        self::assertSame('en_attente', $ligne['status']);
        self::assertSame($this->ficheDe($origine)->getId(), $ligne['origineDraftPersonId']);
        self::assertSame('Barnabé Muguet', $ligne['displayName']);
        self::assertSame(
            ['remplis', 'conflits', 'liensAjoutes', 'liensIgnores', 'mediasDeplaces', 'demandesRepointees'],
            array_keys($ligne['apercu']),
        );
        self::assertSame(['sex', 'birth', 'note'], array_column($ligne['apercu']['remplis'], 'champ'));
        self::assertSame(ResolutionPrevue::ComplementsEnAttente->value, $vue['resolutionPrevue']);
        self::assertNull($vue['complementDe']);
    }

    public function testLaVueDAdministrationDUnComplementDitCeQuIlComplete(): void
    {
        $origine = $this->proposer(self::OPALE);
        $complement = $this->completerPar(self::TUF, $origine);

        $vue = $this->serialiseur->pourAdministration($this->recharger($complement));

        self::assertSame([
            'draftPersonId' => $this->ficheDe($origine)->getId(),
            'demandeId' => $origine->getId(),
            'localRef' => '#p1',
            'displayName' => 'Barnabé Muguet',
            'status' => 'en_attente',
        ], $vue['complementDe']);
        self::assertSame([], $vue['complements']);
        self::assertSame(ResolutionPrevue::ComplementAFusionner->value, $vue['resolutionPrevue']);
    }

    /**
     * **Un complément n'est pas un doublon probable de son origine** — ni des autres
     * compléments de la même origine. Une troisième proposition indépendante, elle, l'est.
     */
    public function testUnComplementNEstPasUnDoublonProbableDeSonOrigine(): void
    {
        $origine = $this->proposer(self::OPALE);
        $premier = $this->completerPar(self::TUF, $origine);
        $second = $this->completerPar(self::ADMIN, $origine);
        $independante = $this->proposer(13);

        self::assertSame($origine->getDedupKey(), $premier->getDedupKey(), 'Même personne, même clé : c\'est tout le sujet.');

        $doublons = static fn (array $vue): array => array_column($vue['doublonsProbables'], 'id');

        self::assertSame([$independante->getId()], $doublons($this->serialiseur->pourAdministration($this->recharger($origine))));
        self::assertSame([$independante->getId()], $doublons($this->serialiseur->pourAdministration($this->recharger($premier))));
        self::assertSame([$independante->getId()], $doublons($this->serialiseur->pourAdministration($this->recharger($second))));
        // Déposées dans la même seconde : l'ordre de `submittedAt` ne les départage pas.
        self::assertEqualsCanonicalizing(
            [$origine->getId(), $premier->getId(), $second->getId()],
            $doublons($this->serialiseur->pourAdministration($this->recharger($independante))),
            'La proposition indépendante, elle, voit les trois.',
        );
    }

    /** La ressemblance ne signale pas un complément comme une proposition de plus. */
    public function testUnComplementNApparaitPasDansLesRessemblances(): void
    {
        $origine = $this->proposer(self::OPALE);
        $this->completerPar(self::TUF, $origine);

        $saisie = new IdentiteComparee('Barnabé', 'Muguet', null, null, Sex::Inconnu, UncertainDate::inconnue(), UncertainDate::inconnue());
        $resultats = self::getContainer()->get(RessemblanceDesPropositions::class)->resultats($saisie, 10, $this->admin());

        self::assertCount(1, $resultats['elements']);
        self::assertSame($this->ficheDe($origine)->getId(), $resultats['elements'][0]['draftPersonId']);
    }

    // ---------------------------------------------------------------------------------
    // Les validations bloquées
    // ---------------------------------------------------------------------------------

    /**
     * L'origine ne se valide pas **avant** ses compléments : la personne serait créée sans
     * eux, et chacun deviendrait une seconde création de la même personne. Rien n'est écrit.
     */
    public function testLaValidationDeLOrigineAttendSesComplements(): void
    {
        $origine = $this->proposer(self::OPALE);
        $complement = $this->completerPar(self::TUF, $origine);

        $erreur = $this->echec(fn () => $this->validation->valider($this->recharger($origine), $this->admin()));

        self::assertInstanceOf(Conflit::class, $erreur);
        self::assertSame('complements_en_attente', $erreur->codeErreur());
        self::assertSame([
            'demandeId' => $origine->getId(),
            'statut' => 'en_attente',
            'rejouable' => true,
            'complements' => [$complement->getId()],
        ], $erreur->details());
        self::assertSame(RequestStatus::EnAttente, $this->recharger($origine)->getStatus());
        self::assertSame(0, $this->arbre->ecritures());
    }

    /** Un complément refusé ne bloque plus : l'origine se valide. */
    public function testUnComplementRefuseNeBloquePlusLOrigine(): void
    {
        $origine = $this->proposer(self::OPALE);
        $complement = $this->completerPar(self::TUF, $origine);
        $this->refus->refuser($complement, $this->admin(), 'Rien de nouveau.', null);

        self::assertSame('validee', $this->validation->valider($this->recharger($origine), $this->admin())->resultat);
    }

    /** Le complément ne se valide pas tant que son origine attend : on le **fusionne**. */
    public function testLeComplementNeSeValidePasTantQueSonOrigineAttend(): void
    {
        $origine = $this->proposer(self::OPALE);
        $complement = $this->completerPar(self::TUF, $origine);

        $erreur = $this->echec(fn () => $this->validation->valider($this->recharger($complement), $this->admin()));

        self::assertSame('complement_a_fusionner', $erreur->codeErreur());
        self::assertSame([
            'demandeId' => $complement->getId(),
            'statut' => 'en_attente',
            'rejouable' => false,
            'origineDemandeId' => $origine->getId(),
            'origineStatut' => 'en_attente',
        ], $erreur->details());
        self::assertSame(0, $this->arbre->ecritures());
    }

    /**
     * Une origine **validée** — le cas ne se produit pas par le circuit, qui la bloque tant
     * qu'un complément attend, mais un correctif à la main le pourrait : le valider
     * doublerait la personne. La garde tient quand même.
     */
    public function testLeComplementDUneOrigineValideeNeSeValidePas(): void
    {
        $origine = $this->proposer(self::OPALE);
        $complement = $this->completerPar(self::TUF, $origine);

        $this->recharger($origine)->decider(RequestStatus::Validee, self::ADMIN, 'Natron Romarin');
        $this->em->flush();

        $erreur = $this->echec(fn () => $this->validation->valider($this->recharger($complement), $this->admin()));

        self::assertSame('complement_a_fusionner', $erreur->codeErreur());
        self::assertSame('validee', ((array) $erreur->details())['origineStatut']);
    }

    /** Une origine refusée rend le complément **autonome** : il se valide comme une création. */
    public function testUneOrigineRefuseeRendLeComplementAutonome(): void
    {
        $origine = $this->proposer(self::OPALE);
        $complement = $this->completerPar(self::TUF, $origine);
        $this->refus->refuser($origine, $this->admin(), 'Doublon d\'une fiche existante.', null);

        $resultat = $this->validation->valider($this->recharger($complement), $this->admin());

        self::assertSame('validee', $resultat->resultat);
        self::assertCount(1, $this->arbre->personnesCreees());
    }

    // ---------------------------------------------------------------------------------
    // Outils
    // ---------------------------------------------------------------------------------

    private function echec(\Closure $geste): ErreurDeDemande
    {
        try {
            $geste();
        } catch (ErreurDeDemande $erreur) {
            return $erreur;
        }

        self::fail('Le geste devait être refusé.');
    }
}
