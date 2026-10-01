<?php

declare(strict_types=1);

namespace App\Tests\Timeline;

use App\Module\Core\Entity\Setting;
use App\Module\Core\Entity\User;
use App\Module\Core\Enum\Branch;
use App\Module\Core\Enum\SettingType;
use App\Module\Core\Enum\Sex;
use App\Module\Core\Enum\UserStatus;
use App\Module\Membership\Entity\Member;
use App\Module\Membership\Enum\MatchKind;
use App\Module\Membership\Enum\MemberStatus;
use App\Tests\Membership\Double\ArbreEnMemoire;
use App\Tests\Timeline\Double\ArbreDeChronologieEnMemoire;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Socle des tests fonctionnels du module `Timeline`.
 *
 * Il reprend les choix de `tests/Core/CoreWebTestCase.php` et de
 * `tests/Membership/CasDeTestMembership.php` — base SQLite reconstruite depuis le mapping,
 * réglages semés à la main parce qu'ils vivent en migration, caches vidés — et il a une
 * particularité qui mérite d'être lue avant d'écrire un test ici.
 *
 * ## Deux doubles, et ils doivent raconter le même arbre
 *
 * Le cercle de visibilité n'est **pas** joué par un double : c'est le vrai
 * `Membership\Service\CercleDeVisibilite` qui répond, branché sur
 * {@see ArbreEnMemoire}. C'est délibéré, et c'est la consigne du dossier de mission :
 * *« teste une règle de sécurité depuis sa source, pas depuis son entrée »* — un test qui
 * construirait à la main l'objet disant « cette personne est visible » vérifierait que le
 * normaliseur obéit *quand on le lui dit*, pas que la règle décide juste.
 *
 * Conséquence : `Membership` décide **qui est visible** depuis son propre arbre, et
 * `Timeline` lit **les dates** depuis le sien. Les deux doubles doivent donc porter les
 * mêmes personnes, avec le même état vivant — ce que {@see self::poserPersonne()} garantit
 * en les peuplant tous les deux d'un seul appel. En production les deux vues viennent de
 * la même `ReglePersonneVivante` ; ici, c'est cette méthode qui tient la promesse.
 *
 * Écrire dans un seul des deux doubles est le piège de ce fichier : une personne absente
 * de l'arbre de `Membership` est tenue pour **vivante inconnue**, donc masquée, et la
 * barre disparaît sans que rien ne le dise.
 */
abstract class CasDeTestTimeline extends WebTestCase
{
    protected KernelBrowser $client;

    private string $ipDuTest;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->ipDuTest = \sprintf('10.%d.%d.%d', random_int(0, 255), random_int(0, 255), random_int(1, 254));

        ArbreEnMemoire::reinitialiser();
        ArbreDeChronologieEnMemoire::reinitialiser();

        $this->repartirDUneBaseVierge();
        $this->semerLesReglages();
        $this->viderLesCaches();
    }

    protected function tearDown(): void
    {
        ArbreEnMemoire::reinitialiser();
        ArbreDeChronologieEnMemoire::reinitialiser();

        parent::tearDown();
    }

    /**
     * Pose une personne dans **les deux** doubles à la fois — lire l'en-tête de la classe
     * avant d'écrire dans l'un seulement.
     *
     * Les dates sont données en GEDCOM (`BET 1901 AND 1909`, `1987`, `11 MAR 1930`) et non
     * en années : c'est ce qui rend le cas Sureau exprimable.
     */
    protected function poserPersonne(
        int $id,
        string $displayName,
        Branch $branche,
        int $generation,
        Sex $sexe,
        bool $vivant,
        ?string $naissance = null,
        ?string $deces = null,
        ?string $lieuNaissance = null,
        // `force_privacy` côté `Membership` : depuis la révision du 2026-10-01 de
        // l'ADR 0003, seule une fiche confidentielle hors cercle est fermée à un membre.
        bool $confidentielle = false,
    ): void {
        ArbreDeChronologieEnMemoire::ajouterPersonne(
            $id,
            $displayName,
            $branche,
            $generation,
            $sexe,
            $vivant,
            null === $naissance ? null : ArbreDeChronologieEnMemoire::date($naissance, $lieuNaissance),
            null === $deces ? null : ArbreDeChronologieEnMemoire::date($deces),
        );

        // Le même individu côté `Membership`, pour que le vrai cercle puisse décider.
        // `prenoms`/`nom` ne servent qu'au libellé minimal de la recherche de
        // rattachement, qui n'a pas cours ici : seul `vivante` compte pour le filtrage.
        ArbreEnMemoire::ajouterPersonne($id, $displayName, '', $vivant, libelle: $displayName, confidentielle: $confidentielle);
    }

    /**
     * @param list<int> $epoux
     * @param list<int> $enfants
     */
    protected function poserFamille(array $epoux, array $enfants = []): void
    {
        ArbreEnMemoire::ajouterFamille($epoux, $enfants);
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

    protected function creerMembre(User $utilisateur, int $personId, string $personLabel): Member
    {
        $em = $this->em();
        $identifiant = $utilisateur->getId();
        \assert(null !== $identifiant);

        $membre = new Member($identifiant, $utilisateur->getEmail(), $personId, $personLabel, MatchKind::PreLie);
        $membre->setStatus(MemberStatus::Actif);
        $membre->activer();

        $em->persist($membre);
        $em->flush();

        return $membre;
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

    /**
     * Les barres de la réponse, indexées par `personId` — ce que la plupart des cas lisent.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function barresParPersonne(): array
    {
        $charge = $this->charge();
        self::assertIsArray($charge['barres'] ?? null, 'La réponse du § 9.1 porte une clé `barres`.');

        $parId = [];

        foreach ($charge['barres'] as $barre) {
            self::assertIsArray($barre);
            $parId[$barre['personId']] = $barre;
        }

        return $parId;
    }

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

    /**
     * Les deux pools, et le second n'est pas décoratif : les cercles sont rangés sous
     * `cercle.{empreinte}.{memberId}.{N}`, et l'empreinte du double est constante. Sans ce
     * nettoyage, le cercle d'un test fuiterait dans le suivant, et un test de visibilité
     * passerait ou échouerait selon l'ordre d'exécution — le pire genre de test.
     */
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
        $this->reglerEntier('timeline.bande_annees_defaut', 10, 1, 200);
    }
}
