<?php

declare(strict_types=1);

namespace App\Tests\Tree;

use App\Module\Tree\Entity\Place;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `heritage:resoudre-les-lieux`, éprouvée **de bout en bout sur une vraie base**.
 *
 * Ce que ce fichier garde et que `AppariementDesLieuxTest` ne peut pas garder : la requête
 * qui collecte les chaînes `PLAC`, l'écriture dans `tree_place`, l'idempotence, la
 * préservation des corrections manuelles, et le code de sortie.
 *
 * ## L'arbre de ces cas
 *
 * Celui de {@see ArbreWebTestCase}, qui porte exactement trois chaînes `PLAC` distinctes
 * dans cinq colonnes réparties sur trois tables :
 *
 * | chaîne | d'où elle vient | occurrences |
 * |---|---|---:|
 * | `Saule, Ombrage, Sylvanie` | naissance de l'aïeule **et** mariage des aïeux | **2** |
 * | `Charmille, Ombrage, Sylvanie` | décès de l'aïeule | 1 |
 * | `Sorbière, Fougerie, Florie` | naissance du cousin | 1 |
 *
 * Le référentiel de test connaît les deux premières et **ignore la troisième** : c'est ce
 * qui permet d'éprouver dans le même geste ce qui se résout et ce qui ne se résout pas.
 *
 * Les subdivisions de ces chaînes (`Ombrage`, `Sylvanie`) sont fictives et n'existent dans
 * aucune table de départements : les passes A et B échouent donc, et c'est la passe C —
 * commune française unique, confiance 70 — qui répond. C'est voulu : la passe la plus
 * fréquente sur un GEDCOM dont les lieux ne sont pas normalisés.
 */
final class ResolutionDesLieuxTest extends ArbreWebTestCase
{
    private string $dossier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dossier = \dirname(__DIR__, 2).'/var/gazetteer_cmd_'.getmypid();

        if (!is_dir($this->dossier) && !mkdir($this->dossier, 0777, true) && !is_dir($this->dossier)) {
            self::fail('Impossible de créer le dossier de fixtures : '.$this->dossier);
        }

