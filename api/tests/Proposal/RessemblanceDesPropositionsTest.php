<?php

declare(strict_types=1);

namespace App\Tests\Proposal;

use App\Module\Core\Contract\Lecteur;
use App\Module\Core\Enum\DateQualifier;
use App\Module\Core\Enum\Sex;
use App\Module\Core\ValueObject\IdentiteComparee;
use App\Module\Core\ValueObject\UncertainDate;
use App\Module\Proposal\Entity\ChangeRequest;
use App\Module\Proposal\Entity\DraftPerson;
use App\Module\Proposal\Enum\RequestKind;
use App\Module\Proposal\Enum\RequestStatus;
use App\Module\Proposal\Service\RessemblanceDesPropositions;

/**
 * « Une proposition semblable attend déjà » — **qui voit quoi**, proposition par
 * proposition (§ 6.6 quater du contrat).
 *
 * C'est l'exception écrite au § 13.3 : la route parcourt les propositions de tous les
 * membres. Ces tests fixent ce qui en sort — en clair pour les siennes, pour un
 * administrateur et pour un défunt ; **en signal seul** pour un vivant proposé par un autre
 * membre, signal qui ne dépend d'aucune date — et ce qui n'en sort jamais : le brouillon
 * non envoyé d'un autre.
 *
 * Au niveau du service, avec des `Lecteur` explicites : c'est ce qui rend la règle
 * lisible d'un coup d'œil. La bascule « voir comme un membre » y est un `Lecteur` dont
 * `membreEmprunteUserId` est renseigné — que la session le renseigne bien, c'est
 * `BasculeVoirCommeTest` qui le garde, et `RessemblanceDesPropositionsEnHttpTest` qui le
 * rejoue de bout en bout.
 */
final class RessemblanceDesPropositionsTest extends BaseDeDemandes
{
    /** Les clés de la forme complète, dans l'ordre. */
    private const array CLES_COMPLETES = [
        'forme', 'draftPersonId', 'demandeId', 'localRef', 'noeudId', 'displayName', 'sex',
        'deceased', 'ligneDates', 'authorLabel', 'enAttente', 'lieuNaissance', 'statut',
        'action', 'criteres', 'raisons', 'score',
    ];

    /** Les cinq clés de la forme discrète — et pas une de plus. */
    private const array CLES_DISCRETES = ['forme', 'draftPersonId', 'action', 'criteres', 'raisons'];

    private RessemblanceDesPropositions $ressemblances;

    protected function setUp(): void
    {
        parent::setUp();

        $service = self::getContainer()->get(RessemblanceDesPropositions::class);
        \assert($service instanceof RessemblanceDesPropositions);
        $this->ressemblances = $service;
    }

    // ─────────────────────────────────────────────────────────────────────────────
    //  Les siennes, en clair
    // ─────────────────────────────────────────────────────────────────────────────

    public function testLAuteurVoitSaPropositionEnClairEtLaCompleteSurPlace(): void
    {
        $brouillon = $this->proposer(self::OPALE);
        $demandeId = $brouillon->getRequest()->getId();

        $elements = $this->pour($this->lecteur(self::OPALE), $this->saisie(self::vers(1889)));

        self::assertCount(1, $elements);
        $element = $elements[0];
        self::assertSame(self::CLES_COMPLETES, array_keys($element));
        self::assertSame('complete', $element['forme']);
        self::assertSame($brouillon->getId(), $element['draftPersonId']);
        self::assertSame($demandeId, $element['demandeId']);
        self::assertSame('#p1', $element['localRef']);
        self::assertSame('#'.$demandeId.'/p1', $element['noeudId'], 'Le nœud est dans le graphe de son auteur.');
        self::assertSame('Tourmaline Muguet', $element['displayName']);
        self::assertSame('Compte 11', $element['authorLabel']);
        self::assertSame('Saule, Ombrage, Sylvanie', $element['lieuNaissance']);
        self::assertSame('en_attente', $element['statut']);
        self::assertTrue($element['enAttente']);
        self::assertSame('completer', $element['action']);
        self::assertSame(['nom', 'prenom', 'sexe', 'naissance'], $element['criteres']);
        self::assertSame(4, $element['score']);
        self::assertContains('née vers 1889, comme dans la fiche', $element['raisons']);
    }

