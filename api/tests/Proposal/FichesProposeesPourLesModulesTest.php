<?php

declare(strict_types=1);

namespace App\Tests\Proposal;

use App\Module\Core\Contract\FichesProposeesFermees;
use App\Module\Core\Contract\FichesProposeesInterface;
use App\Module\Core\Enum\DepotSurFicheProposee;
use App\Module\Core\Enum\SubjectKind;
use App\Module\Core\ValueObject\UncertainDate;
use App\Module\Proposal\Entity\ChangeRequest;
use App\Module\Proposal\Enum\RequestStatus;
use App\Module\Proposal\Service\FichesProposeesPourLesModules;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * **Qui peut joindre une pièce à une personne proposée** — la règle, sur une vraie base.
 *
 * C'est la moitié `Proposal` de la faille fermée le 09/10/2026 : `POST /api/medias`
 * acceptait n'importe quel couple `demandeId` + `brouillonRef`, et la validation
 * rattachait ensuite la pièce à la personne créée. `Media` pose désormais la question à
 * `Core\Contract\FichesProposeesInterface`, et c'est ce fichier qui vérifie la réponse.
 *
 * L'autre moitié — que `Media` pose la question **avant** d'écrire, et traduise le
 * verdict en `404` ou `409` — est dans `tests/Media/TeleversementMediaTest.php` (service)
 * et `tests/Media/TeleversementSurFicheProposeeTest.php` (HTTP, de bout en bout).
 *
 * ── La matrice ──────────────────────────────────────────────────────────────────
 *
 * Trois lecteurs (l'auteur, un administrateur qui ne l'est pas, un autre membre), cinq
 * statuts. Elle est écrite **en entier**, case par case, plutôt que déduite : une règle
 * d'accès se trompe d'abord sur la case qu'on n'a pas écrite.
 *
 * Le service est lu **dans le conteneur, par l'interface du noyau** : c'est ce que
 * `Media` reçoit. Un alias qui sauterait rendrait ici le repli fermé, et le premier test
 * le dirait en clair plutôt que de laisser vingt cases échouer pour une raison
 * mystérieuse.
 */
final class FichesProposeesPourLesModulesTest extends BaseDeDemandes
{
    /** L'époux auquel les fiches proposées se rattachent — il existe dans l'arbre. */
    private const int JARGON = 55;

    private FichesProposeesInterface $fiches;

    protected function setUp(): void
    {
        parent::setUp();

        $this->arbre->poser(SubjectKind::Personne, self::JARGON, 'Jargon Sarriette', 3, [
            'givenNames' => 'Jargon',
            'surname' => 'Sarriette',
            'marriedName' => null,
            'nickname' => null,
            'sex' => 'masculin',
            'deceased' => true,
            'birth' => UncertainDate::inconnue(),
            'death' => UncertainDate::inconnue(),
            'forcePrivacy' => false,
            'privacyNote' => null,
        ]);

        $fiches = self::getContainer()->get(FichesProposeesInterface::class);
        self::assertInstanceOf(FichesProposeesInterface::class, $fiches);
        $this->fiches = $fiches;
    }

    /**
     * **Le câblage.** `core.yaml` pose le repli fermé, `proposal.yaml` le remplace : c'est
     * l'ordre de chargement qui le décide, et il se constate. Si ce test rougit, `Media`
     * refuse tout dépôt sur une personne proposée — c'est le repli qui a gagné.
     */
    public function testLeConteneurServeLImplementationDeProposalEtNonLeRepliFerme(): void
    {
        self::assertInstanceOf(FichesProposeesPourLesModules::class, $this->fiches);
    }

