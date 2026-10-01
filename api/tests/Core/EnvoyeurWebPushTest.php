<?php

declare(strict_types=1);

namespace App\Tests\Core;

use App\Module\Core\Entity\PushSubscription;
use App\Module\Core\Entity\User;
use App\Module\Core\Enum\ResultatDEnvoiPush;
use App\Module\Core\Service\ClesVapid;
use App\Module\Core\Service\EnvoyeurWebPush;
use App\Module\Core\Service\UrlPublique;
use Doctrine\DBAL\DriverManager;
use Minishlink\WebPush\VAPID;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * L'envoi réel — VAPID et chiffrement `aes128gcm` —, contre un client HTTP simulé.
 *
 * Les tests fonctionnels branchent un double à la place de cet envoyeur ; celui-ci
 * vérifie donc à part ce que le double ne peut pas dire : que la requête partie vers le
 * service push est bien formée (signature VAPID, codage, durée de vie), que le délai est
 * borné, et que les réponses du service sont traduites dans les trois issues que
 * `DiffusionPush` sait traiter — `410` et `404` en `Expire` surtout, puisque c'est ce
 * qui supprime un abonnement.
 */
final class EnvoyeurWebPushTest extends TestCase
{
    private function exigerOpenSslSansConfiguration(): void
    {
        // Le chiffrement de la bibliothèque crée une clé éphémère par `openssl_pkey_new()`,
        // sans repli : sur le PHP Windows du poste de développement, il faut
        // `OPENSSL_CONF` dans l'environnement. Le conteneur et la CI (Linux) l'ont toujours.
        $cle = @openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => \OPENSSL_KEYTYPE_EC]);
        while (false !== openssl_error_string()) {
        }

        if (false === $cle) {
            self::markTestSkipped('OpenSSL ne génère pas de clé EC sans configuration ici : poser OPENSSL_CONF (…/php/extras/ssl/openssl.cnf).');
        }
    }

    public function testLaRequeteEstSigneeChiffreeEtBorneeDansLeTemps(): void
    {
        $this->exigerOpenSslSansConfiguration();

        $vues = [];
        $http = new MockHttpClient(static function (string $methode, string $url, array $options) use (&$vues): MockResponse {
            $vues[] = ['methode' => $methode, 'url' => $url, 'options' => $options];

            return new MockResponse('', ['http_code' => 201]);
        });

        $resultats = $this->envoyeur($http)->envoyer(
            [['abonnement' => $this->abonnement('https://fcm.googleapis.com/fcm/send/abc'), 'charge' => '{"id":1,"titre":"Bonjour"}']],
            86_400,
        );

        self::assertSame(['https://fcm.googleapis.com/fcm/send/abc' => ResultatDEnvoiPush::Remis], $resultats);
        self::assertCount(1, $vues);
        self::assertSame('POST', $vues[0]['methode']);

        $entetes = implode("\n", $vues[0]['options']['headers']);
        self::assertStringContainsStringIgnoringCase('content-encoding: aes128gcm', $entetes);
        self::assertStringContainsStringIgnoringCase('ttl: 86400', $entetes);
        self::assertMatchesRegularExpression('/authorization: vapid t=[\w-]+\.[\w-]+\.[\w-]+, k=[\w-]+/i', $entetes);

        // Le corps est chiffré : le titre n'y apparaît pas en clair.
        self::assertStringNotContainsString('Bonjour', (string) $vues[0]['options']['body']);

        self::assertEquals(EnvoyeurWebPush::DELAI_SECONDES, $vues[0]['options']['timeout']);
        self::assertEquals(EnvoyeurWebPush::DELAI_SECONDES, $vues[0]['options']['max_duration']);
    }

    public function testLesReponsesDuServicePushSontTraduites(): void
    {
        $this->exigerOpenSslSansConfiguration();

        $statuts = [
            'https://push.example.net/ok' => 201,
            'https://push.example.net/parti' => 410,
            'https://push.example.net/inconnu' => 404,
            'https://push.example.net/panne' => 503,
            'https://push.example.net/refuse' => 403,
        ];

        $http = new MockHttpClient(static fn (string $methode, string $url): MockResponse => new MockResponse('', ['http_code' => $statuts[$url]]));

        $resultats = $this->envoyeur($http)->envoyer(
            array_map(fn (string $e): array => ['abonnement' => $this->abonnement($e), 'charge' => '{"id":1}'], array_keys($statuts)),
            60,
        );

        self::assertSame(ResultatDEnvoiPush::Remis, $resultats['https://push.example.net/ok']);
        self::assertSame(ResultatDEnvoiPush::Expire, $resultats['https://push.example.net/parti']);
        self::assertSame(ResultatDEnvoiPush::Expire, $resultats['https://push.example.net/inconnu']);
        self::assertSame(ResultatDEnvoiPush::Echec, $resultats['https://push.example.net/panne']);
        self::assertSame(ResultatDEnvoiPush::Echec, $resultats['https://push.example.net/refuse']);
    }

    public function testUnePanneReseauEstUnEchecEtNonUneExpiration(): void
    {
        $this->exigerOpenSslSansConfiguration();

        $http = new MockHttpClient(static fn (): MockResponse => new MockResponse('', ['error' => 'Connexion refusée.']));

        $resultats = $this->envoyeur($http)->envoyer([['abonnement' => $this->abonnement('https://push.example.net/a'), 'charge' => '{"id":1}']], 60);

        self::assertSame(['https://push.example.net/a' => ResultatDEnvoiPush::Echec], $resultats);
    }

    /**
     * Toute la requête en une salve : plusieurs notifications, plusieurs appareils, un
     * même appareil deux fois. Les requêtes partent toutes, et l'issue d'un appareil est
     * la plus décisive des siennes.
     */
    public function testUneSalveEnvoieChaqueMessageEtRetientLIssueLaPlusDecisive(): void
    {
        $this->exigerOpenSslSansConfiguration();

        $vues = 0;
        $http = new MockHttpClient(static function (string $methode, string $url) use (&$vues): MockResponse {
            ++$vues;

            return new MockResponse('', ['http_code' => str_ends_with($url, '/parti') ? 410 : 201]);
        });

        $a = $this->abonnement('https://push.example.net/a');
        $parti = $this->abonnement('https://push.example.net/parti');

        $resultats = $this->envoyeur($http)->envoyer([
            ['abonnement' => $a, 'charge' => '{"id":1}'],
            ['abonnement' => $parti, 'charge' => '{"id":1}'],
            ['abonnement' => $a, 'charge' => '{"id":2}'],
        ], 60);

        self::assertSame(3, $vues);
        self::assertSame(ResultatDEnvoiPush::Remis, $resultats['https://push.example.net/a']);
        self::assertSame(ResultatDEnvoiPush::Expire, $resultats['https://push.example.net/parti']);
    }

    public function testLIssueLaPlusDecisive(): void
    {
        self::assertSame(ResultatDEnvoiPush::Echec, ResultatDEnvoiPush::laPlusDecisive(null, ResultatDEnvoiPush::Echec));
        self::assertSame(ResultatDEnvoiPush::Remis, ResultatDEnvoiPush::laPlusDecisive(ResultatDEnvoiPush::Echec, ResultatDEnvoiPush::Remis));
        self::assertSame(ResultatDEnvoiPush::Remis, ResultatDEnvoiPush::laPlusDecisive(ResultatDEnvoiPush::Remis, ResultatDEnvoiPush::Echec));
        self::assertSame(ResultatDEnvoiPush::Expire, ResultatDEnvoiPush::laPlusDecisive(ResultatDEnvoiPush::Remis, ResultatDEnvoiPush::Expire));
        self::assertSame(ResultatDEnvoiPush::Expire, ResultatDEnvoiPush::laPlusDecisive(ResultatDEnvoiPush::Expire, ResultatDEnvoiPush::Remis));
    }

    public function testUnePaireGenereeEstUnePaireVapidValide(): void
    {
        $paire = ClesVapid::genererUnePaire();

        $valide = VAPID::validate(['subject' => 'mailto:a@example.net', 'publicKey' => $paire['publique'], 'privateKey' => $paire['privee']]);

        self::assertSame(65, \strlen($valide['publicKey']));
        self::assertSame(32, \strlen($valide['privateKey']));
        self::assertDoesNotMatchRegularExpression('/[=+\/]/', $paire['publique'], 'base64url non paddé');
    }

    private function envoyeur(MockHttpClient $http): EnvoyeurWebPush
    {
        $paire = ClesVapid::genererUnePaire();
        $cles = new ClesVapid(
            DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]),
            new UrlPublique('https://heritage.example.net'),
            $paire['publique'],
            $paire['privee'],
            'mailto:admin@example.net',
        );

        return new EnvoyeurWebPush($http, $cles, new NullLogger());
    }

    /** Un vrai point P-256 côté navigateur : le chiffrement en a besoin. */
    private function abonnement(string $endpoint): PushSubscription
    {
        return new PushSubscription(
            new User('cobalt@example.net', 'Cobalt'),
            $endpoint,
            ClesVapid::genererUnePaire()['publique'],
            ClesVapid::base64Url(random_bytes(16)),
        );
    }
}