    /** Un brouillon non envoyé : visible de son auteur seul, qui le complète sur place. */
    public function testUnBrouillonNonEnvoyeNEstVuQueDeSonAuteur(): void
    {
        $this->proposer(self::OPALE, envoyee: false);

        $pourOpale = $this->pour($this->lecteur(self::OPALE));
        self::assertCount(1, $pourOpale);
        self::assertSame('brouillon', $pourOpale[0]['statut']);
        self::assertFalse($pourOpale[0]['enAttente']);
        self::assertSame('completer', $pourOpale[0]['action']);
        self::assertNull($pourOpale[0]['noeudId'], 'Un brouillon non envoyé n’est dans aucun graphe.');

        self::assertSame([], $this->pour($this->lecteur(self::TUF)), 'Pas même en signal, pour un autre membre.');
        self::assertSame([], $this->pour($this->lecteur(self::ADMIN, administrateur: true)), 'Ni pour un administrateur.');
    }

    // ─────────────────────────────────────────────────────────────────────────────
    //  Celles des autres
    // ─────────────────────────────────────────────────────────────────────────────

    /** Un défunt est un fait historique : sa proposition se montre en clair à tout membre. */
    public function testLaPropositionDUnDefuntParUnAutreMembreSortEnClair(): void
    {
        $this->proposer(self::OPALE, decedee: true);

        $elements = $this->pour($this->lecteur(self::TUF), $this->saisie(self::vers(1889)));

        self::assertCount(1, $elements);
        self::assertSame(self::CLES_COMPLETES, array_keys($elements[0]));
        self::assertSame('complete', $elements[0]['forme']);
        self::assertSame('Tourmaline Muguet', $elements[0]['displayName']);
        self::assertSame('Compte 11', $elements[0]['authorLabel']);
        self::assertSame('complement', $elements[0]['action'], 'Un autre membre dépose un complément.');
        self::assertNull($elements[0]['noeudId'], 'Le nœud n’est pas dans le graphe d’un autre membre.');
        self::assertContains('naissance', $elements[0]['criteres']);
    }

    /**
     * **La règle sensible.** Un vivant proposé par un autre membre : la phrase, et rien
     * d'autre — ni nom, ni date, ni lieu, ni auteur, ni numéro de demande, ni nœud.
     */
    public function testLaPropositionDUnVivantParUnAutreMembreNeSortQuEnSignal(): void
    {
        $this->proposer(self::OPALE);

        $elements = $this->pour($this->lecteur(self::TUF), $this->saisie(self::vers(1889)));

        self::assertCount(1, $elements);
        self::assertSame(self::CLES_DISCRETES, array_keys($elements[0]));
        self::assertSame('discrete', $elements[0]['forme']);
        self::assertSame('complement', $elements[0]['action']);
        self::assertSame(['nom', 'prenom', 'sexe'], $elements[0]['criteres'], 'Ni la naissance ni le lieu ne sont comparés.');
        self::assertSame(['nom semblable', 'un prénom en commun', 'même sexe'], $elements[0]['raisons']);

        $json = json_encode($elements, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE);
        foreach (['Tourmaline', 'Muguet', '1889', 'Saule', 'Compte 11', 'vers', 'en_attente'] as $valeur) {
            self::assertStringNotContainsString($valeur, $json);
        }
    }

    /**
     * **L'oracle, fermé.** Le signal d'une proposition discrète ne dépend en rien de la date
     * saisie : ni de sa présence, ni de sa valeur, ni d'une date incompatible.
     */
    public function testLeSignalNeDependPasDeLaDateSaisie(): void
    {
        $this->proposer(self::OPALE);
        $tuf = $this->lecteur(self::TUF);

        $sansDate = $this->pour($tuf, $this->saisie());

        self::assertCount(1, $sansDate);
        self::assertSame($sansDate, $this->pour($tuf, $this->saisie(self::vers(1889))));
        self::assertSame($sansDate, $this->pour($tuf, $this->saisie($this->annee(1990))));
        self::assertSame($sansDate, $this->pour($tuf, $this->saisie($this->annee(1990), $this->annee(1991))));
        self::assertSame($sansDate, $this->pour($tuf, $this->saisie(new UncertainDate(null, DateQualifier::Inconnue, null, null, 'Saule'))));

        // La contre-épreuve : l'auteur, qui la voit en clair, est bien écarté par une date
        // incompatible — c'est le masque qui fait l'égalité, pas une comparaison morte.
        self::assertSame([], $this->pour($this->lecteur(self::OPALE), $this->saisie($this->annee(1990))));
    }

    public function testUnSexeOpposeEcarteMemeUnSignal(): void
    {
        $this->proposer(self::OPALE);

        self::assertSame([], $this->pour($this->lecteur(self::TUF), new IdentiteComparee('Tourmaline', 'Muguet', null, null, Sex::Masculin)));
    }

    // ─────────────────────────────────────────────────────────────────────────────
    //  L'administrateur, et la bascule
    // ─────────────────────────────────────────────────────────────────────────────

