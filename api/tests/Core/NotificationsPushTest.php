<?php

declare(strict_types=1);

namespace App\Tests\Core;

use App\Module\Core\Entity\PushSubscription;
use App\Module\Core\Entity\User;
use App\Module\Core\Enum\NotificationKind;
use App\Module\Core\Enum\ResultatDEnvoiPush;
use App\Module\Core\Enum\UserStatus;
use App\Module\Core\Repository\PushSubscriptionRepository;
use App\Module\Core\Service\ClesVapid;
use App\Module\Core\Service\DiffusionPush;
use App\Module\Core\Service\NotificationService;
use App\Module\Core\Service\UrlPublique;
use App\Tests\Core\Double\EnvoyeurPushEnMemoire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Les notifications sur le téléphone, application fermée — `/api/push` et le branchement
 * dans `NotificationService::notifier()`.
 *
 * Le fil rouge de ces tests est la plainte qui les a fait naître : « le jeton de
 * notification casse à chaque mise en prod ». D'où le premier d'entre eux : la clé
 * publique est la même d'un appel à l'autre **et après un redémarrage**, parce qu'elle
 * est relue en base et non régénérée.
 *
 * Aucun envoi ne sort sur le réseau : l'envoyeur est le double en mémoire branché par
 * `config/services/core.yaml` (`when@test`). L'envoi réel est éprouvé à part, contre un
 * client HTTP simulé, par `EnvoyeurWebPushTest`.
 */
