<?php

declare(strict_types=1);

namespace App\Tests\Tree;

use App\Module\Core\Enum\Branch;
use App\Module\Core\Enum\DateQualifier;
use App\Module\Core\Enum\Sex;
use App\Module\Core\Security\CercleDeVisibiliteInterface;
use App\Module\Core\ValueObject\ComposantesDeDate;
use App\Module\Core\ValueObject\UncertainDate;
use App\Module\Membership\Entity\Member;
use App\Module\Membership\Enum\MatchKind;
use App\Module\Tree\Entity\Child;
use App\Module\Tree\Entity\Family;
use App\Module\Tree\Entity\Person;
use App\Module\Tree\Enum\ChildRelation;
use App\Module\Tree\Serializer\CercleDuLecteur;
use App\Module\Tree\Serializer\EtatVivant;
use App\Module\Tree\Serializer\LectureDeLArbre;
use App\Module\Tree\Serializer\NormaliseurDAffiche;
use App\Module\Tree\Serializer\NormaliseurDeDate;
use App\Tests\Membership\Double\ArbreEnMemoire;
use Symfony\Component\HttpFoundation\Response;

/**
 * L'arbre d'une affiche — `GET /api/arbre/familles/{id}/affiche`, § 5.4 bis du contrat.
 *
 * Deux moitiés, comme pour les fiches : le normaliseur appelé **directement**, avec un
 * cercle construit à la main — c'est là que se prouvent la liste blanche, la réciprocité, le
 * périmètre et la forme de l'arbre servi — ; puis la route traversée pour de vrai, qui prouve
 * que c'est bien cette décision qui sort du tuyau.
 *
 * ## L'arbre de ce test, ajouté au socle
 *
 * Le couple est celui du socle, les aïeux (Émeri Sarriette et Marne Dolomite Sureau), dont
 * `ego` est l'enfant. Tous les noms sont inventés (ADR 0006).
 *
 * ```
 *        Basalte Sarriette ∞ Ambre Lichen          Gypse Orpin (adoption d'Émeri)
 *              ┌──────┴──────┐
 *        Émeri Sarriette   Quartz Sarriette ∞ Opale Tilleul
 *              │                    └──── Marne Dolomite Sureau
 *              └───────── ∞ ───────────────────┘
 *        ┌───────────┬────────┴───┬───────────────┐
 *   Sodalite (1930)  ego (2012)  Tourmaline (sans date)   Péridot (autre arbre)
 *     ∞ Rubis Fenouil (1950) → Jaspe → Calcite → … (une chaîne de sept générations)
 *     & Agate Cerfeuil (divorcés, sans mariage connu)
 * ```
 *
 * Basalte et Ambre sont à deux places de l'ascendance (un **implexe**) : grands-parents du
 * mari, arrière-grands-parents de la femme.
 */
final class AfficheDUnCoupleTest extends ArbreWebTestCase
{
    /** @var array<string, Person> */
    private array $ajoutees = [];