    public function testLAdministrateurVoitLaFileEnClairEtLaCompleteSurPlace(): void
    {
        $proposee = $this->proposer(self::OPALE);
        $sienne = $this->proposer(self::ADMIN, envoyee: false, prenoms: 'Tourmaline Opale');

        $elements = $this->pour($this->lecteur(self::ADMIN, administrateur: true));

        self::assertCount(2, $elements);
        $parFiche = array_column($elements, null, 'draftPersonId');

        $file = $parFiche[$proposee->getId()];
        self::assertSame('complete', $file['forme']);
        self::assertSame('Compte 11', $file['authorLabel']);
        self::assertSame('completer', $file['action'], 'L’administrateur complète sur place une proposition en attente.');
        self::assertSame('#'.$proposee->getRequest()->getId().'/p1', $file['noeudId'], 'Il voit toute la file dans son graphe.');

        self::assertSame('completer', $parFiche[$sienne->getId()]['action'], 'Et son propre brouillon, en auteur.');
    }

    /**
     * Sous « voir comme un membre », l'administrateur lit **comme le membre** : la
     * proposition vivante d'un autre redevient un signal, les brouillons parcourus sont
     * ceux du membre et non les siens.
     */
    public function testSousLaBasculeLAdministrateurVoitCommeLeMembre(): void
    {
        $dOpale = $this->proposer(self::OPALE);
        $deTuf = $this->proposer(self::TUF, envoyee: false, prenoms: 'Tourmaline Jaspe');
        $delAdmin = $this->proposer(self::ADMIN, envoyee: false, prenoms: 'Tourmaline Opale');

        $emprunt = new Lecteur(self::ADMIN, 'Natron Romarin', administrateur: true, membreEmprunteUserId: self::TUF);
        $parFiche = array_column($this->pour($emprunt), null, 'draftPersonId');

        self::assertEqualsCanonicalizing([$dOpale->getId(), $deTuf->getId()], array_keys($parFiche));
        self::assertSame('discrete', $parFiche[$dOpale->getId()]['forme']);
        self::assertSame('complement', $parFiche[$dOpale->getId()]['action']);
        self::assertSame('complete', $parFiche[$deTuf->getId()]['forme']);
        self::assertSame('completer', $parFiche[$deTuf->getId()]['action']);
        self::assertArrayNotHasKey((int) $delAdmin->getId(), $parFiche, 'Le brouillon de l’administrateur n’est pas celui du membre.');
    }

    public function testUnCompteEnAttenteNeRecoitRien(): void
    {
        $this->proposer(self::OPALE, decedee: true);

        self::assertSame(
            ['elements' => [], 'tronque' => false],
            $this->ressemblances->resultats($this->saisie(), 5, $this->lecteur(self::TUF, enAttente: true)),
        );
    }

    // ─────────────────────────────────────────────────────────────────────────────
    //  Ce qui est parcouru
    // ─────────────────────────────────────────────────────────────────────────────

    /** Une demande tranchée n'a plus rien à offrir ; une demande qui n'est pas une création non plus. */
    public function testSeulesLesCreationsEnAttenteOuEnBrouillonSontParcourues(): void
    {
        $refusee = $this->proposer(self::OPALE, decedee: true);
        $refusee->getRequest()->setStatus(RequestStatus::Refusee);
        $this->proposer(self::OPALE, decedee: true, nature: RequestKind::AjoutLien);
        $this->em->flush();

        self::assertSame([], $this->pour($this->lecteur(self::OPALE)));
        self::assertSame([], $this->pour($this->lecteur(self::ADMIN, administrateur: true)));
    }

    /** La demande qu'on est en train de compléter ne se signale pas à elle-même. */
    public function testSaufRetireLaDemandeQuOnComplete(): void
    {
        $brouillon = $this->proposer(self::OPALE);
        $demandeId = $brouillon->getRequest()->getId();
        self::assertNotNull($demandeId);

        $resultat = $this->ressemblances->resultats($this->saisie(), 5, $this->lecteur(self::OPALE), $demandeId);

        self::assertSame([], $resultat['elements']);
    }

    /**
     * Les plus ressemblantes d'abord, puis la limite ; `tronque` dit qu'il en restait,
     * sans dire combien.
     */
    public function testLesRessemblancesSeTrientPuisSeCoupent(): void
    {
        $sansDate = $this->proposer(self::OPALE, naissance: UncertainDate::inconnue());
        $avecDate = $this->proposer(self::OPALE);

        $toutes = $this->ressemblances->resultats($this->saisie(self::vers(1889)), 5, $this->lecteur(self::OPALE));
        self::assertSame([$avecDate->getId(), $sansDate->getId()], array_column($toutes['elements'], 'draftPersonId'));
        self::assertSame([4, 3], array_column($toutes['elements'], 'score'));
        self::assertFalse($toutes['tronque']);

        $une = $this->ressemblances->resultats($this->saisie(self::vers(1889)), 1, $this->lecteur(self::OPALE));
        self::assertSame(['elements', 'tronque'], array_keys($une));
        self::assertCount(1, $une['elements']);
        self::assertTrue($une['tronque']);
    }

