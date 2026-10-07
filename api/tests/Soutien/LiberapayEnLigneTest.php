<?php

declare(strict_types=1);

namespace App\Tests\Soutien;

use App\Module\Soutien\Service\LiberapayEnLigne;
use App\Module\Soutien\Service\LiberapayIndisponible;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * La lecture du profil public Liberapay, **sans réseau** : `MockHttpClient` pour le tiers.
 */
final class LiberapayEnLigneTest extends TestCase
{
    public function testLitLeMontantHebdomadaireEnCentimesEtLeNombreDeDonateurs(): void
    {
        $urls = [];
        $client = new MockHttpClient(static function (string $methode, string $url) use (&$urls): MockResponse {
            $urls[] = $methode.' '.$url;

            return new MockResponse(json_encode([
                'receiving' => ['amount' => '829.48', 'currency' => 'EUR'],
                'npatrons' => 1817,
                'goal' => ['amount' => '1000.00', 'currency' => 'EUR'],
            ], \JSON_THROW_ON_ERROR));
        });

        $profil = (new LiberapayEnLigne($client))->profil('Naltpom');

        self::assertSame(['GET https://liberapay.com/Naltpom/public.json'], $urls, 'Seul le pseudo sort, dans le chemin.');
        self::assertSame(82948, $profil->recuParSemaineCentimes);
        self::assertSame('EUR', $profil->devise);
        self::assertSame(1817, $profil->donateurs);
    }

    public function testUnMontantSansDecimalesEstDesEurosRonds(): void
    {
        $profil = (new LiberapayEnLigne($this->repondant(['receiving' => ['amount' => '5', 'currency' => 'EUR'], 'npatrons' => 1])))->profil('x');

        self::assertSame(500, $profil->recuParSemaineCentimes);
    }

    public function testUnMontantMasqueEstUnePanne(): void
    {
        // Le propriétaire peut cacher ce qu'il reçoit : on ne l'invente pas à zéro.
        $this->expectException(LiberapayIndisponible::class);

        (new LiberapayEnLigne($this->repondant(['receiving' => null, 'npatrons' => 3])))->profil('x');
    }

    public function testUnPseudoInconnuEstUnePanne(): void
    {
        $this->expectException(LiberapayIndisponible::class);

        (new LiberapayEnLigne(new MockHttpClient(new MockResponse('introuvable', ['http_code' => 404]))))->profil('personne');
    }

    public function testUnMontantIllisibleEstUnePanne(): void
    {
        $this->expectException(LiberapayIndisponible::class);

        (new LiberapayEnLigne($this->repondant(['receiving' => ['amount' => '-3.00', 'currency' => 'EUR']])))->profil('x');
    }

    /** @param array<string, mixed> $corps */
    private function repondant(array $corps): MockHttpClient
    {
        return new MockHttpClient(new MockResponse(json_encode($corps, \JSON_THROW_ON_ERROR)));
    }
}
