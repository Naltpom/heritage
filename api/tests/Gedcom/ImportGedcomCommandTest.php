<?php

declare(strict_types=1);

namespace App\Tests\Gedcom;

use App\Module\Core\Entity\Setting;
use App\Module\Core\Enum\SettingType;
use App\Module\Gedcom\Entity\Issue;
use App\Module\Gedcom\Entity\Run;
use App\Module\Gedcom\Enum\RunStatus;
use App\Module\Tree\Entity\Child;
use App\Module\Tree\Entity\Citation;
use App\Module\Tree\Entity\Fact;
use App\Module\Tree\Entity\Family;
use App\Module\Tree\Entity\Note;
use App\Module\Tree\Entity\Person;
use App\Module\Tree\Entity\Source;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * La commande de bout en bout, sur la fixture et sur une vraie base.
 *
 * **Ce que ce test attrape et qu'aucun test unitaire ne peut voir** : une entité qu'on a
 * oublié de `persist()`, une colonne trop courte, un ordre d'écriture qui viole une clé
 * étrangère, une purge de ré-import qui efface trop ou trop peu. C'est aussi le seul
 * endroit où le **recomptage depuis la base** est exercé pour ce qu'il est — relire ce
 * qu'on a écrit, et non se relire soi-même.
 *
 * La base est une SQLite **en mémoire**, bâtie par `SchemaTool` : la CI ne monte aucun
 * service de base de données (voir `api/.env.test` et `.github/workflows/ci.yml`).
 *
 * **Ce que ce test ne peut donc pas voir**, et qu'il faut savoir : `SchemaTool` ne pose
 * que ce que le mapping Doctrine exprime. Les contraintes `CHECK` de la migration — dont
 * celle qui refuse une fourchette dégénérée en point — et les index partiels n'existent
 * pas ici. Ils ne sont éprouvés que contre PostgreSQL, à l'exécution réelle de la
 * commande.
 */
final class ImportGedcomCommandTest extends KernelTestCase
{
    /**
     * Une base **en mémoire**, neuve à chaque test.
     *
     * Le fichier SQLite partagé de `.env.test` ne convient pas ici, et deux tentatives
     * l'ont montré plutôt qu'un raisonnement : ce test doit repartir d'une base vide à
     * chaque méthode, or un fichier garde ce que la méthode précédente y a laissé ; et
     * tant que le descripteur PDO du noyau précédent n'est pas relâché — ce que Windows
     * ne pardonne pas —, deux connexions coexistent sur le même fichier et se renvoient
     * « table déjà existante » d'un côté, « database is locked » de l'autre.
     *
     * `:memory:` n'a ni fichier, ni verrou, ni reste : la connexion naît vide et meurt
     * avec le noyau. C'est aussi beaucoup plus rapide.
     */
    private const string BASE = 'sqlite:///:memory:';

    private ?string $baseDOrigine = null;

    protected function setUp(): void
    {
        $this->baseDOrigine = \is_string($_ENV['DATABASE_URL'] ?? null) ? $_ENV['DATABASE_URL'] : null;
        $_ENV['DATABASE_URL'] = $_SERVER['DATABASE_URL'] = self::BASE;

        self::bootKernel();

        $em = $this->em();

        (new SchemaTool($em))->createSchema($em->getMetadataFactory()->getAllMetadata());

        // Le jeu de réglages est posé par la migration initiale, que `SchemaTool` ne
        // rejoue pas : on pose ici la seule clé que l'import doit renseigner.
        $em->persist(new Setting(
            'tree.personne_racine_id',
            '0',
            SettingType::Entier,
            'Personne racine de l’arbre',
        ));
        $em->flush();
        $em->clear();
    }

    /**
     * On rend `DATABASE_URL` telle qu'on l'a trouvée.
     *
     * Les variables d'environnement sont un état partagé par tout le processus PHPUnit :
     * sans cette restitution, toutes les suites exécutées après celle-ci travailleraient
     * sur *notre* base — celle qu'on efface à chaque `setUp`. Le genre de dégât qui se
     * manifeste très loin de sa cause.
     */
    protected function tearDown(): void
    {
        // On relâche le descripteur avant d'éteindre le noyau : arrêter le noyau libère
        // le conteneur, pas la connexion qu'il tenait. Sur une base en mémoire c'est ce
        // qui la fait disparaître pour de bon.
        if (null !== self::$kernel) {
            $this->em()->getConnection()->close();
        }

        self::ensureKernelShutdown();

        if (null === $this->baseDOrigine) {
            unset($_ENV['DATABASE_URL'], $_SERVER['DATABASE_URL']);
        } else {
            $_ENV['DATABASE_URL'] = $_SERVER['DATABASE_URL'] = $this->baseDOrigine;
        }

        parent::tearDown();
    }

