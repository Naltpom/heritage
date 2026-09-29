<?php

declare(strict_types=1);

namespace App\Tests\Media;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Les six routes existent, et **aucune ne sort un octet sans session**.
 *
 * Le seul test du module qui démarre le noyau : il vérifie ce que les tests unitaires ne
 * peuvent pas voir — que les routes sont bien déclarées, et que la couche HTTP refuse
 * l'anonyme avec l'enveloppe du § 15.1 plutôt qu'avec une page d'erreur HTML.
 *
 * Il ne s'authentifie pas : au moment où ce module est écrit, `security.yaml` est encore
 * l'état minimal du squelette et il n'existe ni pare-feu `json_login` ni `access_control`.
 * C'est justement ce qui rend ce cas intéressant — la garde qui répond ici est **celle du
 * contrôleur**, pas celle de la configuration. Quand la tâche « sécurité et comptes »
 * aura posé l'`access_control`, c'est le point d'entrée 401 de `Core` qui répondra le
 * premier, avec la même enveloppe et le même code : le test continuera de passer, et
 * c'est le but.
 */
final class RoutesMediaTest extends WebTestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function routes(): iterable
    {
        yield 'téléverser' => ['POST', '/api/medias'];
        yield 'lister' => ['GET', '/api/medias?subjectKind=personne&subjectId=17'];
        yield 'détail' => ['GET', '/api/medias/120'];
        yield 'fichier' => ['GET', '/api/medias/120/fichier'];
        yield 'vignette' => ['GET', '/api/medias/120/vignette'];
        yield 'supprimer' => ['DELETE', '/api/medias/120'];
        yield 'pivoter' => ['POST', '/api/medias/120/pivoter'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('routes')]
    public function testAucuneRouteNeRepondSansSession(string $methode, string $chemin): void
    {
        $client = self::createClient();
        $client->request($methode, $chemin);

        $reponse = $client->getResponse();

        self::assertSame(401, $reponse->getStatusCode(), $methode.' '.$chemin);
        self::assertStringStartsWith('application/json', (string) $reponse->headers->get('Content-Type'));

        $charge = json_decode((string) $reponse->getContent(), true, 8, \JSON_THROW_ON_ERROR);
        self::assertIsArray($charge);
        self::assertArrayHasKey('erreur', $charge);
        self::assertIsArray($charge['erreur']);

        // L'enveloppe unique du § 15.1 : quatre clés, `champs` et `details` présents à
        // `null` plutôt qu'absents — la seule exception assumée à la règle « absent
        // contre null », parce qu'un front obligé de tester deux présences finira par
        // en oublier une.
        self::assertSame(['code', 'message', 'champs', 'details'], array_keys($charge['erreur']));
        self::assertSame('authentification_requise', $charge['erreur']['code']);
        self::assertNull($charge['erreur']['champs']);

        // Et rien de tout cela ne va dans le cache d'un navigateur familial partagé.
        self::assertStringContainsString('no-store', (string) $reponse->headers->get('Cache-Control'));
    }

    public function testUneMethodeInconnueNeCreePasDeRouteFantome(): void
    {
        $client = self::createClient();
        $client->request('PUT', '/api/medias/120');

        // 405 (le routeur connaît le chemin, pas la méthode) ou 401 selon l'ordre des
        // couches ; ce qui importe est qu'un `PUT` ne serve jamais un média.
        self::assertContains($client->getResponse()->getStatusCode(), [401, 405]);
    }

    public function testLIdentifiantDoitEtreUnEntier(): void
    {
        // `requirements: ['id' => '\d+']` : sans cette contrainte, `/api/medias/abc`
        // atteindrait le contrôleur et `/api/medias/{id}` entrerait en concurrence avec
        // d'éventuels chemins nommés ajoutés plus tard.
        $client = self::createClient();
        $client->request('GET', '/api/medias/abc/fichier');

        self::assertSame(404, $client->getResponse()->getStatusCode());
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        self::ensureKernelShutdown();
    }
}
