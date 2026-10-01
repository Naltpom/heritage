<?php

declare(strict_types=1);

namespace App\Tests\Tree;

use App\Module\Core\Entity\Setting;
use App\Module\Core\Entity\User;
use App\Module\Core\Enum\Branch;
use App\Module\Core\Enum\DateQualifier;
use App\Module\Core\Enum\SettingType;
use App\Module\Core\Enum\Sex;
use App\Module\Core\Enum\UserStatus;
use App\Module\Core\Service\CleDeRecherche;
use App\Module\Core\ValueObject\UncertainDate;
use App\Module\Membership\Entity\Member;
use App\Module\Membership\Enum\MatchKind;
use App\Module\Tree\Entity\Child;
use App\Module\Tree\Entity\Citation;
use App\Module\Tree\Entity\Family;
use App\Module\Tree\Entity\Note;
use App\Module\Tree\Entity\Person;
use App\Module\Tree\Entity\Source;
use App\Module\Tree\Enum\NoteScope;
use App\Module\Tree\Enum\SourceKind;
use App\Module\Tree\Service\ReglePersonneVivante;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Socle des tests de lecture de l'arbre, et **le petit arbre sur lequel ils portent**.
 *
 * **Sur SQLite, et sans conteneur**, comme `tests/Core` : `.env.test` pointe sur un
 * fichier, la CI ne monte aucune base, et le schéma est reconstruit depuis le mapping
 * Doctrine plutôt que par la migration, qui est écrite en PostgreSQL.
 *
 * > **Une base par processus.** `vendor/bin/phpunit` sans argument exécute toute la
 * > suite, et pendant le développement plusieurs agents lancent leurs tests en même
 * > temps sur la machine. Deux processus qui reconstruisent le même fichier SQLite
 * > produisent des erreurs spectaculaires et trompeuses — « table déjà existante » ici,
 * > « no such table » là, des tests qui changent d'avis à code identique. Le numéro de
 * > processus dans le nom du fichier suffit. C'est la même parade que `CoreWebTestCase`,
 * > et elle est recopiée plutôt que partagée parce que les deux modules ne doivent pas
 * > se donner rendez-vous dans un socle commun.
 *
 * ## L'arbre de test, et pourquoi il ressemble à celui-là
 *
 * Six personnes, choisies pour couvrir exactement les cas que la confidentialité doit
 * trancher — pas pour être un arbre vraisemblable :
 *
 * | | qui | état | pourquoi elle est là |
 * |---|---|---|---|
 * | `ego` | Natron Romarin | **vivant**, né 2012 | le lecteur lui-même |
 * | `conjointe` | Cobalt Romarin | **vivante**, née 2014 | le cas « conjointe dans le cercle » |
 * | `cousin` | Pyroxène Tilleul | **vivant**, né vers 1934 | **le cas central** : vivant hors cercle |
 * | `aieule` | Marne Dolomite Sureau | décédée 1987 | le fait historique, visible de tous |
 * | `aieul` | Émeri Sarriette | décédé 1980 | l'époux de l'aïeule, pour la fiche de famille |
 * | `sansDate` | Lapis Fenouil | ni décès ni date, génération −8 | **le repli par génération** du § 9.2 |
 *
 * `sansDate` mérite un mot : sans le repli par médiane, la règle naïve le classerait
 * vivant parce qu'elle ne sait rien de lui — c'est le cas qui produit 27 faux positifs
 * sur les données réelles, et c'est celui qu'un jeu d'essai « propre » oublie toujours.
 */
abstract class ArbreWebTestCase extends WebTestCase
{
    /** L'ancre de {@see creerLecteurHorsCercle()} : aucune fiche de l'arbre ne la porte. */
    protected const int FICHE_HORS_DE_L_ARBRE = 987654321;

    protected KernelBrowser $client;

    /** @var array<string, Person> */
    protected array $personnes = [];

    /** @var array<string, Family> */
    protected array $familles = [];

    protected function setUp(): void
    {
        $this->isolerLaBaseDuModule();

        $this->client = static::createClient();

        $this->repartirDUneBaseVierge();
        $this->semerLesReglages();
        $this->semerLArbre();
    }