        $this->poserLeReferentiel([
            'Saule;18;24;46.12;2.56;540;0',
            'Charmille;58;27;47.03;3.11;820;0',
        ]);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dossier.'/*') ?: [] as $fichier) {
            @unlink($fichier);
        }

        @rmdir($this->dossier);

        parent::tearDown();
    }

    /**
     * **La requête de collecte tourne sur SQLite**.
     *
     * Elle est écrite en DBAL parce que **DQL ne sait pas faire d'`UNION`** : les cinq
     * colonnes vivent dans trois entités et ne sont pas des associations. Écrire du SQL à la
     * main, c'est prendre le risque d'un dialecte — et le risque a un nom ici : `btrim()`,
     * qui est **propre à PostgreSQL**. Une commande écrite avec lui échouerait avec « no
     * such function » sur la machine de qui l'écrit, où toute la suite tourne sur SQLite, et
     * ne serait donc **jamais éprouvée avant la production**.
     *
     * Le cas vérifie aussi le comptage : `Saule` apparaît dans deux colonnes de deux tables
     * différentes et doit compter **2**. C'est ce qui exige un `UNION ALL` suivi d'un
     * `GROUP BY` plutôt qu'un `UNION` simple, lequel dédoublonne avant de compter et
     * ramènerait tout à 1.
     */
    public function testLaRequeteDesLieuxDistinctsTourneSurSqlite(): void
    {
        $testeur = $this->lancer();

        self::assertSame(Command::SUCCESS, $testeur->getStatusCode(), $testeur->getDisplay());

        $lignes = $this->lignesParLibelle();

        self::assertCount(3, $lignes, 'Trois chaînes PLAC distinctes dans cinq colonnes de trois tables.');
        self::assertSame(2, $lignes['Saule (18)']->getOccurrences(), 'Saule vient d’une naissance ET d’un mariage.');
        self::assertSame(1, $lignes['Charmille (58)']->getOccurrences());
        self::assertSame(1, $lignes['Sorbière']->getOccurrences(), 'Un lieu non résolu porte quand même son compte.');
    }

    /**
     * Deux nombres, toujours : ce qui est résolu et ce qui ne l'est pas.
     *
     * Un rapport qui n'annoncerait que les succès laisserait croire qu'une carte est
     * complète alors qu'il lui manque des bulles. **Un lieu non résolu n'est pas un
     * échec** : la commande sort `SUCCESS`, et elle le dit.
     */
    public function testLaCommandeAnnonceCeQuElleAResoluEtCeQuElleNAPasResolu(): void
    {
        $testeur = $this->lancer();
        $lignes = $this->lignesParLibelle();

        self::assertSame(46.12, $lignes['Saule (18)']->getLat());
        self::assertSame(70, $lignes['Saule (18)']->getConfiance(), 'Passe C : commune française unique.');
        self::assertSame(Place::SOURCE_COMMUNES, $lignes['Saule (18)']->getSource());

        self::assertNull($lignes['Sorbière']->getLat(), 'Absente du référentiel : rien n’est inventé.');
        self::assertSame(0, $lignes['Sorbière']->getConfiance());
        self::assertSame(Place::SOURCE_AUCUNE, $lignes['Sorbière']->getSource());

        // Le lieu non résolu est **nommé** dans la sortie, avec sa raison : c'est ce qui
        // rend la correction possible, et c'est l'ordre de relecture qui compte.
        self::assertStringContainsString('Sorbière', $testeur->getDisplay());
        self::assertStringContainsString('aucun rapprochement', $testeur->getDisplay());
    }

    /**
     * ═══════════════════════════════════════════════════════════════════════════
     *  UNE CORRECTION MANUELLE SURVIT À UNE RELANCE — Y COMPRIS AVEC `--forcer`.
     * ═══════════════════════════════════════════════════════════════════════════.
     *
     * Ce n'est pas une politesse envers l'exploitant. **La vraie base vit sur le VPS et le
     * dépôt Git ne porte aucune donnée familiale** : une correction faite à la main n'a
     * *nulle part ailleurs où exister*. L'écraser, c'est la perdre définitivement — et la
     * perdre au moment précis où l'on croyait améliorer les choses en régénérant le
     * référentiel.
     *
     * Le cas la pose sur un lieu que la commande **saurait** résoudre autrement : sans la
     * protection, `--forcer` y réécrirait les coordonnées du référentiel, et le test
     * verrait la différence.
     */
    public function testUneCorrectionManuelleSurvitAUneRelance(): void
    {
        $this->lancer();

        $saule = $this->lignesParLibelle()['Saule (18)'];
        $saule
            ->setLibelle('Saule-sur-Ombrage, corrigé à la main')
            ->poserLesCoordonnees(1.5, -2.5)
            ->setSource(Place::SOURCE_MANUEL)
            ->setConfiance(100);

        $this->em()->flush();
        $this->em()->clear();

        $testeur = $this->lancer(['--forcer' => true]);
        self::assertSame(Command::SUCCESS, $testeur->getStatusCode(), $testeur->getDisplay());

        $apres = $this->lignesParLibelle();

        self::assertArrayHasKey('Saule-sur-Ombrage, corrigé à la main', $apres, 'Le libellé manuel est intact.');

        $manuel = $apres['Saule-sur-Ombrage, corrigé à la main'];
        self::assertSame(1.5, $manuel->getLat(), '--forcer ne réécrit pas une correction manuelle.');
        self::assertSame(-2.5, $manuel->getLon());
        self::assertSame(Place::SOURCE_MANUEL, $manuel->getSource());

        // Et le reste a bien été réapparié — sans quoi ce cas serait vert parce que
        // `--forcer` ne ferait rien du tout.
        self::assertSame(47.03, $apres['Charmille (58)']->getLat());
    }

    /**
     * **Idempotente** : rejouée, elle ne réécrit rien.
     *
     * La preuve est prise par `resolu_le`. On le recule à l'an 2000 en SQL après la première
     * exécution ; s'il n'a pas bougé après la seconde, c'est qu'aucune ligne n'a été
     * réécrite. Comparer les valeurs métier ne prouverait rien — elles seraient identiques
     * même si chaque ligne avait été réécrite à l'identique.
     *
     * `tree_place` ne porte pas de `#[ORM\Version]` et n'est la cible d'aucune demande :
     * réécrire ne périmerait rien ici. L'enjeu est la **lisibilité du rapport** — un « 0
     * ligne écrite » qui dit la vérité vaut mieux qu'un « 3 lignes écrites » qui ne dit rien.
     */
    public function testLaCommandeEstIdempotente(): void
    {
        $this->lancer();

        $this->em()->getConnection()->executeStatement("UPDATE tree_place SET resolu_le = '2000-01-01 00:00:00'");
        $this->em()->clear();

        $testeur = $this->lancer();
        self::assertSame(Command::SUCCESS, $testeur->getStatusCode(), $testeur->getDisplay());

        $this->em()->clear();

        foreach ($this->lignesParLibelle() as $libelle => $ligne) {
            self::assertSame(
                '2000-01-01',
                $ligne->getResoluLe()->format('Y-m-d'),
                \sprintf('La ligne « %s » a été réécrite alors que rien n’avait changé.', $libelle),
            );
        }
    }

    /**
     * `--dry-run` n'écrit **rien**, pas même la première fois.
     *
     * C'est ce qui permet de regarder le rapport avant de décider — le même geste que
     * `heritage:recalculer-les-cles-de-recherche` offre, et pour la même raison : on lit le
     * compte avant de confirmer.
     */
    public function testDryRunNEcritAucuneLigne(): void
    {
        $testeur = $this->lancer(['--dry-run' => true]);

        self::assertSame(Command::SUCCESS, $testeur->getStatusCode(), $testeur->getDisplay());

        $this->em()->clear();

        self::assertSame(
            [],
            $this->em()->getRepository(Place::class)->findAll(),
            'Aucune ligne ne doit avoir été écrite.',
        );
    }

    /**
     * **Le seul cas rouge** : des lieux existent, et aucun n'est résolu — ni maintenant, ni
     * avant.
     *
     * Ce n'est pas un arbre pauvre en lieux : c'est un outil cassé — mauvais fichier,
     * référentiel tronqué, mauvais séparateur. Le distinguer importe, parce qu'un
     * « 0 résolu » présenté proprement dans un tableau vert est exactement le genre de panne
     * qu'on déploie sans la voir.
     */
    public function testUnReferentielQuiNeReconnaitRienFaitSortirEnErreur(): void
    {
        $this->poserLeReferentiel(['Zzz-la-Vallée;99;99;0.0;0.0;10;0']);

        $testeur = $this->lancer();

        self::assertSame(Command::FAILURE, $testeur->getStatusCode(), 'Trois lieux trouvés, zéro résolu : c’est rouge.');
        self::assertStringContainsString('AUCUN résolu', $testeur->getDisplay());
    }

    /**
     * **Et il n'écrit rien** — y compris avec `--forcer`, y compris sur des lignes déjà
     * résolues.
     *
     * `charger()` refuse un fichier vide ou au mauvais séparateur : celui-là est bien formé,
     * il est seulement **hors sujet** — le référentiel d'un autre pays, des colonnes dans le
     * mauvais ordre. Rien ne l'arrête à la lecture. Si l'échec n'était constaté qu'après le
     * `flush`, `--forcer` aurait effacé les coordonnées de **toutes** les lignes avant que
     * quiconque ait pu lire le rapport — et la vraie base n'existe que sur le VPS.
     *
     * On décide donc d'échouer **avant** de vider, pas après.
     */
    public function testUnReferentielHorsSujetNEffacePasLesResolutionsExistantes(): void
    {
        $this->lancer();
        $this->em()->clear();

        self::assertSame(46.12, $this->lignesParLibelle()['Saule (18)']->getLat(), 'Point de départ : Saule est résolue.');

        $this->poserLeReferentiel(['Zzz-la-Vallée;99;99;0.0;0.0;10;0']);
        $testeur = $this->lancer(['--forcer' => true]);

        self::assertSame(Command::FAILURE, $testeur->getStatusCode());
        self::assertStringContainsString('RIEN N’A ÉTÉ ÉCRIT', $testeur->getDisplay());

        $this->em()->clear();

        self::assertSame(
            46.12,
            $this->lignesParLibelle()['Saule (18)']->getLat(),
            '--forcer sur un référentiel hors sujet ne doit pas vider ce qui était résolu.',
        );
    }

    /**
     * @param array<string, bool> $options
     */
    private function lancer(array $options = []): CommandTester
    {
        self::assertNotNull(self::$kernel, 'Le noyau doit être démarré par le socle.');

        $application = new Application(self::$kernel);
        $testeur = new CommandTester($application->find('heritage:resoudre-les-lieux'));

        $testeur->execute([
            '--fichier-communes' => $this->dossier.'/communes-fr.csv',
            '--fichier-villes' => $this->dossier.'/villes-monde.csv',
            ...$options,
        ]);

        return $testeur;
    }

    /** @return array<string, Place> */
    private function lignesParLibelle(): array
    {
        $lignes = [];

        foreach ($this->em()->getRepository(Place::class)->findAll() as $ligne) {
            $lignes[$ligne->getLibelle()] = $ligne;
        }

        return $lignes;
    }

    /**
     * Le référentiel de test, au **format réel** : séparateur `;`, en-tête de provenance
     * en `#`, colonnes dans l'ordre du fichier livré.
     *
     * @param list<string> $communes
     */
    private function poserLeReferentiel(array $communes): void
    {
        file_put_contents(
            $this->dossier.'/communes-fr.csv',
            "# nom;departement;region;lat;lon;population;rang\n".implode("\n", $communes)."\n",
        );
        file_put_contents($this->dossier.'/villes-monde.csv', "# nom;pays_iso2;lat;lon;population\nOran;DZ;35.69;-0.64;850000\n");
        file_put_contents($this->dossier.'/villes-canada.csv', "# nom;pays_iso2;lat;lon;population\nTrochu;CA;51.83;-113.21;1000\n");
        file_put_contents($this->dossier.'/departements.csv', "# code;nom\n18;Cher\n58;Nièvre\n99;Zzz\n");
        file_put_contents($this->dossier.'/regions.csv', "# code;nom\n24;Centre-Val de Loire\n27;Bourgogne-Franche-Comté\n99;Zzz\n");
    }
}