    /** @var array<string, Family> */
    private array $unions = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->semerLArbreDeLAffiche();
    }

    public function testLAscendanceRemonteChaqueAncetreUneFoisEtRetientLaFiliationDeNaissance(): void
    {
        $affiche = $this->affiche(CercleDuLecteur::de([]));

        $ids = array_column($affiche['personnes'], 'id');
        self::assertSame($ids, array_values(array_unique($ids)), 'Un implexe ne sert pas deux fois la même personne.');
        foreach (['basalte', 'ambre', 'quartz', 'opale'] as $cle) {
            self::assertContains($this->id($cle), $ids, $cle);
        }

        // L'adoption d'Émeri n'est pas remontée : la filiation de naissance passe d'abord.
        self::assertNotContains($this->id('gypse'), $ids);
        self::assertNull($this->familleServie($affiche, $this->unions['adoption']), 'La famille d’adoption ne sort pas : une seule filiation par ancêtre.');

        // La famille de l'implexe porte ses deux enfants servis, Émeri et Quartz.
        $parents = $this->familleServie($affiche, $this->unions['basalte']);
        self::assertIsArray($parents);
        self::assertEqualsCanonicalizing(
            [$this->idDe('aieul'), $this->id('quartz')],
            array_column($parents['enfants'], 'personId'),
        );
    }

    public function testLesEnfantsArriventDansLOrdreDeLAfficheEtLAutreArbreNExistePas(): void
    {
        $affiche = $this->affiche(CercleDuLecteur::de([])->avecHorsPerimetre([$this->id('peridot')]));

        $couple = $this->familleServie($affiche, $this->familles['aieux']);
        self::assertIsArray($couple);
        self::assertSame($this->familles['aieux']->getId(), $affiche['famille']);
        self::assertSame($this->idDe('aieul'), $couple['husband']);
        self::assertSame($this->idDe('aieule'), $couple['wife']);

        // Naissance, puis rang, puis identifiant — sur la vraie date : `ego` est vivant et
        // sa naissance sort masquée, il passerait sinon après l'enfant sans date.
        self::assertSame(
            [$this->id('sodalite'), $this->idDe('ego'), $this->id('tourmaline')],
            array_column($couple['enfants'], 'personId'),
        );
        self::assertNotContains($this->id('peridot'), array_column($affiche['personnes'], 'id'), 'Une personne d’un autre arbre n’est pas servie, même masquée.');
        self::assertStringNotContainsString('Péridot', json_encode($affiche, \JSON_THROW_ON_ERROR));
    }

    public function testLaDescendanceSArreteASixGenerations(): void
    {
        $affiche = $this->affiche(CercleDuLecteur::de([]));
        $ids = array_column($affiche['personnes'], 'id');

        self::assertSame(6, $affiche['generationsDeDescendance']);
        // Le nom du pied vient du réglage : le dépôt n'en porte aucun (ADR 0006).
        self::assertSame('Auteur Fictif', $affiche['signature']);
        // Sodalite est à la 1re génération, Jaspe à la 2e ; la chaîne descend de là.
        self::assertContains($this->id('chaine6'), $ids, 'La sixième génération est servie…');
        self::assertNotContains($this->id('conjoint6'), $ids, '… sans ses unions…');
        self::assertNotContains($this->id('chaine7'), $ids, '… ni la septième.');
        self::assertContains($this->id('conjoint5'), $ids, 'Les conjoints de la cinquième génération sont servis.');
    }

    public function testLesUnionsPortentLeMariageEtLeDivorce(): void
    {
        $affiche = $this->affiche(CercleDuLecteur::de([]));

        $premiere = $this->familleServie($affiche, $this->unions['sodaliteRubis']);
        self::assertIsArray($premiere);
        self::assertSame('1950', $premiere['marriage']['raw']);
        self::assertFalse($premiere['divorced']);
        self::assertNull($premiere['divorce']);

        $seconde = $this->familleServie($affiche, $this->unions['sodaliteAgate']);
        self::assertIsArray($seconde);
        self::assertTrue($seconde['divorced'], 'Le trait d’union dit comment l’union a fini : la charge porte le divorce.');
        self::assertIsArray($seconde['divorce']);
        self::assertSame('inconnue', $seconde['marriage']['qualifier']);
        self::assertSame([], $seconde['enfants']);
    }

    /**
     * La nature de l'union (ADR 0020), sous la règle de la fiche d'une famille : émise même
     * nulle, et toujours vraie sous une date de mariage. Un divorce sans mariage connu reste
     * de nature inconnue — c'est l'affiche qui en décide (`estMarie()`), pas la charge.
     */
    public function testLesUnionsDisentLeurNature(): void
    {
        // Basalte ∞ Ambre : mariés, l'acte manque ; Quartz & Opale : union libre.
        $this->unions['basalte']->setMarried(true);
        $this->unions['quartz']->setMarried(false);
        $this->em()->flush();

        $affiche = $this->affiche(CercleDuLecteur::de([]));
        $nature = function (string $cle) use ($affiche): mixed {
            $famille = $this->familleServie($affiche, $this->unions[$cle]) ?? self::fail($cle.' n’est pas servie.');
            self::assertArrayHasKey('married', $famille, $cle);

            return $famille['married'];
        };

        self::assertTrue($nature('sodaliteRubis'), 'Un mariage daté est un mariage.');
        self::assertTrue($nature('basalte'), 'Mariés sans date : l’acte manque, pas le mariage.');
        self::assertFalse($nature('quartz'), 'Une union libre.');
        self::assertNull($nature('sodaliteAgate'), '`null` est émis : divorcés ou séparés, on ne sait pas s’ils étaient mariés.');
    }

    /**
     * La liste blanche d'une personne masquée : **cinq clés**, et le nom nulle part. Elle
     * reste à sa place dans la famille, comme son nœud dans le graphe.
     */
    public function testUnVivantHorsCercleNExposeQueCinqClesEtResteASaPlace(): void
    {
        $affiche = $this->affiche(CercleDuLecteur::de([]));

        $ego = $this->personneServie($affiche, $this->idDe('ego'));
        self::assertSame(['id', 'visibilite', 'displayName', 'sex', 'vivant'], array_keys($ego));
        self::assertSame('restreinte', $ego['visibilite']);
        self::assertSame(CercleDeVisibiliteInterface::NOM_MASQUE, $ego['displayName']);
        self::assertTrue($ego['vivant']);

        $json = json_encode($affiche, \JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('Natron', $json);
        self::assertStringNotContainsString('2012', $json, 'Aucune date d’un vivant masqué, sous aucune clé.');

        $couple = $this->familleServie($affiche, $this->familles['aieux']);
        self::assertIsArray($couple);
        self::assertContains($this->idDe('ego'), array_column($couple['enfants'], 'personId'), 'Une fratrie amputée serait fausse.');
    }

    public function testUnDefuntSortEntierAvecSesPrenomsEtSesDates(): void
    {
        $affiche = $this->affiche(CercleDuLecteur::de([]));

        $aieule = $this->personneServie($affiche, $this->idDe('aieule'));
        self::assertSame(
            ['id', 'visibilite', 'givenNames', 'surname', 'sex', 'deceased', 'vivant', 'birth', 'death'],
            array_keys($aieule),
        );
        self::assertSame('Marne Dolomite', $aieule['givenNames']);
        self::assertSame('Sureau', $aieule['surname']);
        self::assertSame('BET 1901 AND 1909', $aieule['birth']['raw']);
        self::assertSame('1987', $aieule['death']['raw']);
        self::assertTrue($aieule['deceased']);
        self::assertFalse($aieule['vivant']);
    }

    /**
     * La réciprocité (ADR 0016) : l'année partagée, la naissance sort **réduite** — sans
     * `raw`, bornes élargies à l'année, comme la lisent les statistiques.
     */
    public function testUneNaissancePartageeALAnneeSortReduiteSansRaw(): void
    {
        $cercle = CercleDuLecteur::de([$this->idDe('ego')])->avecPartage([$this->idDe('ego') => new ComposantesDeDate(annee: true)]);
        $ego = $this->personneServie($this->affiche($cercle), $this->idDe('ego'));

        self::assertSame('complete', $ego['visibilite']);
        self::assertSame('Natron', $ego['givenNames']);
        self::assertNull($ego['birth']['raw']);
        self::assertSame('annee', $ego['birth']['qualifier']);
        self::assertSame('2012-01-01', $ego['birth']['min']);
        self::assertSame('2012-12-31', $ego['birth']['max']);
    }

    /** Rien de partagé : le bloc `masquee` de la fiche, et l'année nulle part. */
    public function testUneNaissanceNonPartageeNeSortParAucuneCle(): void
    {
        $cercle = CercleDuLecteur::de([$this->idDe('ego')])->avecPartage([$this->idDe('ego') => ComposantesDeDate::aucune()]);
        $affiche = $this->affiche($cercle);
        $ego = $this->personneServie($affiche, $this->idDe('ego'));

        self::assertSame('complete', $ego['visibilite'], 'Le cercle décide de la fiche ; la réciprocité ne fait que réduire la naissance.');
        self::assertSame('masquee', $ego['birth']['qualifier']);
        self::assertNull($ego['birth']['min']);
        self::assertNull($ego['birth']['max']);
        self::assertStringNotContainsString('2012', json_encode($affiche, \JSON_THROW_ON_ERROR));
    }

    /** La charge est close : aucun identifiant n'y désigne une personne qu'elle ne porte pas. */
    public function testAucunIdentifiantNeRenvoieHorsDeLaCharge(): void
    {
        $affiche = $this->affiche(CercleDuLecteur::de([])->avecHorsPerimetre([$this->id('peridot')]));
        $ids = array_column($affiche['personnes'], 'id');

        foreach ($affiche['familles'] as $famille) {
            foreach (['husband', 'wife'] as $cle) {
                if (null !== $famille[$cle]) {
                    self::assertContains($famille[$cle], $ids);
                }
            }
            foreach ($famille['enfants'] as $enfant) {
                self::assertContains($enfant['personId'], $ids);
            }
        }
    }

    // ── La route, traversée pour de vrai ─────────────────────────────────────────────

    public function testLaRouteEstDerriereLAuthentification(): void
    {
        $this->requete('/api/arbre/familles/'.$this->familles['aieux']->getId().'/affiche');

        self::assertSame(Response::HTTP_UNAUTHORIZED, $this->client->getResponse()->getStatusCode());
    }

    public function testUnMembreLitLAfficheDeSaFamilleEtPasCelleDUnAutreArbre(): void
    {
        try {
            $this->amorcerLaVueDuCercle();
            $this->rattacher('membre@heritage.test', 'ego');
            $this->connexion('membre@heritage.test');

            $this->requete('/api/arbre/familles/'.$this->familles['aieux']->getId().'/affiche');
            self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
            $charge = $this->charge();
            self::assertSame($this->familles['aieux']->getId(), $charge['famille']);
            self::assertContains($this->id('basalte'), array_column($charge['personnes'], 'id'));

            // Le couple « mixte » est dans un autre arbre, que tient un autre compte.
            $this->rattacher('ami@heritage.test', 'cousin');
            $this->requete('/api/arbre/familles/'.$this->familles['mixte']->getId().'/affiche');
            self::assertSame(Response::HTTP_NOT_FOUND, $this->client->getResponse()->getStatusCode());

            $this->requete('/api/arbre/familles/987654/affiche');
            self::assertSame(Response::HTTP_NOT_FOUND, $this->client->getResponse()->getStatusCode());
        } finally {
            ArbreEnMemoire::reinitialiser();
        }
    }

    // ─────────────────────────────────────────────────────────────────────────────────

    /** @return array{famille: int|null, generationsDeDescendance: int, signature: string|null, personnes: list<array<string, mixed>>, familles: list<array<string, mixed>>} */
    private function affiche(CercleDuLecteur $cercle): array
    {
        $lecture = self::getContainer()->get(LectureDeLArbre::class);
        \assert($lecture instanceof LectureDeLArbre);
        $vivants = self::getContainer()->get(EtatVivant::class);
        \assert($vivants instanceof EtatVivant);

        // Les entités semées sont détachées pour que la lecture reparte de la base, comme
        // une requête HTTP le ferait : sinon les collections en mémoire masqueraient une
        // jointure oubliée.
        $this->em()->clear();
        $couple = $lecture->famille((int) $this->familles['aieux']->getId());
        self::assertNotNull($couple);

        return (new NormaliseurDAffiche(new NormaliseurDeDate(), $lecture, new ReglagesFixes(110, null, 'Auteur Fictif')))->affiche($couple, $cercle, $vivants);
    }

    /**
     * @param array{personnes: list<array<string, mixed>>} $affiche
     *
     * @return array<string, mixed>
     */
    private function personneServie(array $affiche, int $id): array
    {
        foreach ($affiche['personnes'] as $personne) {
            if ($personne['id'] === $id) {
                return $personne;
            }
        }

        self::fail(\sprintf('La personne %d n’est pas servie.', $id));
    }

    /**
     * @param array{familles: list<array<string, mixed>>} $affiche
     *
     * @return array<string, mixed>|null
     */
    private function familleServie(array $affiche, Family $famille): ?array
    {
        foreach ($affiche['familles'] as $servie) {
            if ($servie['id'] === $famille->getId()) {
                return $servie;
            }
        }

        return null;
    }

    private function id(string $cle): int
    {
        $id = $this->ajoutees[$cle]->getId();
        self::assertNotNull($id);

        return $id;
    }

    private function semerLArbreDeLAffiche(): void
    {
        $em = $this->em();
        $aieul = $this->personnes['aieul'];
        $aieule = $this->personnes['aieule'];

        $p = fn (string $cle, string $prenoms, string $nom, Sex $sexe, ?int $naissance = null, ?int $deces = null) => $this->ajoutees[$cle] = $this->nouvelle($prenoms, $nom, $sexe, $naissance, $deces);

        $p('basalte', 'Basalte', 'Sarriette', Sex::Masculin, 1840, 1910);
        $p('ambre', 'Ambre', 'Lichen', Sex::Feminin, 1845, 1915);
        $p('gypse', 'Gypse', 'Orpin', Sex::Masculin, 1850, 1920);
        $p('quartz', 'Quartz', 'Sarriette', Sex::Masculin, 1870, 1940);
        $p('opale', 'Opale', 'Tilleul', Sex::Feminin, 1872, 1950);
        $p('sodalite', 'Sodalite', 'Sarriette', Sex::Feminin, 1930, 2001);
        $p('tourmaline', 'Tourmaline', 'Sarriette', Sex::Feminin, deces: 1999);
        $p('peridot', 'Péridot', 'Sarriette', Sex::Masculin, 1935, 2005);
        $p('rubis', 'Rubis', 'Fenouil', Sex::Masculin, 1925, 1960);
        $p('agate', 'Agate', 'Cerfeuil', Sex::Masculin, 1928, 1990);
        $p('jaspe', 'Jaspe', 'Sarriette', Sex::Masculin, 1952, 2010);

        foreach ($this->ajoutees as $personne) {
            $em->persist($personne);
        }

        $this->unions['adoption'] = new Family($this->ajoutees['gypse'], null);
        $this->unions['basalte'] = new Family($this->ajoutees['basalte'], $this->ajoutees['ambre']);
        $this->unions['quartz'] = new Family($this->ajoutees['quartz'], $this->ajoutees['opale']);
        $this->unions['sodaliteRubis'] = (new Family($this->ajoutees['rubis'], $this->ajoutees['sodalite']))
            ->setMarriage(new UncertainDate('1950', DateQualifier::Annee, new \DateTimeImmutable('1950-01-01'), new \DateTimeImmutable('1950-12-31')));
        $this->unions['sodaliteAgate'] = (new Family($this->ajoutees['agate'], $this->ajoutees['sodalite']))
            ->setDivorced(true);
        foreach ($this->unions as $famille) {
            $em->persist($famille);
        }

        // L'adoption est posée **avant** la naissance, sur une famille d'identifiant plus
        // petit : seule la règle « naissance d'abord » peut écarter Gypse.
        $em->persist(new Child($this->unions['adoption'], $aieul, 0, ChildRelation::Adoption));
        $em->persist(new Child($this->unions['basalte'], $aieul, 0));
        $em->persist(new Child($this->unions['basalte'], $this->ajoutees['quartz'], 1));
        $em->persist(new Child($this->unions['quartz'], $aieule, 0));

        // Les enfants du couple, rangs volontairement à rebours de l'ordre des naissances.
        $em->persist(new Child($this->familles['aieux'], $this->ajoutees['sodalite'], 3));
        $em->persist(new Child($this->familles['aieux'], $this->ajoutees['tourmaline'], 0));
        $em->persist(new Child($this->familles['aieux'], $this->ajoutees['peridot'], 4));
        $em->persist(new Child($this->unions['sodaliteRubis'], $this->ajoutees['jaspe'], 0));

        // La chaîne : Jaspe (2e génération) ∞ conjoint2 → chaine3 ∞ conjoint3 → … →
        // chaine6 ∞ conjoint6 → chaine7. Les dates n'ont pas à être vraisemblables, seulement
        // à faire de chacun un défunt.
        $parent = $this->ajoutees['jaspe'];
        for ($g = 3; $g <= 7; ++$g) {
            $conjoint = $this->ajoutees['conjoint'.($g - 1)] = $this->nouvelle('Conjoint', 'Génération'.($g - 1), Sex::Feminin, 1900 + 10 * $g, 1990 + $g);
            $enfant = $this->ajoutees['chaine'.$g] = $this->nouvelle('Calcite', 'Sarriette', Sex::Masculin, 1910 + 10 * $g, 2000 + $g);
            $em->persist($conjoint);
            $em->persist($enfant);
            $union = new Family($parent, $conjoint);
            $em->persist($union);
            $em->persist(new Child($union, $enfant, 0));
            $parent = $enfant;
        }

        $em->flush();
    }

    private function nouvelle(string $prenoms, string $nom, Sex $sexe, ?int $naissance = null, ?int $deces = null): Person
    {
        $an = static fn (int $a): UncertainDate => new UncertainDate((string) $a, DateQualifier::Annee, new \DateTimeImmutable($a.'-01-01'), new \DateTimeImmutable($a.'-12-31'));

        return (new Person($prenoms.' '.$nom))
            ->setGivenNames($prenoms)
            ->setSurname($nom)
            ->setSearchKey(mb_strtoupper($nom.' '.$prenoms))
            ->setSex($sexe)
            ->setGeneration(-1)
            ->setBranch(Branch::Pp)
            ->setDeceased(null !== $deces)
            ->setBirth(null !== $naissance ? $an($naissance) : UncertainDate::inconnue())
            ->setDeath(null !== $deces ? $an($deces) : UncertainDate::inconnue());
    }

    /** La vue de `Membership` : le socle et ce test, deux arbres sans lien. */
    private function amorcerLaVueDuCercle(): void
    {
        ArbreEnMemoire::reinitialiser();
        foreach (['ego' => true, 'conjointe' => true, 'cousin' => true, 'aieule' => false, 'aieul' => false, 'sansDate' => false] as $cle => $vivante) {
            ArbreEnMemoire::ajouterPersonne($this->idDe($cle), $cle, '', vivante: $vivante);
        }
        foreach (array_keys($this->ajoutees) as $cle) {
            ArbreEnMemoire::ajouterPersonne($this->id($cle), $cle, '', vivante: false);
        }
        ArbreEnMemoire::ajouterFamille([$this->idDe('aieul'), $this->idDe('aieule')], [$this->idDe('ego'), $this->id('sodalite'), $this->id('tourmaline'), $this->id('peridot')]);
        ArbreEnMemoire::ajouterFamille([$this->id('basalte'), $this->id('ambre')], [$this->idDe('aieul'), $this->id('quartz')]);
        ArbreEnMemoire::ajouterFamille([$this->id('quartz'), $this->id('opale')], [$this->idDe('aieule')]);
        ArbreEnMemoire::ajouterFamille([$this->idDe('cousin'), $this->idDe('conjointe')], [$this->idDe('sansDate')]);
    }

    private function rattacher(string $courriel, string $personne): void
    {
        $utilisateur = $this->creerUtilisateur($courriel);
        $identifiant = $utilisateur->getId();
        self::assertNotNull($identifiant);

        $membre = new Member($identifiant, $courriel, $this->idDe($personne), $this->personnes[$personne]->getDisplayName(), MatchKind::PreLie);
        $membre->activer();
        $this->em()->persist($membre);
        $this->em()->flush();
    }

    private function connexion(string $courriel): void
    {
        $this->client->request('POST', '/api/connexion', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], content: json_encode(['email' => $courriel, 'password' => 'motdepasse-de-test'], \JSON_THROW_ON_ERROR));

        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode(), 'La connexion de test doit réussir.');
    }
}
