<?php

declare(strict_types=1);

namespace App\Tests\Proposal;

use App\Module\Core\Entity\Setting;
use App\Module\Core\Entity\User;
use App\Module\Core\Enum\DateQualifier;
use App\Module\Core\Enum\SettingType;
use App\Module\Core\Enum\SubjectKind;
use App\Module\Core\Enum\UserStatus;
use App\Module\Core\ValueObject\UncertainDate;
use App\Module\Membership\Entity\Member;
use App\Module\Membership\Enum\MatchKind;
use App\Module\Membership\Enum\MemberStatus;
use App\Tests\Proposal\Double\ArbreEnMemoire;
use App\Tests\Proposal\Double\NotificateurEnMemoire;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Le socle des tests **HTTP** de `Proposal`, et la raison pour laquelle il existe.
 *
 * ── Ce que `BaseDeDemandes` ne pouvait pas faire, et ne peut plus ne pas faire ────
 *
 * L'en-tête de {@see BaseDeDemandes} explique que ce module se teste au niveau des
 * services parce que « `config/packages/security.yaml` est encore volontairement
 * incomplet, sans fournisseur d'utilisateurs sur `Core\User` ni pare-feu » : `loginUser()`
 * y échouait. Ce n'est plus vrai — `security.yaml` porte désormais un `json_login` sur
 * `core_connexion`, un fournisseur d'entité et un `access_control` qui ferme `^/api`. La
 * dette que ce fichier-là annonçait (« ce qui reste à couvrir en HTTP quand la sécurité
 * sera posée : les codes de statut et l'enveloppe d'erreur ») est donc échue, et c'est
 * ce socle qui la solde.
 *
 * Mesuré avant d'écrire une ligne : les douze routes du § 6 n'étaient vérifiées qu'**en
 * déclaration** — {@see DiffEtRoutesTest::testLesRoutesDuContratSontDeclarees()} fait
 * `getRouteCollection()->get($nom)` puis compare le chemin. Il vérifie qu'elles sont
 * déclarées au bon endroit, **jamais qu'elles répondent**. Aucun `$client->request()` du
 * dépôt ne visait `/api/demandes` ni `/api/administration/demandes`.
 *
 * ── Ce que la couche HTTP ajoute, et que le service ne peut pas montrer ──────────
 *
 * 1. **La traduction exception → réponse.** `Conflit::demandeCaduque()` et
 *    `Conflit::verrouConcurrent()` portent chacune un `details.rejouable` ; rien ne
 *    prouvait jusqu'ici qu'`ApiExceptionSubscriber` le laisse passer jusqu'au front, ni
 *    que les deux ressortent bien en `409`. Le contrat insiste, le front en dépend, et
 *    **personne ne les avait jamais vues en réponse réelle**.
 * 2. **La seconde requête est gratuite et fidèle.** `BaseDeDemandes::nouvelleRequete()`
 *    rebâtit le noyau à la main pour éprouver le conseil « réessayez » de
 *    `verrou_concurrent`, parce que Doctrine ferme l'`EntityManager` après un échec de
 *    transaction. Le client HTTP fait cela tout seul entre deux `request()` : ce n'est
 *    plus une simulation, c'est le mécanisme.
 * 3. **La garde d'autorisation.** `access_control` et {@see DemandeVoter} ne
 *    s'exécutent que dans une requête.
 *
 * ── Les deux doubles restent ceux de la production de test ───────────────────────
 *
 * `config/services/proposal.yaml` aliase, en `when@test`, `PortArbre` sur
 * {@see ArbreEnMemoire} et `NotificateurDeDemandes` sur {@see NotificateurEnMemoire}. Un
 * test HTTP hérite donc exactement du câblage des tests de service — mêmes tags, mêmes
 * itérateurs, mêmes applicateurs — et l'arbre reste celui dont on contrôle la version au
 * verrou près. Leur état est **statique**, ce qui est ici un avantage : le client HTTP
 * rebâtit le noyau à chaque requête, et l'arbre, lui, ne se vide pas entre deux requêtes.
 *
 * ── Le déposant est un membre ACTIF, et ce n'est pas un détail ───────────────────
 *
 * `DepotDeDemande::verifierQuIlPeutEcrire()` refuse le régime `Aucun`. Or
 * `Membership\Service\FabriqueDuLecteurDuCercle` ferme le cercle d'un compte actif
 * **sans ligne `mbr_member` active** : sans le rattachement posé par
 * {@see self::creerMembreActif()}, tout dépôt rendrait `403 regime_en_attente` et ces
 * tests ne diraient rien de ce qu'ils prétendent dire.
 */