    /**
     * @return iterable<string, array{RequestStatus, DepotSurFicheProposee, DepotSurFicheProposee, DepotSurFicheProposee}>
     */
    public static function matrice(): iterable
    {
        //                                           auteur                               administrateur                        autre membre
        yield 'brouillon' => [RequestStatus::Brouillon, DepotSurFicheProposee::Joignable, DepotSurFicheProposee::Introuvable, DepotSurFicheProposee::Introuvable];
        yield 'en_attente' => [RequestStatus::EnAttente, DepotSurFicheProposee::Joignable, DepotSurFicheProposee::Joignable, DepotSurFicheProposee::Introuvable];
        yield 'validee' => [RequestStatus::Validee, DepotSurFicheProposee::Tranchee, DepotSurFicheProposee::Introuvable, DepotSurFicheProposee::Introuvable];
        yield 'refusee' => [RequestStatus::Refusee, DepotSurFicheProposee::Tranchee, DepotSurFicheProposee::Introuvable, DepotSurFicheProposee::Introuvable];
        yield 'annulee' => [RequestStatus::Annulee, DepotSurFicheProposee::Tranchee, DepotSurFicheProposee::Introuvable, DepotSurFicheProposee::Introuvable];
    }

    /**
     * La règle, statut par statut et lecteur par lecteur.
     *
     * - **L'auteur** joint une pièce à son brouillon comme à sa demande en attente — on
     *   décrit la personne, on joint l'acte, avant ou après l'envoi. Sur une demande
     *   tranchée, `Tranchee` : elle est à lui, la lui cacher ne protégerait rien.
     * - **L'administrateur** instruit les demandes en attente, et rien d'autre : un
     *   brouillon n'est visible que de son auteur (§ 5.7), une demande tranchée n'a plus
     *   rien à instruire.
     * - **Un autre membre** : jamais, et sans rien apprendre — `Introuvable` partout, comme
     *   pour une demande qui n'existe pas.
     *
     * Les statuts tranchés sont posés **par les vrais services** quand ils existent
     * (validation, refus, retrait) : un statut forcé à la main éprouverait la règle sur un
     * état que l'application ne produit peut-être pas.
     */
    #[DataProvider('matrice')]
    public function testLaRegleStatutParStatutEtLecteurParLecteur(
        RequestStatus $statut,
        DepotSurFicheProposee $pourLAuteur,
        DepotSurFicheProposee $pourLAdministrateur,
        DepotSurFicheProposee $pourUnAutreMembre,
    ): void {
        $demande = $this->demandeAuStatut($statut);
        $id = (int) $demande->getId();

        self::assertSame($statut, $this->recharger($demande)->getStatus(), 'La demande est bien dans l’état éprouvé.');

        self::assertSame($pourLAuteur, $this->fiches->depot($id, 'p1', self::OPALE, false), 'Auteur, '.$statut->value);
        self::assertSame($pourLAdministrateur, $this->fiches->depot($id, 'p1', self::ADMIN, true), 'Administrateur, '.$statut->value);
        self::assertSame($pourUnAutreMembre, $this->fiches->depot($id, 'p1', self::TUF, false), 'Autre membre, '.$statut->value);
    }

    /**
     * Un administrateur **auteur** de la demande suit la règle de l'auteur : il joint une
     * pièce à son propre brouillon, comme n'importe qui.
     */
    public function testUnAdministrateurAuteurDeLaDemandeSuitLaRegleDeLAuteur(): void
    {
        $demande = $this->proposer(self::ADMIN, soumettre: false);

        self::assertSame(DepotSurFicheProposee::Joignable, $this->fiches->depot((int) $demande->getId(), 'p1', self::ADMIN, true));
    }

    /** Une demande qui n'existe pas : `Introuvable` pour tout le monde, administrateur compris. */
    public function testUneDemandeInexistanteEstIntrouvable(): void
    {
        $demande = $this->proposer(self::OPALE);
        $inexistante = (int) $demande->getId() + 1000;

        self::assertSame(DepotSurFicheProposee::Introuvable, $this->fiches->depot($inexistante, 'p1', self::OPALE, false));
        self::assertSame(DepotSurFicheProposee::Introuvable, $this->fiches->depot($inexistante, 'p1', self::ADMIN, true));
    }