    private function isolerLaBaseDuModule(): void
    {
        $url = 'sqlite:///'.self::cheminDeLaBase();

        $_ENV['DATABASE_URL'] = $url;
        $_SERVER['DATABASE_URL'] = $url;
    }

    private static function cheminDeLaBase(): string
    {
        return \dirname(__DIR__, 2).'/var/data_test_tree_'.getmypid().'.db';
    }

    public static function tearDownAfterClass(): void
    {
        self::ensureKernelShutdown();

        $base = self::cheminDeLaBase();
        foreach ([$base, $base.'-journal', $base.'-wal', $base.'-shm'] as $fichier) {
            if (is_file($fichier)) {
                @unlink($fichier);
            }
        }
    }

    /**
     * On vide table par table plutôt que par `SchemaTool::dropSchema()`, qui exécute ses
     * `DROP` **en avalant les exceptions** : quand l'ordre heurte une clé étrangère, une
     * partie des tables survit sans que rien ne le signale, et le `createSchema()`
     * suivant échoue sur la première table déjà présente. Le test d'après tombe alors sur
     * un « no such table » à des kilomètres de la cause.
     */
    private function repartirDUneBaseVierge(): void
    {
        $em = $this->em();
        $connexion = $em->getConnection();

        $connexion->executeStatement('PRAGMA foreign_keys = OFF');
        foreach ($connexion->createSchemaManager()->listTableNames() as $table) {
            $connexion->executeStatement(\sprintf('DROP TABLE IF EXISTS "%s"', $table));
        }
        $connexion->executeStatement('PRAGMA foreign_keys = ON');

        (new SchemaTool($em))->createSchema($em->getMetadataFactory()->getAllMetadata());
    }

    protected function em(): EntityManagerInterface
    {
        $em = self::getContainer()->get('doctrine')->getManager();
        \assert($em instanceof EntityManagerInterface);

        return $em;
    }

    /**
     * Les réglages sont posés en migration (§ 2.2 du modèle), et la migration ne joue
     * pas sur SQLite.
     */
    private function semerLesReglages(): void
    {
        $em = $this->em();

        $em->persist(new Setting(
            'cercle.generations',
            '3',
            SettingType::Entier,
            'Générations remontées par le cercle de visibilité',
            null,
            1,
            6,
        ));
        $em->persist(new Setting(
            'tree.seuil_vivant_annees',
            (string) ReglePersonneVivante::SEUIL_DEFAUT,
            SettingType::Entier,
            'Seuil au-delà duquel une personne est tenue pour décédée',
        ));

        $em->flush();
    }