abstract class CasDeTestDemandesHttp extends WebTestCase
{
    protected KernelBrowser $client;

    /** Voir {@see self::requete()} : une adresse par test, pour que le limiteur de connexion soit reproductible. */
    private string $ipDuTest;

    protected const string MOT_DE_PASSE = 'motdepasse-de-test';

    /** Marne Dolomite Sureau, `id = 17`, `version = 7` — la fiche du § 9.6 du modèle. */
    protected const int PERSONNE = 17;

    /** La version de départ de la fiche : celle que portent les `baseVersion` des ops. */
    protected const int VERSION_DE_DEPART = 7;

    protected function setUp(): void
    {
        // Les doubles portent un état statique : ils tiennent le rôle des tables, qui
        // survivent à une requête. On le remet à zéro ici, et nulle part ailleurs.
        ArbreEnMemoire::oublierTout();
        NotificateurEnMemoire::oublierTout();

        $this->client = static::createClient();
        $this->ipDuTest = \sprintf('10.%d.%d.%d', random_int(0, 255), random_int(0, 255), random_int(1, 254));

        $this->repartirDUneBaseVierge();
        $this->semerLesReglages();
        $this->viderLesCaches();
        $this->poserLaFicheDeReference();
    }

    // ─────────────────────────────────────────────────────────────────────────────
    //  L'arbre et les comptes
    // ─────────────────────────────────────────────────────────────────────────────

    protected function arbre(): ArbreEnMemoire
    {
        $arbre = self::getContainer()->get(ArbreEnMemoire::class);
        \assert($arbre instanceof ArbreEnMemoire);

        return $arbre;
    }

    protected function notificateur(): NotificateurEnMemoire
    {
        $notificateur = self::getContainer()->get(NotificateurEnMemoire::class);
        \assert($notificateur instanceof NotificateurEnMemoire);

        return $notificateur;
    }

    /**
     * Marne Dolomite Sureau telle que le § 9.6 la met en scène : `version = 7`,
     * naissance `1987`.
     */
    protected function poserLaFicheDeReference(): void
    {
        $this->arbre()->poser(SubjectKind::Personne, self::PERSONNE, 'Marne Dolomite Sureau', self::VERSION_DE_DEPART, [
            'givenNames' => 'Marne Dolomite',
            'surname' => 'Sureau',
            'marriedName' => null,
            'nickname' => null,
            'sex' => 'feminin',
            'deceased' => true,
            'birth' => new UncertainDate('1987', DateQualifier::Annee, new \DateTimeImmutable('1987-01-01'), new \DateTimeImmutable('1987-12-31')),
            'death' => UncertainDate::inconnue(),
            'forcePrivacy' => false,
            'privacyNote' => null,
        ]);
    }

    /** @param list<string> $roles */
    protected function creerUtilisateur(
        string $courriel,
        array $roles = [],
        string $nomAffiche = 'Compte de test',
        UserStatus $statut = UserStatus::Actif,
    ): User {
        $em = $this->em();
        $hacheur = self::getContainer()->get(UserPasswordHasherInterface::class);
        \assert($hacheur instanceof UserPasswordHasherInterface);

        $utilisateur = new User($courriel, $nomAffiche);
        $utilisateur
            ->setRoles($roles)
            ->setStatus($statut)
            ->setPassword($hacheur->hashPassword($utilisateur, self::MOT_DE_PASSE));

        $em->persist($utilisateur);
        $em->flush();

        return $utilisateur;
    }

    /**
     * Le rattachement `mbr_member` **actif** sans lequel le compte n'a pas de régime, et
     * donc pas le droit de contribuer. Voir l'en-tête de cette classe.
     */
    protected function creerMembreActif(User $utilisateur, int $personId, string $personLabel = 'Personne de test'): Member
    {
        $em = $this->em();
        $id = $utilisateur->getId();
        \assert(null !== $id);

        $membre = new Member($id, $utilisateur->getEmail(), $personId, $personLabel, MatchKind::PreLie);
        $membre->setStatus(MemberStatus::Actif);
        $membre->activer();

        $em->persist($membre);
        $em->flush();

        return $membre;
    }

    /**
     * Un membre actif, prêt à déposer : compte + rattachement, en une ligne.
     *
     * `$personId` diffère d'un membre à l'autre parce que `uniq_mbr_member_person`
     * interdit à deux comptes de réclamer la même personne.
     */
    protected function creerContributeur(string $courriel, int $personId, string $nomAffiche): User
    {
        $utilisateur = $this->creerUtilisateur($courriel, nomAffiche: $nomAffiche);
        $this->creerMembreActif($utilisateur, $personId, $nomAffiche);

        return $utilisateur;
    }