    /**
     * Une référence qui ne désigne **aucune fiche de cette demande** : `Introuvable`,
     * même pour son auteur — et **même sur sa demande tranchée**. La référence passe avant
     * le statut : on ne dit pas « relisez votre demande » pour un `#p9` qu'elle n'a jamais
     * porté.
     */
    public function testUneReferenceInconnueEstIntrouvableMemeSurSaDemandeTranchee(): void
    {
        $enAttente = $this->proposer(self::OPALE);
        $refusee = $this->demandeAuStatut(RequestStatus::Refusee);

        self::assertSame(DepotSurFicheProposee::Introuvable, $this->fiches->depot((int) $enAttente->getId(), 'p2', self::OPALE, false));
        self::assertSame(DepotSurFicheProposee::Introuvable, $this->fiches->depot((int) $enAttente->getId(), 'p2', self::ADMIN, true));
        self::assertSame(DepotSurFicheProposee::Introuvable, $this->fiches->depot((int) $refusee->getId(), 'p9', self::OPALE, false));
    }

    /**
     * La référence est acceptée **avec ou sans dièse** : `p1` comme `Media` la reçoit, `#p1`
     * comme `prp_draft_person` la stocke. Une forme qui n'est pas celle d'une référence
     * provisoire ne désigne rien.
     *
     * @return iterable<string, array{string, DepotSurFicheProposee}>
     */
    public static function formesDeReference(): iterable
    {
        yield 'sans dièse' => ['p1', DepotSurFicheProposee::Joignable];
        yield 'avec dièse' => ['#p1', DepotSurFicheProposee::Joignable];
        yield 'deux dièses' => ['##p1', DepotSurFicheProposee::Introuvable];
        yield 'rang zéro' => ['p0', DepotSurFicheProposee::Introuvable];
        yield 'autre lettre' => ['q1', DepotSurFicheProposee::Introuvable];
        yield 'majuscule' => ['P1', DepotSurFicheProposee::Introuvable];
        yield 'vide' => ['', DepotSurFicheProposee::Introuvable];
        yield 'dièse seul' => ['#', DepotSurFicheProposee::Introuvable];
    }

    #[DataProvider('formesDeReference')]
    public function testLaReferenceSAccepteAvecOuSansDiese(string $reference, DepotSurFicheProposee $attendu): void
    {
        $demande = $this->proposer(self::OPALE);

        self::assertSame($attendu, $this->fiches->depot((int) $demande->getId(), $reference, self::OPALE, false));
    }

    /**
     * Une demande d'une autre nature ne porte aucune fiche proposée : `p1` n'y désigne
     * personne, et une pièce n'a rien à y faire par ce chemin — la preuve d'une
     * modification se joint à la **personne existante**, par `subjectId`.
     */
    public function testUneDemandeSansFicheProposeeNAccepteRien(): void
    {
        $modification = $this->depot->deposer(
            $this->corpsDeModification([$this->opTexte('surname', 'Sureau', 'SUREAU')]),
            $this->lecteur(self::OPALE),
        );

        self::assertSame(DepotSurFicheProposee::Introuvable, $this->fiches->depot((int) $modification->getId(), 'p1', self::OPALE, false));
        self::assertSame(DepotSurFicheProposee::Introuvable, $this->fiches->depot((int) $modification->getId(), 'p1', self::ADMIN, true));
    }

    /**
     * Les demandes de deux auteurs portent chacune un `#p1` : la référence n'est unique
     * que **dans sa demande**, et le droit se juge sur la demande nommée, jamais sur la
     * référence seule.
     */
    public function testLaReferenceSeJugeDansSaDemandeEtPasAilleurs(): void
    {
        $deOpale = $this->proposer(self::OPALE);
        $deTuf = $this->proposer(self::TUF);

        self::assertSame(DepotSurFicheProposee::Joignable, $this->fiches->depot((int) $deOpale->getId(), 'p1', self::OPALE, false));
        self::assertSame(DepotSurFicheProposee::Introuvable, $this->fiches->depot((int) $deTuf->getId(), 'p1', self::OPALE, false));
        self::assertSame(DepotSurFicheProposee::Joignable, $this->fiches->depot((int) $deTuf->getId(), 'p1', self::TUF, false));
    }