    private function semerLArbre(): void
    {
        $em = $this->em();

        $this->personnes['ego'] = $this->personne(
            'Natron Romarin',
            'Natron',
            'Romarin',
            Sex::Masculin,
            0,
            Branch::Tr,
            naissance: $this->annee(2012),
        );

        $this->personnes['conjointe'] = $this->personne(
            'Cobalt Romarin',
            'Cobalt',
            'Romarin',
            Sex::Feminin,
            0,
            Branch::Tr,
            naissance: $this->annee(2014),
        );

        // ⇢ Le cas central de tous ces tests. Né vers 1934, aucun décès enregistré : la
        //   règle du § 9.2 le classe vivant à quatre-vingt-douze ans, « improbable mais non
        //   exclu » — c'est la personne réelle sur laquelle le contrat d'API compare
        //   les deux sérialisations (§ 11.1).
        $this->personnes['cousin'] = $this->personne(
            'Pyroxène Tilleul',
            'Pyroxène',
            'Tilleul',
            Sex::Masculin,
            -2,
            Branch::Mm,
            naissance: new UncertainDate('ABT 1934', DateQualifier::Environ, new \DateTimeImmutable('1934-01-01'), new \DateTimeImmutable('1934-12-31'), 'Sorbière, Fougerie, Florie'),
        );

        $this->personnes['aieule'] = $this->personne(
            'Marne Dolomite Sureau',
            'Marne Dolomite',
            'Sureau',
            Sex::Feminin,
            -3,
            Branch::Pm,
            naissance: new UncertainDate('BET 1901 AND 1909', DateQualifier::Entre, new \DateTimeImmutable('1901-01-01'), new \DateTimeImmutable('1909-12-31'), 'Saule, Ombrage, Sylvanie'),
            deces: new UncertainDate('1987', DateQualifier::Annee, new \DateTimeImmutable('1987-01-01'), new \DateTimeImmutable('1987-12-31'), 'Charmille, Ombrage, Sylvanie'),
            decedee: true,
        );

        $this->personnes['aieul'] = $this->personne(
            'Émeri Sarriette',
            'Émeri',
            'Sarriette',
            Sex::Masculin,
            -3,
            Branch::Pm,
            naissance: $this->annee(1899),
            deces: $this->annee(1980),
            decedee: true,
        );

        // Ni date, ni décès, génération −8 : seul le repli par médiane peut trancher.
        $this->personnes['sansDate'] = $this->personne(
            'Lapis Fenouil',
            'Lapis',
            'Fenouil',
            Sex::Masculin,
            -8,
            Branch::Pp,
        );

        foreach ($this->personnes as $personne) {
            $em->persist($personne);
        }

        // Xref littérale, à dessein — celle-ci comme celle de la source plus bas. Le brief
        // d'anonymisation demandait de renuméroter les xrefs ; **cette consigne a été
        // annulée** (§ 9.1) : un xref est un numéro opaque, il n'énonce rien sans la date
        // qu'on lui accole, et les dates nominatives ont quitté le dépôt par variable
        // d'environnement. Ne pas « réparer ».
        $this->familles['aieux'] = new Family($this->personnes['aieul'], $this->personnes['aieule']);
        $this->familles['aieux']
            ->setGedcomXref('@F500028@')
            ->setMarriage(new UncertainDate('23 SEP 1927', DateQualifier::Exacte, new \DateTimeImmutable('1927-09-23'), new \DateTimeImmutable('1927-09-23'), 'Saule, Ombrage, Sylvanie'))
            ->setGeneration(-3)
            ->setRank(-5);

        // Un couple dont **un seul membre est masqué** : c'est lui qui prouve que les
        // dates d'union restent visibles (§ 5.4) et que la fratrie ne devient pas fausse.
        $this->familles['mixte'] = new Family($this->personnes['cousin'], $this->personnes['conjointe']);
        $this->familles['mixte']
            ->setMarriage($this->annee(1962))
            ->setGeneration(-1)
            ->setRank(1);

        foreach ($this->familles as $famille) {
            $em->persist($famille);
        }

        $em->persist(new Child($this->familles['aieux'], $this->personnes['ego'], 0));
        $em->persist(new Child($this->familles['mixte'], $this->personnes['sansDate'], 0));

        // Xref littérale, à dessein (voir la note sur la famille ci-dessus). Ne pas « réparer ».
        $source = (new Source('Archives départementales de la Fougerie', SourceKind::ActeEtatCivil))
            ->setGedcomXref('@S500011@');
        $em->persist($source);

        // Une citation et une note **sur la personne vivante hors cercle** : sans elles,
        // le test de liste blanche passerait pour de mauvaises raisons — il n'y aurait
        // simplement rien à fuir.
        $citation = (new Citation($source, NoteScope::Naissance))
            ->setPerson($this->personnes['cousin'])
            ->setPage('Sorbière, table décennale 1930-1939');
        $em->persist($citation);

        $note = (new Note('Vivant à quatre-vingt-douze ans, improbable mais non exclu.', NoteScope::Generale))
            ->setPerson($this->personnes['cousin']);
        $em->persist($note);

        $em->flush();

        // La racine se pose **après** le `flush` : elle porte un identifiant, que l'ego
        // n'avait pas avant. Sans elle, le moteur n'a aucun point de départ pour sa
        // récursion d'ascendance et tout l'arbre tombe dans le point fixe de la phase 2 —
        // le graphe reste calculable, mais il ne ressemble plus à l'arbre attendu.
        $em->persist(new Setting(
            'tree.personne_racine_id',
            (string) $this->personnes['ego']->getId(),
            SettingType::Entier,
            'La personne racine de l’arbre, ramenée à x = 0',
        ));

        $em->flush();
    }

    private function annee(int $annee): UncertainDate
    {
        return new UncertainDate(
            (string) $annee,
            DateQualifier::Annee,
            new \DateTimeImmutable($annee.'-01-01'),
            new \DateTimeImmutable($annee.'-12-31'),
        );
    }

