<?php

declare(strict_types=1);

namespace App\Tests\Noel;

use App\Module\Core\Service\ConnexionGoogle;
use App\Module\Noel\Service\TirageAuSortNoel;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * La vérification d'un ID token Google, le jeton d'inscription signé, et le tirage au
 * sort lui-même — sans base ni réseau.
 */
final class ConnexionGoogleTest extends TestCase
{
    private const string CLIENT = 'client-de-test.apps.googleusercontent.com';

    public function testUnIdTokenValideRendLeProfil(): void
    {
        $google = $this->google(['aud' => self::CLIENT] + $this->revendications());
        $profil = $google->verifier('jeton');

        self::assertNotNull($profil);
        self::assertSame('1234567890', $profil->sub);
        self::assertSame('lea@example.com', $profil->email);
        self::assertSame(['Léa', 'Martin'], [$profil->prenom, $profil->nom]);
        self::assertSame('https://lh3.googleusercontent.com/a/photo', $profil->photo);
    }

    public function testUneAudienceUnEmetteurOuUnCourrielNonVerifieSontRefuses(): void
    {
        self::assertNull($this->google(['aud' => 'un-autre-client'] + $this->revendications())->verifier('jeton'));
        self::assertNull($this->google(['aud' => self::CLIENT, 'iss' => 'evil.example'] + $this->revendications())->verifier('jeton'));
        self::assertNull($this->google(['aud' => self::CLIENT, 'email_verified' => 'false'] + $this->revendications())->verifier('jeton'));
        self::assertNull($this->google(['aud' => self::CLIENT, 'exp' => (string) (time() - 10)] + $this->revendications())->verifier('jeton'));
        self::assertNull((new ConnexionGoogle(new MockHttpClient(), '', 'secret'))->verifier('jeton'), 'Sans client OAuth, Google est désactivé.');
    }

    public function testLeJetonDInscriptionEstSigneEtExpire(): void
    {
        $google = $this->google(['aud' => self::CLIENT] + $this->revendications());
        $profil = $google->verifier('jeton');
        self::assertNotNull($profil);

        $jeton = $google->jetonDInscription($profil);
        self::assertSame('lea@example.com', $google->lireLeJeton($jeton)?->email);

        [$charge, $signature] = explode('.', $jeton);
        self::assertNull($google->lireLeJeton($charge.'x.'.$signature), 'Un jeton modifié est refusé.');
        self::assertNull((new ConnexionGoogle(new MockHttpClient(), self::CLIENT, 'autre-secret'))->lireLeJeton($jeton));
        self::assertNull($google->lireLeJeton($jeton, time() + 3600), 'Un jeton expiré est refusé.');
    }

    public function testLeTirageRespecteSoiEtConjointsEtRefuseLImpossible(): void
    {
        $tirage = new TirageAuSortNoel();

        for ($graine = 0; $graine < 50; ++$graine) {
            $resultat = $tirage->tirer([1, 2, 3, 4, 5], [[1, 2], [3, 4]], new Randomizer(new Mt19937($graine)));
            self::assertNotNull($resultat);
            self::assertCount(5, $resultat);
            self::assertCount(5, array_unique($resultat));
            foreach ($resultat as $donneur => $receveur) {
                self::assertNotSame($donneur, $receveur);
            }
            self::assertNotSame(2, $resultat[1]);
            self::assertNotSame(1, $resultat[2]);
            self::assertNotSame(4, $resultat[3]);
            self::assertNotSame(3, $resultat[4]);
        }

        self::assertNull($tirage->tirer([1, 2], [], new Randomizer()), 'À deux, pas de tirage.');
        // Trois participants dont deux conjoints : 1 et 2 ne peuvent tirer que 3.
        self::assertNull($tirage->tirer([1, 2, 3], [[1, 2]], new Randomizer()));
    }

    /** @param array<string, mixed> $revendications */
    private function google(array $revendications): ConnexionGoogle
    {
        return new ConnexionGoogle(new MockHttpClient(new MockResponse((string) json_encode($revendications))), self::CLIENT, 'secret');
    }

    /** @return array<string, string> */
    private function revendications(): array
    {
        return [
            'iss' => 'https://accounts.google.com',
            'sub' => '1234567890',
            'email' => 'Lea@Example.com',
            'email_verified' => 'true',
            'given_name' => 'Léa',
            'family_name' => 'Martin',
            'picture' => 'https://lh3.googleusercontent.com/a/photo',
            'exp' => (string) (time() + 600),
        ];
    }
}