    /**
     * **La lecture** (10/10/2026, ADR 0017) : l'auteur de la demande, et lui seul, quel qu'en
     * soit le statut — une demande refusée reste consultable par son auteur, avec ses pièces.
     * C'est ce que `Media` demande pour lire une pièce versée par la fusion d'un complément,
     * ou jointe par l'administrateur.
     */
    public function testLAuteurDeLaDemandeEtLuiSeulEnLitLesPiecesQuelQuEnSoitLeStatut(): void
    {
        $refusee = $this->demandeAuStatut(RequestStatus::Refusee);
        $enAttente = $this->proposer(self::OPALE);

        self::assertTrue($this->fiches->estAuteurDe((int) $enAttente->getId(), self::OPALE));
        self::assertTrue($this->fiches->estAuteurDe((int) $refusee->getId(), self::OPALE));
        self::assertFalse($this->fiches->estAuteurDe((int) $enAttente->getId(), self::TUF));
        self::assertFalse($this->fiches->estAuteurDe(999999, self::OPALE), 'Une demande qui n\'existe pas n\'a pas d\'auteur.');
        self::assertFalse((new FichesProposeesFermees())->estAuteurDe((int) $enAttente->getId(), self::OPALE), 'Le repli est fermé.');
    }

    // ─────────────────────────────────────────────────────────────────────────────

    /**
     * Une création de personne d'Opale, amenée au statut voulu **par les services qui le
     * produisent** : soumission, validation, refus, retrait.
     */
    private function demandeAuStatut(RequestStatus $statut): ChangeRequest
    {
        return match ($statut) {
            RequestStatus::Brouillon => $this->proposer(self::OPALE, soumettre: false),
            RequestStatus::EnAttente => $this->proposer(self::OPALE),
            RequestStatus::Validee => $this->tranchee(fn (ChangeRequest $d): mixed => $this->validation->valider($d, $this->lecteur(self::ADMIN, administrateur: true))),
            RequestStatus::Refusee => $this->tranchee(fn (ChangeRequest $d): mixed => $this->refus->refuser($d, $this->lecteur(self::ADMIN, administrateur: true), 'La fiche existe déjà.', null)),
            RequestStatus::Annulee => $this->tranchee(fn (ChangeRequest $d): mixed => $this->depot->retirer($d, $this->lecteur(self::OPALE))),
            RequestStatus::Caduque => self::fail('Une création de personne ne devient pas caduque par ce chemin.'),
        };
    }

    /** @param \Closure(ChangeRequest): mixed $trancher */
    private function tranchee(\Closure $trancher): ChangeRequest
    {
        $demande = $this->proposer(self::OPALE);
        $trancher($demande);

        return $demande;
    }

    private function proposer(int $auteur, bool $soumettre = true): ChangeRequest
    {
        return $this->depot->deposer([
            'kind' => 'creation_personne',
            'soumettre' => $soumettre,
            'brouillons' => [[
                'localRef' => '#p1',
                'givenNames' => 'Tourmaline',
                'surname' => 'Muguet',
                'sex' => 'feminin',
                'deceased' => true,
                'birth' => $this->blocDate($this->annee(1889)),
                'death' => $this->blocDate(UncertainDate::inconnue()),
            ]],
            'liens' => [[
                'seq' => 1,
                'kind' => 'conjoint_de',
                'subjectDraftRef' => '#p1',
                'otherPersonId' => self::JARGON,
            ]],
        ], $this->lecteur($auteur, administrateur: self::ADMIN === $auteur));
    }
}