    /**
     * Le gestionnaire est **redemandé au conteneur** à chaque usage, jamais gardé.
     *
     * L'import annulé referme le sien et demande un gestionnaire neuf au registre pour
     * écrire son journal ; une référence gardée en propriété pointerait alors sur un
     * gestionnaire fermé, et les vérifications d'après-annulation échoueraient pour une
     * raison qui n'a rien à voir avec ce qu'elles vérifient.
     */
    private function em(): EntityManagerInterface
    {
        $registre = self::getContainer()->get('doctrine');
        self::assertInstanceOf(\Doctrine\Persistence\ManagerRegistry::class, $registre);

        $em = $registre->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }

    public function testLImportEcritLArbreEtLesTotauxTombentJuste(): void
    {
        $commande = $this->lancer();

        self::assertSame(0, $commande->getStatusCode(), $commande->getDisplay());
        self::assertStringContainsString('Import conforme', $commande->getDisplay());

        foreach ([
            Person::class => 11,
            Family::class => 5,
            Child::class => 7,
            Source::class => 2,
            Citation::class => 5,
            Note::class => 8,
            Fact::class => 3,
        ] as $entite => $attendu) {
            self::assertSame($attendu, $this->compter($entite), \sprintf('Nombre de %s après import.', $entite));
        }
    }

    /**
     * Le tableau du rapport affiche bien **attendu contre obtenu**, ligne par ligne :
     * c'est le livrable de la commande, pas un détail de présentation.
     */
    public function testLeRapportAfficheLesDeuxColonnes(): void
    {
        $affichage = $this->lancer()->getDisplay();

        self::assertStringContainsString('attendu', $affichage);
        self::assertStringContainsString('obtenu', $affichage);
        self::assertMatchesRegularExpression('/personnes\s+11\s+11\s+ok/u', $affichage);
        self::assertMatchesRegularExpression('/générations\s+4\s+4\s+ok/u', $affichage);
        self::assertMatchesRegularExpression('/citations\s+5\s+5\s+ok/u', $affichage);
    }

    /**
     * **Le contrôle contrôle vraiment.** Un total attendu faux doit faire échouer la
     * commande *et* n'écrire aucune ligne d'arbre : une base à moitié peuplée est pire
     * qu'une base vide, parce qu'elle se découvre six mois plus tard.
     */
    public function testUnEcartDeDenombrementAnnuleToutLImport(): void
    {
        $commande = $this->lancer(['personnes' => 999]);

        self::assertSame(1, $commande->getStatusCode());
        self::assertStringContainsString('dénombrements ne retombent pas', $commande->getDisplay());
        self::assertSame(0, $this->compter(Person::class), 'Aucune personne ne doit subsister après annulation.');
        self::assertSame(0, $this->compter(Family::class));
        self::assertSame(0, $this->compter(Citation::class));
    }

    /**
     * Le journal survit à l'annulation : `ged_run` et `ged_issue` sont hors transaction,
     * parce qu'un import qui échoue doit laisser de quoi comprendre pourquoi.
     */
    public function testLeJournalSurvitALAnnulation(): void
    {
        $this->lancer(['personnes' => 999]);
        $this->em()->clear();

        $executions = $this->em()->getRepository(Run::class)->findAll();

        self::assertCount(1, $executions);
        self::assertSame(RunStatus::Echoue, $executions[0]->getStatus());
        self::assertStringContainsString('attendu 999, obtenu 11', (string) $executions[0]->getError());
        self::assertGreaterThan(0, $this->compter(Issue::class), 'Les anomalies doivent être écrites malgré l’échec.');
    }

    /**
     * **L'idempotence du ré-import** (§ 9.8) : les personnes, familles et sources sont
     * retrouvées par leur `gedcom_xref`, et les lignes sans identité propre sont
     * effacées puis réécrites. Sans cela, un second import doublerait les citations.
     */
    public function testUnSecondImportNeCreeAucunDoublon(): void
    {
        $this->lancer();
        $identifiants = $this->identifiantsParXref();

        $second = $this->lancer();

        self::assertSame(0, $second->getStatusCode(), $second->getDisplay());
        self::assertSame(11, $this->compter(Person::class));
        self::assertSame(5, $this->compter(Family::class));
        self::assertSame(5, $this->compter(Citation::class));
        self::assertSame(8, $this->compter(Note::class));
        self::assertSame(7, $this->compter(Child::class));
        self::assertSame(3, $this->compter(Fact::class));
        self::assertSame($identifiants, $this->identifiantsParXref(), 'Les lignes doivent être mises à jour, pas recréées.');
    }

    /**
     * Une note écrite **dans l'application** n'appartient pas au GEDCOM : le ré-import
     * ne doit pas l'emporter avec les notes importées.
     */
    public function testLeReImportEpargneLesNotesEcritesDansLApplication(): void
    {
        $this->lancer();
        $this->em()->clear();

        $personne = $this->em()->getRepository(Person::class)->findOneBy(['gedcomXref' => '@I1@']);
        self::assertInstanceOf(Person::class, $personne);

        $this->em()->persist(
            (new Note('Remarque saisie par un membre.'))->setPerson($personne)->setAuthor(42, 'Un membre'),
        );
        $this->em()->flush();
        $this->em()->clear();

        self::assertSame(9, $this->compter(Note::class));

        $this->lancer(['notes' => 9]);

        self::assertSame(9, $this->compter(Note::class), 'La note du membre doit survivre au ré-import.');
    }

