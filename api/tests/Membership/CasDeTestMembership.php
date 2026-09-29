<?php

declare(strict_types=1);

namespace App\Tests\Membership;

use App\Module\Core\Entity\Setting;
use App\Module\Core\Entity\User;
use App\Module\Core\Enum\SettingType;
use App\Module\Core\Enum\UserStatus;
use App\Module\Membership\Entity\Invitation;
use App\Module\Membership\Entity\Member;
use App\Module\Membership\Enum\MatchKind;
use App\Module\Membership\Enum\MemberStatus;
use App\Module\Membership\Service\GenerateurDeJeton;
use App\Tests\Membership\Double\ArbreEnMemoire;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Socle des tests fonctionnels du module `Membership`.
 *
 * Il reprend les choix de `tests/Core/CoreWebTestCase.php` — base SQLite reconstruite
 * depuis le mapping, réglages semés à la main parce qu'ils vivent en migration, pool de
 * limiteur vidé, **une adresse IP par test** — et en ajoute deux qui lui sont propres :
 *
 * 1. **L'arbre est un double en mémoire** ({@see ArbreEnMemoire}), reposé à chaque test.
 *    Aucune table `tree_*` n'est montée : ce module n'en connaît pas l'existence, et
 *    c'est la frontière de l'ADR 0002 vérifiée par la forme même des tests ;
 * 2. **le cache du cercle est vidé**. Les cercles sont rangés sous
 *    `cercle.{empreinte}.{memberId}.{N}`, et l'empreinte du double est constante : sans
 *    ce nettoyage, le cercle d'un test fuiterait dans le suivant — et un test du réglage
 *    `N` passerait ou échouerait selon l'ordre d'exécution, le pire genre de test.
 *
 * L'IP par test n'est pas décorative ici non plus : deux des trois limiteurs du parcours
 * d'inscription comptent par adresse, et le test du plafond par **jeton** exige que le
 * plafond par **IP** ne se déclenche jamais en cours de route.
 */
abstract class CasDeTestMembership extends WebTestCase
{
    protected KernelBrowser $client;

    private string $ipDuTest;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->ipDuTest = \sprintf('10.%d.%d.%d', random_int(0, 255), random_int(0, 255), random_int(1, 254));

        $this->repartirDUneBaseVierge();
        $this->semerLesReglages();
        $this->viderLesCaches();

        ArbreFamilial::poser();
    }

    protected function tearDown(): void
    {
        ArbreEnMemoire::reinitialiser();

        parent::tearDown();
    }

    protected function em(): EntityManagerInterface
    {
        // Rechargé à chaque appel : le client relance le noyau entre deux requêtes, et un
        // gestionnaire gardé d'avant est fermé.
        $em = self::getContainer()->get('doctrine')->getManager();
        \assert($em instanceof EntityManagerInterface);

        return $em;
    }

    /** @param list<string> $roles */
    protected function creerUtilisateur(
        string $courriel,
        array $roles = [],
        UserStatus $statut = UserStatus::Actif,
        string $motDePasse = 'motdepasse-de-test',
    ): User {
        $em = $this->em();
        $hacheur = self::getContainer()->get(UserPasswordHasherInterface::class);
        \assert($hacheur instanceof UserPasswordHasherInterface);

        $utilisateur = new User($courriel);
        $utilisateur
            ->setRoles($roles)
            ->setStatus($statut)
            ->setPassword($hacheur->hashPassword($utilisateur, $motDePasse));

        $em->persist($utilisateur);
        $em->flush();

        return $utilisateur;
    }

    protected function creerMembre(
        User $utilisateur,
        int $personId,
        string $personLabel,
        MemberStatus $statut = MemberStatus::Actif,
        MatchKind $nature = MatchKind::PreLie,
    ): Member {
        $em = $this->em();
        $identifiant = $utilisateur->getId();
        \assert(null !== $identifiant);

        $membre = new Member($identifiant, $utilisateur->getEmail(), $personId, $personLabel, $nature);
        $membre->setStatus($statut);

        if (MemberStatus::Actif === $statut) {
            $membre->activer();
        }

        $em->persist($membre);
        $em->flush();

        return $membre;
    }

    /**
     * Crée une invitation **et rend son jeton en clair** — ce que la production ne sait
     * pas faire une seconde fois, et c'est bien le sujet : ici on le fabrique, on ne le
     * relit pas.
     *
     * @return array{0: Invitation, 1: string}
     */
    protected function creerInvitation(User $administrateur, ?int $personId = null, ?string $personLabel = null, ?int $maxUses = 1): array
    {
        $jetons = self::getContainer()->get(GenerateurDeJeton::class);
        \assert($jetons instanceof GenerateurDeJeton);

        $jeton = $jetons->jeton();
        $identifiant = $administrateur->getId();
        \assert(null !== $identifiant);

        $invitation = new Invitation(
            $jetons->empreinte($jeton),
            $jetons->prefixe($jeton),
            new \DateTimeImmutable('+7 days'),
            $identifiant,
            $administrateur->getEmail(),
            $maxUses,
        );

        if (null !== $personId) {
            $invitation->setPerson($personId, $personLabel ?? 'Personne pré-liée');
        }

        $em = $this->em();
        $em->persist($invitation);
        $em->flush();

        return [$invitation, $jeton];
    }

    protected function connexion(string $courriel, string $motDePasse = 'motdepasse-de-test'): void
    {
        $this->requete('POST', '/api/connexion', ['email' => $courriel, 'password' => $motDePasse]);
    }

    /** @param array<string, mixed>|null $corps */
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

    /** Pose un réglage, ou remplace le sien — c'est ainsi qu'on change `N` sans redéployer. */
    protected function reglerEntier(string $cle, int $valeur, int $min, int $max): void
    {
        $em = $this->em();
        $existant = $em->getRepository(Setting::class)->findOneBy(['settingKey' => $cle]);

        if (null !== $existant) {
            $em->remove($existant);
            $em->flush();
        }

        $em->persist(new Setting($cle, (string) $valeur, SettingType::Entier, $cle, null, $min, $max));
        $em->flush();
    }

    /** @see self::setUp() — l'en-tête dit pourquoi les deux pools sont vidés. */
    private function viderLesCaches(): void
    {
        foreach (['cache.rate_limiter', 'cache.app'] as $identifiant) {
            $pool = self::getContainer()->get($identifiant);

            if ($pool instanceof CacheItemPoolInterface) {
                $pool->clear();
            }
        }
    }

    /**
     * On vide la base table par table ; on n'appelle **pas** `SchemaTool::dropSchema()`,
     * qui avale ses exceptions et laisse la base à moitié construite. Le motif est écrit
     * en entier dans `tests/Core/CoreWebTestCase.php` ; il a coûté une demi-heure là-bas.
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

    private function semerLesReglages(): void
    {
        $this->reglerEntier('cercle.generations', 3, 1, 6);
        $this->reglerEntier('invitation.ttl_jours', 7, 1, 30);
        $this->reglerEntier('invitation.tentatives_par_heure', 5, 1, 20);
        $this->reglerEntier('invitation.candidats_max', 3, 1, 5);
    }
}
