<?php

declare(strict_types=1);

namespace App\Tests\Core;

use App\Module\Core\Enum\PorteeDeJeton;
use App\Module\Core\EventSubscriber\JetonSansSessionSubscriber;
use App\Module\Core\Security\JetonDApiAuthentifie;
use App\Module\Core\Security\RequeteParJetonDApi;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Les trois pièces du pare-feu `api_jetons` qui se jugent sans HTTP (ADR 0021 § 4) : quelles
 * requêtes il prend, ce qu'il fait de leurs cookies, et la lecture fermée des attributs du
 * jeton.
 */
final class RequeteParJetonDApiTest extends TestCase
{
    /** @return iterable<string, array{string, string|null, bool}> */
    public static function requetes(): iterable
    {
        yield 'Bearer sur /api' => ['/api/session', 'Bearer abc', true];
        yield 'Bearer seul' => ['/api/session', 'Bearer', true];
        yield 'schéma en minuscules' => ['/api/arbre/graphe', 'bearer abc', true];
        yield 'sans en-tête' => ['/api/session', null, false];
        yield 'Basic, posé par un intermédiaire' => ['/api/session', 'Basic cHJlcHJvZDp4', false];
        yield 'schéma qui commence comme Bearer' => ['/api/session', 'Bearerabc', false];
        yield 'hors de /api' => ['/sante.php', 'Bearer abc', false];
    }

    #[DataProvider('requetes')]
    public function testLePareFeuNePrendQueLesRequetesApiAuSchemaBearer(string $chemin, ?string $entete, bool $attendu): void
    {
        $requete = Request::create($chemin);
        if (null !== $entete) {
            $requete->headers->set('Authorization', $entete);
        }

        self::assertSame($attendu, (new RequeteParJetonDApi())->matches($requete));
    }

    /**
     * Le Bearer fait foi, sans session : la requête perd ses cookies avant le pare-feu, et
     * la session part sans l'identifiant du cookie.
     */
    public function testUneRequeteParJetonPerdSesCookiesEtNaPasDeSessionPrecedente(): void
    {
        $requete = Request::create('/api/session', cookies: ['MOCKSESSID' => 'session-du-navigateur']);
        $requete->headers->set('Authorization', 'Bearer abc');
        $requete->headers->set('Cookie', 'MOCKSESSID=session-du-navigateur');
        $requete->setSession(new Session(new MockArraySessionStorage('MOCKSESSID')));
        self::assertTrue($requete->hasPreviousSession());

        $this->surRequete($requete);

        self::assertSame([], $requete->cookies->all());
        self::assertFalse($requete->headers->has('Cookie'));
        self::assertFalse($requete->hasPreviousSession());
    }

    public function testUneRequeteACookieGardeSesCookies(): void
    {
        $requete = Request::create('/api/session', cookies: ['MOCKSESSID' => 'session-du-navigateur']);
        $requete->headers->set('Authorization', 'Basic cHJlcHJvZDp4');

        $this->surRequete($requete);

        self::assertSame(['MOCKSESSID' => 'session-du-navigateur'], $requete->cookies->all());
    }

    public function testLesAttributsDuJetonFontLAllerRetour(): void
    {
        $jeton = new JetonDApiAuthentifie(7, 'Claude — agents', PorteeDeJeton::Contribution);

        self::assertEquals($jeton, JetonDApiAuthentifie::depuisAttributs($jeton->attributs()));
        self::assertNull(JetonDApiAuthentifie::depuisAttributs(null), 'Sans attributs, pas de jeton d’API.');
        self::assertNull(JetonDApiAuthentifie::depuisAttributs(['autre' => 1]), 'Une authentification ordinaire n’est pas un jeton d’API.');
    }

    /**
     * `null` voudrait dire « session ordinaire », donc tout ce qu'ouvre une session : un
     * jeton mal décrit ne doit pas y retomber.
     */
    public function testDesAttributsDeJetonIncompletsNeRetombentPasSurUneSession(): void
    {
        $this->expectException(\LogicException::class);

        JetonDApiAuthentifie::depuisAttributs([
            JetonDApiAuthentifie::ATTRIBUT_ID => 7,
            JetonDApiAuthentifie::ATTRIBUT_LIBELLE => 'Claude — agents',
            JetonDApiAuthentifie::ATTRIBUT_PORTEE => 'administration',
        ]);
    }

    private function surRequete(Request $requete): void
    {
        $noyau = $this->createStub(HttpKernelInterface::class);

        (new JetonSansSessionSubscriber(new RequeteParJetonDApi()))
            ->surRequete(new RequestEvent($noyau, $requete, HttpKernelInterface::MAIN_REQUEST));
    }
}