    /** `--dry-run` lit, compte, signale — et n'écrit rien dans l'arbre. */
    public function testLExecutionABlancNEcritAucuneLigneDArbre(): void
    {
        $commande = $this->lancer([], ['--dry-run' => true]);

        self::assertSame(0, $commande->getStatusCode(), $commande->getDisplay());
        self::assertStringContainsString('Lecture à blanc conforme', $commande->getDisplay());
        self::assertSame(0, $this->compter(Person::class));
        self::assertSame(0, $this->compter(Family::class));
        // Le journal, lui, est écrit : `ged_run.dry_run` existe pour ça.
        self::assertSame(1, $this->compter(Run::class));
        self::assertSame('0', $this->racine(), 'La personne racine ne doit pas être posée à blanc.');
    }

    /** L'import pose `tree.personne_racine_id`, que la migration laisse à 0. */
    public function testLImportPoseLaPersonneRacine(): void
    {
        $this->lancer();
        $this->em()->clear();

        $ego = $this->em()->getRepository(Person::class)->findOneBy(['gedcomXref' => '@I1@']);
        self::assertInstanceOf(Person::class, $ego);
        self::assertSame((string) $ego->getId(), $this->racine());
    }

    /**
     * Sans jeu de totaux de référence, la commande **réussit en le disant**. Elle ne
     * prétend pas contrôler ce qu'elle n'a pas recompté : l'atelier contient onze
     * versions antérieures du GEDCOM, et aucune ne fait foi.
     */
    public function testSansTotauxDeReferenceLaCommandeLeDit(): void
    {
        $commande = $this->lancerSansControle();

        self::assertSame(0, $commande->getStatusCode());
        self::assertStringContainsString('pas de jeu de totaux de référence', $commande->getDisplay());
    }

    public function testUnFichierIntrouvableEchoueProprement(): void
    {
        $commande = $this->tester();
        $commande->execute(['fichier' => __DIR__.'/fixtures/inexistant.ged']);

        self::assertSame(1, $commande->getStatusCode());
        self::assertStringContainsString('introuvable', $commande->getDisplay());
        self::assertSame(0, $this->compter(Person::class));
    }

    /**
     * Lance la commande sur la fixture, avec les totaux de contrôle de la fixture.
     *
     * @param array<string, int>  $surcharges totaux à remplacer — c'est ainsi qu'on
     *                                        fabrique un écart pour vérifier qu'il annule tout
     * @param array<string, bool> $options    options de la commande, `--dry-run` notamment
     */
    private function lancer(array $surcharges = [], array $options = []): CommandTester
    {
        $totaux = $surcharges + AnalyseurGedcomTest::totauxDeLaFixture();

        return $this->executer($options + ['--attendus' => implode(',', array_map(
            static fn (string $cle, int $valeur): string => $cle.'='.$valeur,
            array_keys($totaux),
            array_values($totaux),
        ))]);
    }

    /** Sans `--attendus` : le cas d'un fichier qui n'a pas de jeu de totaux de référence. */
    private function lancerSansControle(): CommandTester
    {
        return $this->executer([]);
    }

    /**
     * @param array<string, bool|string> $options
     */
    private function executer(array $options): CommandTester
    {
        $commande = $this->tester();
        $commande->execute(['fichier' => __DIR__.'/fixtures/arbre-essai.ged', '--ego' => '@I1@'] + $options);
        $this->em()->clear();

        return $commande;
    }

    private function tester(): CommandTester
    {
        $application = new Application(self::$kernel ?? self::bootKernel());

        return new CommandTester($application->find('heritage:import-gedcom'));
    }

    /**
     * @param class-string $entite
     */
    private function compter(string $entite): int
    {
        $resultat = $this->em()
            ->createQuery(\sprintf('SELECT COUNT(e.id) FROM %s e', $entite))
            ->getSingleScalarResult();

        return \is_numeric($resultat) ? (int) $resultat : 0;
    }

    /** @return array<string, int> */
    private function identifiantsParXref(): array
    {
        $this->em()->clear();
        $identifiants = [];

        foreach ($this->em()->getRepository(Person::class)->findAll() as $personne) {
            $identifiants[(string) $personne->getGedcomXref()] = (int) $personne->getId();
        }

        ksort($identifiants);

        return $identifiants;
    }

    private function racine(): string
    {
        $this->em()->clear();
        $reglage = $this->em()->getRepository(Setting::class)->findOneBy(['settingKey' => 'tree.personne_racine_id']);
        self::assertInstanceOf(Setting::class, $reglage);

        return $reglage->getValue();
    }
}