    // ─────────────────────────────────────────────────────────────────────────────
    //  Les requêtes
    // ─────────────────────────────────────────────────────────────────────────────

    protected function connexion(string $courriel): void
    {
        $this->requete('POST', '/api/connexion', ['email' => $courriel, 'password' => self::MOT_DE_PASSE]);
        self::assertResponseIsSuccessful(\sprintf('La connexion de « %s » doit réussir avant le reste du scénario.', $courriel));
    }

    protected function deconnexion(): void
    {
        $this->requete('POST', '/api/deconnexion');
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
        self::assertIsArray($decode, \sprintf('La réponse doit être un objet JSON, reçu : %s', mb_substr($contenu, 0, 400)));

        /* @var array<string, mixed> $decode */
        return $decode;
    }

    /**
     * L'enveloppe d'erreur du § 15.1 — **quatre clés, dans cet ordre**, `champs` et
     * `details` présents à `null` plutôt qu'absents.
     *
     * @return array<string, mixed>
     */
    protected function erreur(): array
    {
        $charge = $this->charge();

        self::assertArrayHasKey('erreur', $charge, 'Une réponse d’erreur porte une clé « erreur » (§ 15.1).');
        self::assertIsArray($charge['erreur']);
        self::assertSame(['code', 'message', 'champs', 'details'], array_keys($charge['erreur']));

        return $charge['erreur'];
    }

    // ─────────────────────────────────────────────────────────────────────────────
    //  Les corps de demande
    // ─────────────────────────────────────────────────────────────────────────────

    /**
     * Un op de champ texte, prêt à être posté.
     *
     * @return array<string, mixed>
     */
    protected function opTexte(string $field, ?string $ancienne, ?string $nouvelle, int $baseVersion = self::VERSION_DE_DEPART, int $seq = 1): array
    {
        return [
            'seq' => $seq,
            'opKind' => 'champ',
            'subjectKind' => 'personne',
            'subjectId' => self::PERSONNE,
            'field' => $field,
            'valueKind' => 'texte',
            'oldValue' => $ancienne,
            'newValue' => $nouvelle,
            'baseVersion' => $baseVersion,
        ];
    }

    /**
     * Dépose une demande **par HTTP** et rend son identifiant.
     *
     * @param list<array<string, mixed>> $ops
     */
    protected function deposer(array $ops, ?string $message = null): int
    {
        $this->requete('POST', '/api/demandes', [
            'kind' => 'modification_personne',
            'message' => $message,
            'soumettre' => true,
            'targetKind' => 'personne',
            'targetId' => self::PERSONNE,
            'ops' => $ops,
        ]);

        self::assertResponseStatusCodeSame(201, 'Un dépôt qui réussit rend 201 avec la demande (§ 1.1).');

        $charge = $this->charge();
        self::assertIsInt($charge['id']);
        self::assertSame('en_attente', $charge['status'], 'Une demande soumise entre dans la file.');

        return $charge['id'];
    }

    /** Le statut d'une demande, **relu par HTTP** — jamais depuis un objet gardé en mémoire. */
    protected function statutDeLaDemande(int $id): string
    {
        $this->requete('GET', '/api/demandes/'.$id);
        self::assertResponseIsSuccessful();

        $statut = $this->charge()['status'];
        self::assertIsString($statut);

        return $statut;
    }

    protected function em(): EntityManagerInterface
    {
        // Rechargé à chaque appel : le client relance le noyau entre deux requêtes, et un
        // gestionnaire gardé d'avant est fermé.
        $em = self::getContainer()->get('doctrine')->getManager();
        \assert($em instanceof EntityManagerInterface);

        return $em;
    }

    /**
     * On vide table par table ; on n'appelle **pas** `SchemaTool::dropSchema()`, qui
     * avale ses exceptions et laisse la base à moitié construite — le motif est écrit en
     * entier dans {@see \App\Tests\ReinitialisationDuSchema}.
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

    /** Les réglages vivent en migration (§ 2.2), et la migration ne joue pas sur SQLite. */
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

        $em->flush();
    }

    /**
     * Les compteurs de `login_throttling` et les cercles mis en cache vivent dans deux
     * pools de fichiers : sans ce nettoyage, un test hérite du voisinage du précédent.
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
}
