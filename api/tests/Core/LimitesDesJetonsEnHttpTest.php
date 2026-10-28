<?php

declare(strict_types=1);

namespace App\Tests\Core;

use App\Module\Core\Service\GenerateurDeJetonDApi;
use App\Module\Core\Service\LimiteursDesJetonsDApi;
use Symfony\Component\HttpFoundation\Response;

/**
 * Les deux plafonds du jeton d'API en HTTP (ADR 0021, § 1.9 du contrat) : dix échecs par IP
 * sur quinze minutes, puis `429` avant même de chercher le jeton ; un plafond de requêtes
 * par jeton (12 en test, 2 000 en production : `app.core.jeton_api.requetes_par_heure`).
 *
 * `CoreWebTestCase` vide `cache.rate_limiter` à chaque test, et chaque test parle depuis une
 * IP tirée au hasard ; les requêtes d'une « autre » IP passent par {@see self::depuis()}.
 *
 * Aucun jeton n'est écrit en littéral : tous sortent du générateur.
 */
final class LimitesDesJetonsEnHttpTest extends CoreWebTestCase
{
    private const int LIMITE_DE_TEST = 12;

    public function testLeOnziemeEchecRendTropDeTentativesAvecRetryAfter(): void
    {
        for ($i = 1; $i <= LimiteursDesJetonsDApi::ECHECS_PAR_IP; ++$i) {
            $this->requete('GET', '/api/session', entetes: $this->bearer($this->jetonInconnu()));
            self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED, \sprintf('Échec n° %d', $i));
        }

        $this->requete('GET', '/api/session', entetes: $this->bearer($this->jetonInconnu()));

        self::assertResponseStatusCodeSame(Response::HTTP_TOO_MANY_REQUESTS);
        $retryAfter = $this->client->getResponse()->headers->get('Retry-After');
        self::assertNotNull($retryAfter);
        self::assertGreaterThan(0, (int) $retryAfter);

