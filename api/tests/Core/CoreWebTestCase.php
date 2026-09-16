<?php

declare(strict_types=1);

namespace App\Tests\Core;

use App\Module\Core\Entity\AuditLog;
use App\Module\Core\Entity\Setting;
use App\Module\Core\Entity\User;
use App\Module\Core\Enum\SettingType;
use App\Module\Core\Enum\UserStatus;
use App\Module\Core\Repository\AuditLogRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Socle des tests fonctionnels du module `Core`.
 *
 * **Sur SQLite, et sans conteneur.** `api/.env.test` pointe sur un fichier SQLite et la
 * CI ne monte aucun service de base de données ; le schéma est donc reconstruit ici
 * depuis le mapping Doctrine plutôt que par la migration, qui est écrite en PostgreSQL.
 * La cohérence entre les deux est vérifiée ailleurs, par `Schema\SchemaSynchroniseTest`.
 *
 * **Le jeu de réglages est semé ici** pour la même raison : il est posé en migration
 * (§ 2.2 du modèle), et la migration ne joue pas sur SQLite.
 *
 * **L'étanchéité entre processus est posée ailleurs** : `api/tests/bootstrap.php` donne à
 * chaque processus sa propre base SQLite et son propre répertoire de pools de cache. Ne
 * pas la refaire ici ; lire l'en-tête de ce fichier avant d'y toucher, il explique
 * pourquoi ces deux séparations existent.
 *
 * Reste à la charge de ce socle l'étanchéité **entre tests d'un même processus** : une
 * base remise à plat ({@see self::repartirDUneBaseVierge()}), un pool de limiteur vidé,
 * et une adresse IP source distincte par test ({@see self::requete()}). Sans cela, les
 * tentatives échouées d'un test comptent dans le plafond du suivant, et le test de
 * blocage passe ou échoue selon l'ordre d'exécution — le pire genre de test.
 */
abstract class CoreWebTestCase extends WebTestCase
{
    protected KernelBrowser $client;

    /**
     * L'adresse source de ce test. Voir {@see self::requete()} : elle est **différente à
     * chaque test**, et c'est ce qui rend les tests de limitation de débit reproductibles.
     */
    private string $ipDuTest;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->ipDuTest = \sprintf('10.%d.%d.%d', random_int(0, 255), random_int(0, 255), random_int(1, 254));

        $this->repartirDUneBaseVierge();
        $this->semerLesReglages();
        $this->viderLeLimiteur();
    }

    /**
     * On vide la base table par table ; on n'appelle **pas** `SchemaTool::dropSchema()`.
     *
     * `dropSchema()` exécute ses `DROP` un par un **en avalant les exceptions** : quand
     * l'ordre de suppression heurte une clé étrangère, une partie des tables survit sans
     * que rien ne le signale. Le `createSchema()` suivant échoue alors sur la première
     * table déjà présente, en laissant la base à moitié construite — et le test d'après
     * tombe sur un « no such table » à des kilomètres de la cause. C'est exactement ce
     * qui est arrivé en écrivant ces tests, et ça a coûté une demi-heure.
     *
     * Avec `PRAGMA foreign_keys = OFF`, l'ordre cesse d'exister comme problème : on
     * supprime dans l'ordre du catalogue, et rien ne peut échouer à moitié.
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
        // Rechargé à chaque appel : le client relance le noyau entre deux requêtes, et
        // un gestionnaire gardé d'avant est fermé.
        $em = self::getContainer()->get('doctrine')->getManager();
        \assert($em instanceof EntityManagerInterface);

        return $em;
    }

    /** @param list<string> $roles */
    protected function creerUtilisateur(
        string $courriel,
        string $motDePasse = 'motdepasse-de-test',
        array $roles = [],
        UserStatus $statut = UserStatus::Actif,
        string $nomAffiche = '',
    ): User {
        $em = $this->em();
        $hacheur = self::getContainer()->get(UserPasswordHasherInterface::class);
        \assert($hacheur instanceof UserPasswordHasherInterface);

        $utilisateur = new User($courriel, $nomAffiche);
        $utilisateur
            ->setRoles($roles)
            ->setStatus($statut)
            ->setPassword($hacheur->hashPassword($utilisateur, $motDePasse));

        $em->persist($utilisateur);
        $em->flush();

        return $utilisateur;
    }

    protected function connexion(string $courriel, string $motDePasse = 'motdepasse-de-test'): void
    {
        $this->requete('POST', '/api/connexion', ['email' => $courriel, 'password' => $motDePasse]);
    }

    /**
     * @param array<string, mixed>|null $corps
     *
     * **Chaque test parle depuis sa propre adresse IP.** `login_throttling` compte par
     * couple identifiant+IP et double la mesure d'un compteur par IP seule. Vider le pool
     * au démarrage laisse une fenêtre : le noyau est relancé à chaque requête du client,
     * et une reconstruction du conteneur entre deux requêtes fait tourner le préfixe du
     * pool. Une IP tirée au hasard par test donne à chacun sa propre fenêtre, quoi qu'il
     * arrive au pool.
     */
    protected function requete(string $methode, string $chemin, ?array $corps = null): void
    {
        $this->client->request(
            $methode,
            $chemin,
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'REMOTE_ADDR' => $this->ipDuTest,
            ],
            content: null !== $corps ? json_encode($corps, \JSON_THROW_ON_ERROR) : null,
        );
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

    /** @return list<AuditLog> */
    protected function journal(): array
    {
        $depot = self::getContainer()->get(AuditLogRepository::class);
        \assert($depot instanceof AuditLogRepository);

        /** @var list<AuditLog> $lignes */
        $lignes = $depot->createQueryBuilder('a')->orderBy('a.id', 'ASC')->getQuery()->getResult();

        return $lignes;
    }

    private function semerLesReglages(): void
    {
        $em = $this->em();

        $em->persist(new Setting(
            'cercle.generations',
            '3',
            SettingType::Entier,
            'Générations remontées par le cercle de visibilité',
            'Le N du cercle : combien de générations on remonte avant de redescendre.',
            1,
            6,
        ));
        $em->persist(new Setting(
            'media.mimes_autorises',
            '["image/jpeg","image/png","image/webp","application/pdf"]',
            SettingType::Json,
            'Types de fichiers acceptés',
            'Liste blanche appliquée après reniflage des octets d’en-tête.',
        ));

        $em->flush();
    }

    private function viderLeLimiteur(): void
    {
        $pool = self::getContainer()->get('cache.rate_limiter');

        if ($pool instanceof CacheItemPoolInterface) {
            $pool->clear();
        }
    }
}
