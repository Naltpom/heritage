<?php

declare(strict_types=1);

namespace App\Tests\Almanach;

use App\Module\Almanach\Contract\TypeDEvenement;
use App\Module\Core\Entity\Setting;
use App\Module\Core\Entity\User;
use App\Module\Core\Enum\SettingType;
use App\Module\Core\Enum\Sex;
use App\Module\Core\Enum\UserStatus;
use App\Module\Membership\Entity\Member;
use App\Module\Membership\Enum\MatchKind;
use App\Module\Membership\Enum\MemberStatus;
use App\Tests\Almanach\Double\FluxDEvenementsEnMemoire;
use App\Tests\Membership\Double\ArbreEnMemoire;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Socle des tests fonctionnels du module `Almanach`.
 *
 * Il reprend les choix de `tests/Timeline/CasDeTestTimeline.php` — base SQLite reconstruite
 * depuis le mapping, réglages semés à la main parce qu'ils vivent en migration, caches
 * vidés — et il a la même particularité, qui mérite d'être lue avant d'écrire un test ici.
 *
 * ## Deux doubles, et ils doivent raconter le même arbre
 *
 * Le cercle de visibilité n'est **pas** joué par un double : c'est le vrai
 * `Membership\Service\CercleDeVisibilite` qui répond, branché sur {@see ArbreEnMemoire}.
 * C'est délibéré, et c'est la consigne du dossier de mission : *« teste une règle de
 * sécurité depuis sa source, pas depuis son entrée »* — un test qui construirait à la main
 * l'objet disant « cette personne est visible » vérifierait que le module obéit *quand on
 * le lui dit*, pas que la règle décide juste.
 *
 * Conséquence : `Membership` décide **qui est visible** depuis son propre arbre, et
 * `Almanach` lit **les événements** depuis le sien. Les deux doubles doivent donc porter
 * les mêmes personnes, avec le même état vivant — ce que {@see self::poserPersonne()}
 * garantit en les peuplant tous les deux d'un seul appel.
 *
 * **Écrire dans un seul des deux doubles est le piège de ce fichier** : une personne
 * absente de l'arbre de `Membership` est tenue pour **vivante inconnue**, donc masquée, et
 * l'événement disparaît sans que rien ne le dise.
 *
 * ## L'arbre de ces cas
 *
 * ```
 *   1  Natron Romarin      vivant    n. 26 SEP 2012        ← le lecteur
 *   2  Père Romarin        vivant    n. 1981
 *   9  Cousin Éloigné      VIVANT    n. 1979               ← HORS cercle
 *  17  Marne D. Sureau     décédée   n. BET 1901 AND 1909
 *  18  Émeri Sarriette     décédé    n. 1899
 * ```
 *
 * Le cercle de l'ADR 0003 remonte `N = 3` générations depuis le lecteur puis redescend. La
 * personne 9 n'a **aucun lien** avec le lecteur dans l'arbre familial posé ici : elle est
 * vivante et hors cercle.
 */
abstract class CasDeTestAlmanach extends WebTestCase
{
    protected const int LECTEUR = 1;
    protected const int PERE = 2;
    protected const int HORS_CERCLE = 9;
    protected const int SUREAU = 17;
    protected const int SARRIETTE = 18;

    protected KernelBrowser $client;

    private string $ipDuTest;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->ipDuTest = \sprintf('10.%d.%d.%d', random_int(0, 255), random_int(0, 255), random_int(1, 254));

        ArbreEnMemoire::reinitialiser();
        FluxDEvenementsEnMemoire::reinitialiser();