        $erreur = $this->charge()['erreur'];
        self::assertSame('trop_de_tentatives', $erreur['code']);
        self::assertSame((int) $retryAfter, $erreur['details']['retryAfter']);
        self::assertArrayHasKey('champs', $erreur);
        self::assertNull($erreur['champs']);
    }

    /** Le blocage précède la recherche du jeton : un attaquant bloqué ne teste plus rien. */
    public function testUneIpBloqueeEstRefuseeMemeAvecUnBonJeton(): void
    {
        $cobalt = $this->creerUtilisateur('cobalt@example.net');
        $bon = $this->creerJetonDApi($cobalt);

        for ($i = 0; $i < LimiteursDesJetonsDApi::ECHECS_PAR_IP; ++$i) {
            $this->requete('GET', '/api/session', entetes: $this->bearer($this->jetonInconnu()));
        }

        $this->requete('GET', '/api/session', entetes: $this->bearer($bon));

        self::assertResponseStatusCodeSame(Response::HTTP_TOO_MANY_REQUESTS);
        self::assertSame('trop_de_tentatives', $this->charge()['erreur']['code']);
    }

    public function testUneAutreIpNEstPasBloquee(): void
    {
        $cobalt = $this->creerUtilisateur('cobalt@example.net');
        $bon = $this->creerJetonDApi($cobalt);

        for ($i = 0; $i <= LimiteursDesJetonsDApi::ECHECS_PAR_IP; ++$i) {
            $this->requete('GET', '/api/session', entetes: $this->bearer($this->jetonInconnu()));
        }
        self::assertResponseStatusCodeSame(Response::HTTP_TOO_MANY_REQUESTS);

        $this->depuis('192.0.2.77', $bon);

        self::assertResponseIsSuccessful();
    }

    /** Un succès ne rend pas les essais : l'attaquant qui possède un jeton ne les efface pas. */
    public function testUnSuccesNeRemetPasLeCompteurAZero(): void
    {
        $cobalt = $this->creerUtilisateur('cobalt@example.net');
        $bon = $this->creerJetonDApi($cobalt);

        for ($i = 0; $i < LimiteursDesJetonsDApi::ECHECS_PAR_IP - 1; ++$i) {
            $this->requete('GET', '/api/session', entetes: $this->bearer($this->jetonInconnu()));
        }
        $this->requete('GET', '/api/session', entetes: $this->bearer($bon));
        self::assertResponseIsSuccessful();

        // Le dixième échec est le dernier admis ; le suivant est bloqué malgré le succès.
        $this->requete('GET', '/api/session', entetes: $this->bearer($this->jetonInconnu()));
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        $this->requete('GET', '/api/session', entetes: $this->bearer($bon));
        self::assertResponseStatusCodeSame(Response::HTTP_TOO_MANY_REQUESTS);
    }

    public function testLeDepassementDeLaLimiteParJetonRendTropDeTentatives(): void
    {
        $cobalt = $this->creerUtilisateur('cobalt@example.net');
        $jeton = $this->creerJetonDApi($cobalt);

        for ($i = 1; $i <= self::LIMITE_DE_TEST; ++$i) {
            $this->requete('GET', '/api/session', entetes: $this->bearer($jeton));
            self::assertResponseIsSuccessful(\sprintf('Requête n° %d', $i));
        }

        $this->requete('GET', '/api/session', entetes: $this->bearer($jeton));

        self::assertResponseStatusCodeSame(Response::HTTP_TOO_MANY_REQUESTS);
        self::assertGreaterThan(0, (int) $this->client->getResponse()->headers->get('Retry-After'));
        $erreur = $this->charge()['erreur'];
        self::assertSame('trop_de_tentatives', $erreur['code']);
        self::assertGreaterThan(0, $erreur['details']['retryAfter']);
    }

    public function testUnAutreJetonDuMemeCompteNEstPasTouche(): void
    {
        $cobalt = $this->creerUtilisateur('cobalt@example.net');
        $epuise = $this->creerJetonDApi($cobalt, libelle: 'Agent qui boucle');
        $autre = $this->creerJetonDApi($cobalt, libelle: 'Agent sage');

        for ($i = 0; $i <= self::LIMITE_DE_TEST; ++$i) {
            $this->requete('GET', '/api/session', entetes: $this->bearer($epuise));
        }
        self::assertResponseStatusCodeSame(Response::HTTP_TOO_MANY_REQUESTS);

        $this->requete('GET', '/api/session', entetes: $this->bearer($autre));

        self::assertResponseIsSuccessful();
    }

    /** Le cookie n'est compté par aucun des deux limiteurs. */
    public function testLeCookieNEstPasAffecte(): void
    {
        $this->creerUtilisateur('cobalt@example.net');
        $this->connexion('cobalt@example.net');
        self::assertResponseIsSuccessful();

        for ($i = 0; $i < self::LIMITE_DE_TEST + 3; ++$i) {
            $this->requete('GET', '/api/session');
            self::assertResponseIsSuccessful(\sprintf('Requête n° %d', $i + 1));
        }

        // Une IP bloquée pour ses jetons garde son cookie.
        for ($i = 0; $i <= LimiteursDesJetonsDApi::ECHECS_PAR_IP; ++$i) {
            $this->requete('GET', '/api/session', entetes: $this->bearer($this->jetonInconnu()));
        }
        self::assertResponseStatusCodeSame(Response::HTTP_TOO_MANY_REQUESTS);

        $this->requete('GET', '/api/session');
        self::assertResponseIsSuccessful();
    }

    /** @return array<string, string> */
    private function bearer(string $jeton): array
    {
        return ['Authorization' => 'Bearer '.$jeton];
    }

    /** Un jeton bien formé qu'aucune ligne ne connaît. */
    private function jetonInconnu(): string
    {
        $generateur = self::getContainer()->get(GenerateurDeJetonDApi::class);
        \assert($generateur instanceof GenerateurDeJetonDApi);

        return $generateur->generer()['jeton'];
    }

    /** `GET /api/session` avec ce jeton, depuis une autre adresse que celle du test. */
    private function depuis(string $ip, string $jeton): void
    {
        $this->client->request('GET', '/api/session', server: [
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$jeton,
            'REMOTE_ADDR' => $ip,
        ]);
    }
}