    /**
     * L'exemple du § 6.6 quater, rejoué tel qu'écrit : la proposition du lecteur en clair,
     * celle d'un vivant faite par un autre en signal — et dans cet ordre, la première ayant
     * un critère de plus (la naissance, que la forme discrète ne compare pas).
     */
    public function testLExempleDuContrat(): void
    {
        $sienne = $this->proposer(self::OPALE, prenoms: 'Barnabé', decedee: true, naissance: new UncertainDate('1901', DateQualifier::Annee, new \DateTimeImmutable('1901-01-01'), new \DateTimeImmutable('1901-12-31'), 'Saule, Ombrage, Sylvanie'), sexe: Sex::Masculin, deces: $this->annee(1968));
        $autre = $this->proposer(self::TUF, prenoms: 'Barnabé', sexe: Sex::Masculin);
        $demandeId = $sienne->getRequest()->getId();

        $resultat = $this->ressemblances->resultats(
            new IdentiteComparee('Barnabé', 'Muguet', null, null, Sex::Masculin, $this->annee(1901)),
            5,
            $this->lecteur(self::OPALE),
        );

        self::assertSame(['elements' => [
            [
                'forme' => 'complete',
                'draftPersonId' => $sienne->getId(),
                'demandeId' => $demandeId,
                'localRef' => '#p1',
                'noeudId' => '#'.$demandeId.'/p1',
                'displayName' => 'Barnabé Muguet',
                'sex' => 'masculin',
                'deceased' => true,
                'ligneDates' => '1901 – 1968',
                'authorLabel' => 'Compte 11',
                'enAttente' => true,
                'lieuNaissance' => 'Saule, Ombrage, Sylvanie',
                'statut' => 'en_attente',
                'action' => 'completer',
                'criteres' => ['nom', 'prenom', 'sexe', 'naissance'],
                'raisons' => ['même nom', 'prénom Barnabé en commun', 'même sexe', 'né en 1901, comme dans la fiche'],
                'score' => 4,
            ],
            [
                'forme' => 'discrete',
                'draftPersonId' => $autre->getId(),
                'action' => 'complement',
                'criteres' => ['nom', 'prenom', 'sexe'],
                'raisons' => ['nom semblable', 'un prénom en commun', 'même sexe'],
            ],
        ], 'tronque' => false], $resultat);
    }

    // ─────────────────────────────────────────────────────────────────────────────

    /** @return list<array<string, mixed>> */
    private function pour(Lecteur $lecteur, ?IdentiteComparee $saisie = null): array
    {
        return $this->ressemblances->resultats($saisie ?? $this->saisie(), 5, $lecteur)['elements'];
    }

    private function saisie(?UncertainDate $naissance = null, ?UncertainDate $deces = null): IdentiteComparee
    {
        return new IdentiteComparee('Tourmaline', 'Muguet', null, null, Sex::Feminin, $naissance, $deces);
    }

    /**
     * Tourmaline Muguet, née vers 1889 à Saule — l'exemple du § 6.6 du contrat. Vivante
     * par défaut : c'est le cas que la forme discrète protège.
     */
    private function proposer(
        int $auteur,
        bool $envoyee = true,
        string $prenoms = 'Tourmaline',
        bool $decedee = false,
        ?UncertainDate $naissance = null,
        RequestKind $nature = RequestKind::CreationPersonne,
        Sex $sexe = Sex::Feminin,
        ?UncertainDate $deces = null,
    ): DraftPerson {
        $demande = new ChangeRequest($nature, $auteur, 'Compte '.$auteur);

        if ($envoyee) {
            $demande->soumettre();
        }

        $this->em->persist($demande);

        $brouillon = new DraftPerson($demande, '#p1');
        $brouillon
            ->setGivenNames($prenoms)
            ->setSurname('Muguet')
            ->setSex($sexe)
            ->setDeceased($decedee)
            ->setBirth($naissance ?? new UncertainDate('ABT 1889', DateQualifier::Environ, new \DateTimeImmutable('1889-01-01'), new \DateTimeImmutable('1889-12-31'), 'Saule, Ombrage, Sylvanie'))
            ->setDeath($decedee ? ($deces ?? $this->annee(1972)) : UncertainDate::inconnue());

        $this->em->persist($brouillon);
        $this->em->flush();

        return $brouillon;
    }

    private static function vers(int $annee): UncertainDate
    {
        return new UncertainDate('ABT '.$annee, DateQualifier::Environ, new \DateTimeImmutable($annee.'-01-01'), new \DateTimeImmutable($annee.'-12-31'));
    }
}