        $this->repartirDUneBaseVierge();
        $this->semerLesReglages();
        $this->viderLesCaches();
    }

    protected function tearDown(): void
    {
        ArbreEnMemoire::reinitialiser();
        FluxDEvenementsEnMemoire::reinitialiser();

        parent::tearDown();
    }

    /**
     * Pose une personne dans **les deux** doubles à la fois — lire l'en-tête de la classe
     * avant d'écrire dans l'un seulement.
     *
     * Elle n'ajoute **aucun événement** : c'est aux cas de poser ceux dont ils ont besoin,
     * avec la forme GEDCOM qui les intéresse. Un socle qui sèmerait d'office une naissance
     * par personne rendrait illisibles les tests de compteurs, où le nombre exact
     * d'événements est ce qu'on affirme.
     */
    protected function poserPersonne(int $id, string $displayName, bool $vivant, Sex $sexe = Sex::Feminin): void
    {
        FluxDEvenementsEnMemoire::ajouterSujet($id, $displayName, $sexe, $vivant);

        // Le même individu côté `Membership`, pour que le vrai cercle puisse décider.
        // `prenoms`/`nom` ne servent qu'au libellé minimal de la recherche de
        // rattachement, qui n'a pas cours ici : seul `vivante` compte pour le filtrage.
        ArbreEnMemoire::ajouterPersonne($id, $displayName, '', $vivant, libelle: $displayName);
    }

    /**
     * @param list<int> $epoux
     * @param list<int> $enfants
     */
    protected function poserFamille(array $epoux, array $enfants = []): void
    {
        ArbreEnMemoire::ajouterFamille($epoux, $enfants);
    }

    /**
     * Un événement d'une seule personne — naissance, décès ou fait.
     *
     * La date se donne en GEDCOM (`11 MAR 1930`, `1887`, `FEB 1920`) : c'est ce qui rend
     * exprimables les cas du 1<sup>er</sup> janvier et du 1<sup>er</sup> du mois, qui sont
     * les deux que ce module doit refuser de poser.
     */
    protected function poserEvenement(
        int $personId,
        TypeDEvenement $type,
        string $gedcom,
        ?string $lieu = null,
        ?string $libelle = null,
    ): void {
        FluxDEvenementsEnMemoire::ajouterEvenement(
            $type->origine().':'.$personId,
            $type,
            [$personId],
            FluxDEvenementsEnMemoire::date($gedcom, $lieu),
            $libelle,
        );
    }

    /**
     * Une union — **deux sujets**, et c'est ce qui la rend intéressante.
     *
     * @param list<int> $epoux
     */
    protected function poserUnion(
        int $familyId,
        array $epoux,
        string $gedcom,
        ?string $lieu = null,
        TypeDEvenement $type = TypeDEvenement::Mariage,
    ): void {
        FluxDEvenementsEnMemoire::ajouterEvenement(
            $type->origine().':'.$familyId,
            $type,
            $epoux,
            FluxDEvenementsEnMemoire::date($gedcom, $lieu),
            null,
            $familyId,
        );
    }

    protected function poserLieu(int $placeId, string $brut, float $lat, float $lon): void
    {
        FluxDEvenementsEnMemoire::ajouterLieu($placeId, $brut, $lat, $lon);
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

    /**
     * Ouvre une session. Un compte `EnAttente` relève du régime `Regime::Aucun` : il ne
     * voit que des personnes décédées, et il n'a pas de `Member` — d'où l'absence de
     * rattachement dans ce cas.
     */
    protected function connecterUnMembre(UserStatus $statut = UserStatus::Actif): void
    {
        $utilisateur = $this->creerUtilisateur('membre@example.test', statut: $statut);

        if (UserStatus::Actif === $statut) {
            $this->creerMembre($utilisateur, self::LECTEUR, 'Natron Romarin');
        }

        $this->requete('POST', '/api/connexion', ['email' => 'membre@example.test', 'password' => 'motdepasse-de-test']);
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
     * Les événements de la carte, indexés par leur clé — ce que la plupart des cas lisent.
     *
     * @return array<string, array<string, mixed>>
     */
    protected function evenementsDeLaCarte(): array
    {
        $charge = $this->charge();
        self::assertIsArray($charge['evenements'] ?? null, 'La charge de la carte porte une clé `evenements`.');

        $parCle = [];

        foreach ($charge['evenements'] as $evenement) {
            self::assertIsArray($evenement);
            self::assertIsString($evenement['cle'] ?? null);
            $parCle[$evenement['cle']] = $evenement;
        }

        return $parCle;
    }

    /**
     * Le résumé de la réponse courante.
     *
     * @return array<string, mixed>
     */
    protected function resume(): array
    {
        $charge = $this->charge();
        self::assertIsArray($charge['resume'] ?? null, 'Les deux routes portent une clé `resume`.');

        /* @var array<string, mixed> */
        return $charge['resume'];
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
    }
}
