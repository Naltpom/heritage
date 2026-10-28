<?php

declare(strict_types=1);

namespace App\Tests\Core;

use Symfony\Component\HttpFoundation\Response;

/**
 * Les en-têtes de durcissement, et la règle de cache du § 1.8 du contrat.
 */
final class EnTetesDeSecuriteTest extends CoreWebTestCase
{
    public function testLesEnTetesDeDurcissementSontPosesSurToutesLesReponsesApi(): void
    {
        $this->creerUtilisateur('cobalt@example.net');
        $this->connexion('cobalt@example.net');

        foreach (['/api/session', '/api/administration/journal'] as $chemin) {
            $this->requete('GET', $chemin);
            $entetes = $this->client->getResponse()->headers;

            self::assertSame('nosniff', $entetes->get('X-Content-Type-Options'), $chemin);
            self::assertSame('DENY', $entetes->get('X-Frame-Options'), $chemin);
            self::assertSame('no-referrer', $entetes->get('Referrer-Policy'), $chemin);
            self::assertSame('same-origin', $entetes->get('Cross-Origin-Opener-Policy'), $chemin);
            self::assertSame('same-origin', $entetes->get('Cross-Origin-Resource-Policy'), $chemin);
            self::assertStringContainsString("default-src 'none'", (string) $entetes->get('Content-Security-Policy'), $chemin);
            self::assertStringContainsString("frame-ancestors 'none'", (string) $entetes->get('Content-Security-Policy'), $chemin);
        }
    }

    /**
     * `no-store` et non `no-cache` : ces réponses contiennent des données de personnes
     * vivantes, elles n'ont rien à faire dans le cache disque d'un navigateur familial
     * partagé.
     */
    public function testLesReponsesApiNeSeMettentPasEnCache(): void
    {
        $this->creerUtilisateur('cobalt@example.net');
        $this->connexion('cobalt@example.net');
        $this->requete('GET', '/api/session');

        $entetes = $this->client->getResponse()->headers;

        // `no-store` seul, sans le `max-age=0, must-revalidate` que pose le listener de
        // session : la directive du § 1.8, et rien qui la contredise. Le `private` que
        // Symfony ajoute de lui-même à toute valeur sans `public` ni `s-maxage` est
        // conservé — il va dans le même sens.
        self::assertTrue($entetes->hasCacheControlDirective('no-store'));
        self::assertFalse($entetes->hasCacheControlDirective('public'));
        self::assertFalse($entetes->hasCacheControlDirective('max-age'));
        self::assertSame('Cookie, Authorization', $entetes->get('Vary'));
    }

    public function testLesEnTetesSontPosesAussiSurLesReponsesDErreur(): void
    {
        // Le cas qui compte : une réponse d'erreur non authentifiée ne doit pas être la
        // seule à sortir nue.
        $this->requete('GET', '/api/session');

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        self::assertSame('nosniff', $this->client->getResponse()->headers->get('X-Content-Type-Options'));
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
    }

    /** Même origine, sans exception : aucune route n'émet d'en-tête `Access-Control-*`. */
    public function testAucunEnTeteCorsNEstEmis(): void
    {
        $this->creerUtilisateur('cobalt@example.net');
        $this->connexion('cobalt@example.net');
        $this->requete('GET', '/api/session');

        foreach ($this->client->getResponse()->headers->all() as $nom => $valeurs) {
            self::assertStringStartsNotWith('access-control-', strtolower($nom));
        }
    }

    public function testUneRouteInconnueSousApiRendDuJsonEtNonDuHtml(): void
    {
        $this->requete('GET', '/api/chemin-qui-nexiste-pas');

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        self::assertStringStartsWith(
            'application/json',
            (string) $this->client->getResponse()->headers->get('Content-Type'),
        );
        self::assertSame('introuvable', $this->charge()['erreur']['code']);
    }

    public function testUneMauvaiseMethodeRend405AvecLesMethodesAutorisees(): void
    {
        $this->creerUtilisateur('cobalt@example.net');
        $this->connexion('cobalt@example.net');

        $this->requete('DELETE', '/api/session');

        self::assertResponseStatusCodeSame(Response::HTTP_METHOD_NOT_ALLOWED);
        $erreur = $this->charge()['erreur'];
        self::assertSame('methode_non_autorisee', $erreur['code']);
        self::assertContains('GET', $erreur['details']['autorisees']);
    }
}