final class NotificationsPushTest extends CoreWebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        EnvoyeurPushEnMemoire::oublierTout();
    }

    protected function tearDown(): void
    {
        EnvoyeurPushEnMemoire::oublierTout();
        parent::tearDown();
    }

    // ─── Les routes ──────────────────────────────────────────────────────────────

    public function testLesTroisRoutesExigentUneSession(): void
    {
        $this->requete('GET', '/api/push/cle');
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);

        $this->requete('PUT', '/api/push/abonnement', $this->abonnement('https://fcm.googleapis.com/fcm/send/a'));
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);

        $this->requete('DELETE', '/api/push/abonnement', ['endpoint' => 'https://fcm.googleapis.com/fcm/send/a']);
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    /**
     * LE test de ce fichier. Deux appels rendent la même clé, un noyau redémarré aussi,
     * et un service construit à neuf — sans rien d'autre que la base — encore.
     */
    public function testLaClePubliqueEstStableEtSurvitAUnRedemarrage(): void
    {
        $this->creerUtilisateur('cobalt@example.net');
        $this->connexion('cobalt@example.net');

        $this->requete('GET', '/api/push/cle');
        self::assertResponseIsSuccessful();
        $premiere = $this->charge()['clePublique'];
        self::assertIsString($premiere);

        // Base64url non paddé d'un point P-256 non compressé : 65 octets, préfixe 0x04.
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $premiere);
        $octets = ClesVapid::depuisBase64Url($premiere);
        self::assertNotNull($octets);
        self::assertSame(65, \strlen($octets));
        self::assertSame("\x04", $octets[0]);

        $this->requete('GET', '/api/push/cle');
        self::assertSame($premiere, $this->charge()['clePublique'], 'Deux appels, une seule clé.');

        // « Redémarrage » : noyau éteint, conteneur neuf, aucune mémoire commune — seule
        // la base a survécu, comme après un redéploiement.
        $this->client->restart();
        $this->connexion('cobalt@example.net');
        $this->requete('GET', '/api/push/cle');
        self::assertSame($premiere, $this->charge()['clePublique'], 'La clé survit au redémarrage.');

        $relue = new ClesVapid($this->em()->getConnection(), new UrlPublique('https://heritage.example.net'));
        self::assertSame($premiere, $relue->clePublique(), 'Un service neuf relit la même clé en base.');

        self::assertSame(1, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM core_vapid_key'));
    }

    public function testLaCleNeSortPasParLesReglages(): void
    {
        $this->creerUtilisateur('natron@example.net', roles: ['ROLE_ADMIN']);
        $this->connexion('natron@example.net');

        $this->requete('GET', '/api/push/cle');
        $privee = $this->em()->getConnection()->fetchOne('SELECT private_key FROM core_vapid_key');
        self::assertIsString($privee);

        $this->requete('GET', '/api/administration/reglages');
        self::assertResponseIsSuccessful();
        $reponse = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString($privee, $reponse);
        self::assertStringNotContainsString('vapid', mb_strtolower($reponse));
    }

    public function testLesVariablesDEnvironnementPriment(): void
    {
        $paire = ClesVapid::genererUnePaire();
        $cles = new ClesVapid(
            $this->em()->getConnection(),
            new UrlPublique('https://heritage.example.net'),
            $paire['publique'],
            $paire['privee'],
            'mailto:admin@example.net',
        );

        self::assertSame($paire['publique'], $cles->clePublique());
        self::assertSame(
            ['subject' => 'mailto:admin@example.net', 'publicKey' => $paire['publique'], 'privateKey' => $paire['privee']],
            $cles->pourSigner(),
        );
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM core_vapid_key'), 'Rien n’est écrit en base quand l’environnement fournit la paire.');
    }

    public function testLeSujetParDefaut(): void
    {
        $connexion = $this->em()->getConnection();
        $url = new UrlPublique('https://heritage.example.net/');

        self::assertSame(
            'mailto:invitations@heritage.example.net',
            (new ClesVapid($connexion, $url, expediteur: 'Heritage <invitations@heritage.example.net>'))->sujet(),
        );
        self::assertSame('https://heritage.example.net', (new ClesVapid($connexion, $url))->sujet());
        self::assertSame('mailto:heritage@localhost', (new ClesVapid($connexion, new UrlPublique(null)))->sujet());
    }

    public function testUnCompteEnAttenteObtientLaCleEtSAbonne(): void
    {
        $this->creerUtilisateur('cousin@example.net', statut: UserStatus::EnAttente);
        $this->connexion('cousin@example.net');

        $this->requete('GET', '/api/push/cle');
        self::assertResponseIsSuccessful();

        $this->requete('PUT', '/api/push/abonnement', $this->abonnement('https://fcm.googleapis.com/fcm/send/cousin'));
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
    }

    public function testLInscriptionEstUnUpsertQuiReattribueLAppareil(): void
    {
        $cobalt = $this->creerUtilisateur('cobalt@example.net');
        $pyrite = $this->creerUtilisateur('pyrite@example.net');
        $endpoint = 'https://fcm.googleapis.com/fcm/send/appareil-de-la-maison';

        $this->connexion('cobalt@example.net');
        $this->requete('PUT', '/api/push/abonnement', $this->abonnement($endpoint));
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        self::assertSame('', (string) $this->client->getResponse()->getContent());

        // Rejoué à chaque ouverture de l'application : aucun doublon.
        $this->requete('PUT', '/api/push/abonnement', $this->abonnement($endpoint));
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        self::assertCount(1, $this->abonnements()->findAll());
        self::assertSame($cobalt->getId(), $this->abonnements()->parEndpoint($endpoint)?->getUser()->getId());

        // Le même téléphone, un autre compte : la ligne change de mains, avec ses clés.
        $nouvelles = $this->abonnement($endpoint);
        $this->requete('POST', '/api/deconnexion');
        $this->connexion('pyrite@example.net');
        $this->requete('PUT', '/api/push/abonnement', $nouvelles);
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);

        $this->em()->clear();
        $lignes = $this->abonnements()->findAll();
        self::assertCount(1, $lignes);
        self::assertSame($pyrite->getId(), $lignes[0]->getUser()->getId());
        self::assertSame($nouvelles['keys']['p256dh'], $lignes[0]->getP256dh());
        self::assertSame($nouvelles['keys']['auth'], $lignes[0]->getAuth());

        // Le journal d'audit sait que les clés ont changé, pas ce qu'elles valent.
        foreach ($this->journal() as $ligne) {
            $changements = json_encode($ligne->getChanges(), \JSON_THROW_ON_ERROR);
            self::assertStringNotContainsString($nouvelles['keys']['p256dh'], $changements);
            self::assertStringNotContainsString($nouvelles['keys']['auth'], $changements);
        }
    }

    public function testLeRetraitEstIdempotentEtNeTouchePasAuxAppareilsDesAutres(): void
    {
        $cobalt = $this->creerUtilisateur('cobalt@example.net');
        $this->creerUtilisateur('pyrite@example.net');
        $endpoint = 'https://updates.push.services.mozilla.com/wpush/v2/cobalt';
        $this->abonner($cobalt, $endpoint);

        // Pyrite ne peut pas désabonner le téléphone de Cobalt — et la réponse ne dit pas
        // que l'endpoint existe.
        $this->connexion('pyrite@example.net');
        $this->requete('DELETE', '/api/push/abonnement', ['endpoint' => $endpoint]);
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        self::assertNotNull($this->abonnements()->parEndpoint($endpoint));

        $this->requete('POST', '/api/deconnexion');
        $this->connexion('cobalt@example.net');
        $this->requete('DELETE', '/api/push/abonnement', ['endpoint' => $endpoint]);
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        $this->em()->clear();
        self::assertNull($this->abonnements()->parEndpoint($endpoint));

        $this->requete('DELETE', '/api/push/abonnement', ['endpoint' => $endpoint]);
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
    }

    public function testUnCorpsInvalideRend400(): void
    {
        $this->creerUtilisateur('cobalt@example.net');
        $this->connexion('cobalt@example.net');

        $valide = $this->abonnement('https://fcm.googleapis.com/fcm/send/a');

        $cas = [
            'endpoint en clair' => ['endpoint' => 'http://push.example.net/a'] + $valide,
            'endpoint absent' => ['keys' => $valide['keys']],
            'autre schéma' => ['endpoint' => 'file:///etc/passwd'] + $valide,
            'clés absentes' => ['endpoint' => $valide['endpoint']],
            'p256dh tronquée' => ['endpoint' => $valide['endpoint'], 'keys' => ['p256dh' => 'AAAA', 'auth' => $valide['keys']['auth']]],
            'auth illisible' => ['endpoint' => $valide['endpoint'], 'keys' => ['p256dh' => $valide['keys']['p256dh'], 'auth' => '!!!']],
            'endpoint démesuré' => ['endpoint' => 'https://fcm.googleapis.com/fcm/send/'.str_repeat('a', 2100)] + $valide,
            // Hors des services push des navigateurs : ce serveur ne POSTe pas n'importe où.
            'hôte interne' => ['endpoint' => 'https://api/push/a'] + $valide,
            'IP littérale' => ['endpoint' => 'https://127.0.0.1/a'] + $valide,
            'hôte inconnu' => ['endpoint' => 'https://push.example.net/a'] + $valide,
            'suffixe usurpé' => ['endpoint' => 'https://fcm.googleapis.com.attaquant.test/a'] + $valide,
            'port explicite' => ['endpoint' => 'https://fcm.googleapis.com:8443/fcm/send/a'] + $valide,
            'identifiants' => ['endpoint' => 'https://x:y@fcm.googleapis.com/fcm/send/a'] + $valide,
        ];

        foreach ($cas as $nom => $corps) {
            $this->requete('PUT', '/api/push/abonnement', $corps);
            self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST, $nom);
            self::assertSame('donnees_invalides', $this->charge()['erreur']['code'], $nom);
        }

        // Un corps qui n'est pas du JSON.
        $this->client->request('PUT', '/api/push/abonnement', server: ['CONTENT_TYPE' => 'application/json'], content: '{pas du json');
        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);

        $this->requete('DELETE', '/api/push/abonnement', []);
        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);

        self::assertCount(0, $this->abonnements()->findAll());
    }

    public function testLEndpointEstNormaliseAvantDEtreStocke(): void
    {
        $this->creerUtilisateur('cobalt@example.net');
        $this->connexion('cobalt@example.net');

        // La bibliothèque d'envoi rapporte l'hôte en minuscules : stocké tel quel, un
        // 410 ne retrouverait jamais cet abonnement.
        $this->requete('PUT', '/api/push/abonnement', $this->abonnement('https://Web.Push.Apple.com/QxYz'));
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);

        $abonnements = $this->abonnements()->findAll();
        self::assertCount(1, $abonnements);
        self::assertSame('https://web.push.apple.com/QxYz', $abonnements[0]->getEndpoint());
    }

    public function testRienNEstEcritPendantUneIncarnation(): void
    {
        $this->creerUtilisateur('natron@example.net', roles: ['ROLE_ADMIN']);
        $cobalt = $this->creerUtilisateur('cobalt@example.net');
        $this->connexion('natron@example.net');

        $this->requete('POST', '/api/administration/incarnation', ['userId' => $cobalt->getId()]);
        self::assertResponseIsSuccessful();

        // Le service worker de l'administrateur rejoue son abonnement : il ne doit pas
        // passer au compte incarné.
        $this->requete('PUT', '/api/push/abonnement', $this->abonnement('https://fcm.googleapis.com/fcm/send/telephone-admin'));
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        self::assertCount(0, $this->abonnements()->findAll());
    }

    // ─── Le branchement dans `notifier()` ────────────────────────────────────────

    public function testUneNotificationPartVersLesAppareilsDuDestinataireEtDeLuiSeul(): void
    {
        $cobalt = $this->creerUtilisateur('cobalt@example.net');
        $pyrite = $this->creerUtilisateur('pyrite@example.net');
        $this->abonner($cobalt, 'https://fcm.googleapis.com/fcm/send/cobalt-telephone');
        $this->abonner($cobalt, 'https://fcm.googleapis.com/fcm/send/cobalt-tablette');
        $this->abonner($pyrite, 'https://fcm.googleapis.com/fcm/send/pyrite');

        $notification = $this->service()->notifier(
            (int) $cobalt->getId(),
            NotificationKind::DemandeRefusee,
            'Votre demande a été refusée.',
            'La date proposée ne correspond pas à l’acte.',
            'demande.detail',
            ['id' => 14],
        );
        self::assertNotNull($notification);

        // Rien ne part pendant la requête : l'envoi attend `kernel.terminate`.
        self::assertSame(0, EnvoyeurPushEnMemoire::$salves);
        $this->diffusion()->envoyerLesProgrammees();

        self::assertCount(1, EnvoyeurPushEnMemoire::$envois);
        self::assertSame(
            ['https://fcm.googleapis.com/fcm/send/cobalt-telephone', 'https://fcm.googleapis.com/fcm/send/cobalt-tablette'],
            EnvoyeurPushEnMemoire::endpointsVises(),
        );

        $envoi = EnvoyeurPushEnMemoire::$envois[0];
        self::assertSame(86_400, $envoi['ttl']);
        self::assertSame(
            [
                'id' => $notification->getId(),
                'titre' => 'Votre demande a été refusée.',
                'corps' => 'La date proposée ne correspond pas à l’acte.',
                'linkRoute' => 'demande.detail',
                'linkParams' => ['id' => 14],
                'kind' => 'demande_refusee',
            ],
            json_decode($envoi['charge'], true, flags: \JSON_THROW_ON_ERROR),
        );

        // La remise est notée, appareil par appareil.
        $this->em()->clear();
        self::assertNotNull($this->abonnements()->parEndpoint('https://fcm.googleapis.com/fcm/send/cobalt-telephone')?->getLastUsedAt());
        self::assertNull($this->abonnements()->parEndpoint('https://fcm.googleapis.com/fcm/send/pyrite')?->getLastUsedAt());
    }

    public function testLaChargeTronqueLeCorpsEtGardeDesParametresObjets(): void
    {
        $cobalt = $this->creerUtilisateur('cobalt@example.net');
        $this->abonner($cobalt, 'https://fcm.googleapis.com/fcm/send/cobalt');

        $this->service()->notifier((int) $cobalt->getId(), NotificationKind::DemandeSoumise, 'Une demande.', str_repeat('é', 400), 'accueil', []);
        $this->service()->notifier((int) $cobalt->getId(), NotificationKind::DemandeSoumise, 'Sans lien.');
        $this->diffusion()->envoyerLesProgrammees();

        $avecCorps = EnvoyeurPushEnMemoire::$envois[0]['charge'];
        $charge = json_decode($avecCorps, true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($charge);
        self::assertIsString($charge['corps']);
        self::assertSame(DiffusionPush::CORPS_MAX, mb_strlen($charge['corps']));
        self::assertStringEndsWith('…', $charge['corps']);
        // `{}` et non `[]` : le front fait `JSON.stringify(linkParams)` en paramètres nommés.
        self::assertStringContainsString('"linkParams":{}', $avecCorps);

        $sansLien = json_decode(EnvoyeurPushEnMemoire::$envois[1]['charge'], true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($sansLien);
        self::assertNull($sansLien['corps']);
        self::assertNull($sansLien['linkRoute']);
        self::assertNull($sansLien['linkParams']);
    }

    public function testUn410SupprimeLAbonnementEtLuiSeul(): void
    {
        $cobalt = $this->creerUtilisateur('cobalt@example.net');
        $this->abonner($cobalt, 'https://fcm.googleapis.com/fcm/send/desinstalle');
        $this->abonner($cobalt, 'https://fcm.googleapis.com/fcm/send/en-panne');
        $this->abonner($cobalt, 'https://fcm.googleapis.com/fcm/send/vivant');

        EnvoyeurPushEnMemoire::$reponses['https://fcm.googleapis.com/fcm/send/desinstalle'] = ResultatDEnvoiPush::Expire;
        EnvoyeurPushEnMemoire::$reponses['https://fcm.googleapis.com/fcm/send/en-panne'] = ResultatDEnvoiPush::Echec;

        self::assertNotNull($this->service()->notifier((int) $cobalt->getId(), NotificationKind::DemandeValidee, 'Validée.'));
        $this->diffusion()->envoyerLesProgrammees();

        $this->em()->clear();
        self::assertNull($this->abonnements()->parEndpoint('https://fcm.googleapis.com/fcm/send/desinstalle'), '410 : l’appareil n’existe plus.');
        // Une panne passagère ne désabonne pas un téléphone en parfait état.
        self::assertNotNull($this->abonnements()->parEndpoint('https://fcm.googleapis.com/fcm/send/en-panne'));
        self::assertNotNull($this->abonnements()->parEndpoint('https://fcm.googleapis.com/fcm/send/vivant'));
    }

    public function testUnePanneDuPushNEmpechePasLaNotification(): void
    {
        $cobalt = $this->creerUtilisateur('cobalt@example.net');
        $this->abonner($cobalt, 'https://fcm.googleapis.com/fcm/send/cobalt');

        EnvoyeurPushEnMemoire::$panne = new \RuntimeException('Service push injoignable.');

        $notification = $this->service()->notifier((int) $cobalt->getId(), NotificationKind::DemandeValidee, 'Validée.');
        self::assertNotNull($notification, 'notifier() ne lève pas et rend la notification.');
        self::assertSame(0, $this->diffusion()->envoyerLesProgrammees(), 'L’envoi ne lève pas non plus.');

        $this->connexion('cobalt@example.net');
        $this->requete('GET', '/api/notifications');
        self::assertSame(1, $this->charge()['total'], 'La cloche l’affiche quand même.');
        self::assertNotNull($this->abonnements()->parEndpoint('https://fcm.googleapis.com/fcm/send/cobalt'), 'Une panne ne désabonne pas.');
    }

    public function testUnCompteSuspenduNeRecoitRien(): void
    {
        $suspendu = $this->creerUtilisateur('ancien@example.net', statut: UserStatus::Suspendu);
        $this->abonner($suspendu, 'https://fcm.googleapis.com/fcm/send/ancien');

        self::assertNotNull($this->service()->notifier((int) $suspendu->getId(), NotificationKind::DemandeValidee, 'Validée.'));
        $this->diffusion()->envoyerLesProgrammees();
        self::assertSame([], EnvoyeurPushEnMemoire::$envois);
    }

    public function testSansAppareilAucunEnvoiEtAucuneCle(): void
    {
        $cobalt = $this->creerUtilisateur('cobalt@example.net');

        $this->service()->notifier((int) $cobalt->getId(), NotificationKind::DemandeValidee, 'Validée.');
        $this->diffusion()->envoyerLesProgrammees();

        self::assertSame([], EnvoyeurPushEnMemoire::$envois);
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM core_vapid_key'));
    }

    /**
     * Le défaut relevé en relecture le 01/10/2026 : un envoi par `notifier()` faisait
     * payer à la requête un délai réseau par destinataire. Un tirage de vingt comptes
     * pouvait tenir l'administrateur ~100 s. Désormais : une salve, après la réponse.
     */
    public function testPlusieursDestinatairesPartentEnUneSeuleSalveApresLaReponse(): void
    {
        $comptes = [];
        foreach (['cobalt', 'pyrite', 'galene'] as $nom) {
            $comptes[$nom] = $this->creerUtilisateur($nom.'@example.net');
            $this->abonner($comptes[$nom], 'https://fcm.googleapis.com/fcm/send/'.$nom);
        }

        foreach ($comptes as $compte) {
            $this->service()->notifier((int) $compte->getId(), NotificationKind::DemandeValidee, 'Le tirage est fait.');
        }
        // Une deuxième notification au même appareil, dans la même requête.
        $this->service()->notifier((int) $comptes['cobalt']->getId(), NotificationKind::DemandeValidee, 'Et encore une.');

        self::assertSame(0, EnvoyeurPushEnMemoire::$salves, 'Rien ne part pendant la requête.');

        // Ce que fait le noyau une fois la réponse rendue.
        $this->terminer();

        self::assertSame(1, EnvoyeurPushEnMemoire::$salves, 'Une seule salve pour toute la requête.');
        self::assertCount(4, EnvoyeurPushEnMemoire::endpointsVises());
        self::assertEqualsCanonicalizing(
            ['https://fcm.googleapis.com/fcm/send/cobalt', 'https://fcm.googleapis.com/fcm/send/pyrite', 'https://fcm.googleapis.com/fcm/send/galene'],
            array_values(array_unique(EnvoyeurPushEnMemoire::endpointsVises())),
        );

        // La salve est oubliée une fois partie : un second `terminate` n'envoie rien.
        $this->terminer();
        self::assertSame(1, EnvoyeurPushEnMemoire::$salves);
    }

    public function testUneSalveProgrammeeNeSurvitPasAuReset(): void
    {
        $cobalt = $this->creerUtilisateur('cobalt@example.net');
        $this->abonner($cobalt, 'https://fcm.googleapis.com/fcm/send/cobalt');

        $this->service()->notifier((int) $cobalt->getId(), NotificationKind::DemandeValidee, 'Validée.');

        // En mode worker, `kernel.reset` passe entre deux requêtes.
        $this->diffusion()->reset();

        self::assertSame(0, $this->diffusion()->envoyerLesProgrammees());
        self::assertSame(0, EnvoyeurPushEnMemoire::$salves);
    }

    public function testLaSuppressionDuCompteEmporteSesAppareils(): void
    {
        $cobalt = $this->creerUtilisateur('cobalt@example.net');
        $pyrite = $this->creerUtilisateur('pyrite@example.net');
        $this->abonner($cobalt, 'https://fcm.googleapis.com/fcm/send/cobalt');
        $this->abonner($pyrite, 'https://fcm.googleapis.com/fcm/send/pyrite');

        $connexion = $this->em()->getConnection();
        // SQLite n'applique les clés étrangères que si on le lui demande, connexion par
        // connexion ; PostgreSQL, toujours.
        $connexion->executeStatement('PRAGMA foreign_keys = ON');
        $connexion->executeStatement('DELETE FROM core_user WHERE id = ?', [$cobalt->getId()]);

        self::assertSame(
            ['https://fcm.googleapis.com/fcm/send/pyrite'],
            array_map(static fn (mixed $e): string => (string) $e, $connexion->fetchFirstColumn('SELECT endpoint FROM core_push_subscription')),
        );
    }

    // ─── Aides ───────────────────────────────────────────────────────────────────

    /** @return array{endpoint: string, keys: array{p256dh: string, auth: string}} */
    private function abonnement(string $endpoint): array
    {
        return [
            'endpoint' => $endpoint,
            'keys' => [
                'p256dh' => ClesVapid::base64Url("\x04".random_bytes(64)),
                'auth' => ClesVapid::base64Url(random_bytes(16)),
            ],
        ];
    }

    private function abonner(User $compte, string $endpoint): void
    {
        $cles = $this->abonnement($endpoint)['keys'];
        $em = $this->em();
        $utilisateur = $em->find(User::class, $compte->getId());
        self::assertInstanceOf(User::class, $utilisateur);

        $em->persist(new PushSubscription($utilisateur, $endpoint, $cles['p256dh'], $cles['auth']));
        $em->flush();
    }

    private function abonnements(): PushSubscriptionRepository
    {
        $depot = self::getContainer()->get(PushSubscriptionRepository::class);
        \assert($depot instanceof PushSubscriptionRepository);

        return $depot;
    }

    private function diffusion(): DiffusionPush
    {
        $diffusion = self::getContainer()->get(DiffusionPush::class);
        \assert($diffusion instanceof DiffusionPush);

        return $diffusion;
    }

    /** `kernel.terminate`, tel que le noyau le publie une fois la réponse rendue. */
    private function terminer(): void
    {
        $noyau = self::$kernel;
        self::assertNotNull($noyau);

        $dispatcheur = self::getContainer()->get('event_dispatcher');
        \assert($dispatcheur instanceof EventDispatcherInterface);

        $dispatcheur->dispatch(new TerminateEvent($noyau, Request::create('/'), new Response()), KernelEvents::TERMINATE);
    }

    private function service(): NotificationService
    {
        $service = self::getContainer()->get(NotificationService::class);
        \assert($service instanceof NotificationService);

        return $service;
    }
}
