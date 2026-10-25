<?php

declare(strict_types=1);

namespace App\Tests\Core;

use App\Module\Core\Enum\PorteeDeJeton;
use App\Module\Core\Service\GenerateurDeJetonDApi;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Le **jeton d'API** (ADR 0021) : sa forme, son empreinte, son préfixe. Sans base ni noyau —
 * le générateur n'a aucune dépendance.
 */
final class GenerateurDeJetonDApiTest extends TestCase
{
    private GenerateurDeJetonDApi $generateur;

    protected function setUp(): void
    {
        $this->generateur = new GenerateurDeJetonDApi();
    }

    public function testUnJetonPorteLePrefixeEtQuaranteTroisSignesBase64url(): void
    {
        $jeton = $this->generateur->generer()['jeton'];

        self::assertStringStartsWith('hrt_', $jeton);
        self::assertSame(4 + 43, \strlen($jeton));
        self::assertMatchesRegularExpression('/^hrt_[A-Za-z0-9_-]{43}$/', $jeton);
        self::assertTrue($this->generateur->formeValide($jeton));
    }

    public function testQuelquesTiragesNeSeRepetentJamais(): void
    {
        $jetons = [];
        for ($i = 0; $i < 50; ++$i) {
            $jetons[] = $this->generateur->generer()['jeton'];
        }

        self::assertCount(50, array_unique($jetons));
    }

    public function testLEmpreinteEstLeSha256DuJetonEtLePrefixeSonDebut(): void
    {
        $tirage = $this->generateur->generer();

        self::assertSame(hash('sha256', $tirage['jeton']), $tirage['empreinte']);
        self::assertSame($tirage['empreinte'], $this->generateur->empreinte($tirage['jeton']));
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $tirage['empreinte']);
        self::assertSame(substr($tirage['jeton'], 0, 12), $tirage['prefixe']);
        self::assertNotSame($tirage['jeton'], $tirage['prefixe']);
    }

    /** @return iterable<string, array{string}> */
    public static function formesInvalides(): iterable
    {
        yield 'vide' => [''];
        yield 'sans préfixe' => [str_repeat('a', 43)];
        yield 'mauvais préfixe' => ['abc_'.str_repeat('a', 43)];
        yield 'trop court' => ['hrt_'.str_repeat('a', 42)];
        yield 'trop long' => ['hrt_'.str_repeat('a', 44)];
        yield 'caractère hors base64url' => ['hrt_'.str_repeat('a', 42).'+'];
        yield 'avec remplissage' => ['hrt_'.str_repeat('a', 42).'='];
        yield 'saut de ligne final' => ['hrt_'.str_repeat('a', 43)."\n"];
    }

    #[DataProvider('formesInvalides')]
    public function testUneFormeInvalideEstRefusee(string $jeton): void
    {
        self::assertFalse($this->generateur->formeValide($jeton));
    }

    public function testLesPorteesSontEmboiteesDuPlusFaibleAuPlusFort(): void
    {
        self::assertTrue(PorteeDeJeton::Validation->couvre(PorteeDeJeton::Contribution));
        self::assertTrue(PorteeDeJeton::Contribution->couvre(PorteeDeJeton::Lecture));
        self::assertTrue(PorteeDeJeton::Lecture->couvre(PorteeDeJeton::Lecture));
        self::assertFalse(PorteeDeJeton::Lecture->couvre(PorteeDeJeton::Contribution));
        self::assertFalse(PorteeDeJeton::Contribution->couvre(PorteeDeJeton::Validation));
    }
}