    private function personne(
        string $affichage,
        string $prenoms,
        string $nom,
        Sex $sexe,
        int $generation,
        Branch $branche,
        ?UncertainDate $naissance = null,
        ?UncertainDate $deces = null,
        bool $decedee = false,
    ): Person {
        $personne = new Person($affichage);
        $personne
            ->setGivenNames($prenoms)
            ->setSurname($nom)
            // **La clé se calcule comme en production, pas à la main.**
            //
            // Cette ligne posait `mb_strtoupper($nom.' '.$prenoms)`, ce qui contourne
            // {@see CleDeRecherche} : les clés semées ne portaient donc **aucun accent
            // replié, aucune ligature, aucune ponctuation réduite**. La recherche était
            // éprouvée sur des clés qu'aucun écrivain de production ne produit — et le
            // socle en contient : « Émeri Sarriette » était semé `SARRIETTE ÉMERI`, que
            // le terme « Émeri » (replié en `EMERI`) ne peut pas trouver. Un test vert
            // sur une fixture impossible ne dit rien du produit.
            ->setSearchKey((new CleDeRecherche())->cleDeNom($prenoms, $nom))
            ->setSex($sexe)
            ->setGeneration($generation)
            ->setBranch($branche)
            ->setDeceased($decedee)
            ->setBirth($naissance ?? UncertainDate::inconnue())
            ->setDeath($deces ?? UncertainDate::inconnue());

        return $personne;
    }

    /** @param list<string> $roles */
    protected function creerUtilisateur(
        string $courriel,
        array $roles = [],
        UserStatus $statut = UserStatus::Actif,
        string $nomAffiche = 'Compte de test',
    ): User {
        $em = $this->em();
        $hacheur = self::getContainer()->get(UserPasswordHasherInterface::class);
        \assert($hacheur instanceof UserPasswordHasherInterface);

        $utilisateur = new User($courriel, $nomAffiche);
        $utilisateur
            ->setRoles($roles)
            ->setStatus($statut)
            ->setPassword($hacheur->hashPassword($utilisateur, 'motdepasse-de-test'));

        $em->persist($utilisateur);
        $em->flush();

        return $utilisateur;
    }

    /**
     * Un **membre validé dont le cercle ne contient aucun vivant de cet arbre** : compte
     * actif, `mbr_member` actif, ancré sur une fiche que le double `PortArbre` de
     * `Membership` ne connaît pas — le calcul du cercle rend donc `[]`.
     *
     * C'est le lecteur que ces tests appelaient « membre actif » en ne créant que le
     * compte. Depuis la correction du 01/10/2026, un compte actif **sans** rattachement
     * n'a pas de cercle (`Regime::Aucun`) et le voter `MEMBRE` le refuse
     * (`403 regime_en_attente`) : il ne lit plus rien de l'arbre. Le point de vue que ces
     * tests éprouvent — défunts complets, vivants en nœuds restreints — est désormais
     * celui d'un membre rattaché dont le cercle est vide.
     */
    protected function creerLecteurHorsCercle(string $courriel, string $nomAffiche = 'Compte de test'): User
    {
        $utilisateur = $this->creerUtilisateur($courriel, nomAffiche: $nomAffiche);
        $identifiant = $utilisateur->getId();
        self::assertNotNull($identifiant);

        $membre = new Member($identifiant, $courriel, self::FICHE_HORS_DE_L_ARBRE, 'Fiche hors de l’arbre de test', MatchKind::PreLie);
        $membre->activer();

        $this->em()->persist($membre);
        $this->em()->flush();

        return $utilisateur;
    }

    protected function requete(string $chemin): void
    {
        $this->client->request('GET', $chemin, server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ]);
    }

    /** @return array<string, mixed> */
    protected function charge(): array
    {
        $contenu = $this->client->getResponse()->getContent();
        self::assertIsString($contenu, 'La réponse doit avoir un corps.');

        $decode = json_decode($contenu, true);
        self::assertIsArray($decode, \sprintf('La réponse doit être un objet JSON, reçu : %s', mb_substr($contenu, 0, 300)));

        /* @var array<string, mixed> $decode */
        return $decode;
    }

    protected function idDe(string $cle): int
    {
        $id = $this->personnes[$cle]->getId();
        self::assertNotNull($id);

        return $id;
    }
}
