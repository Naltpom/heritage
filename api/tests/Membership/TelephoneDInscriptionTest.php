<?php

declare(strict_types=1);

namespace App\Tests\Membership;

use App\Module\Membership\Service\ServiceDInscription;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Le téléphone **facultatif** de l'inscription : rien donné, rien gardé ; donné, il est
 * nettoyé et doit compter de 6 à 15 chiffres. Il ne sert qu'à l'annuaire de
 * l'administrateur.
 */
final class TelephoneDInscriptionTest extends TestCase
{
    /** @return iterable<string, array{mixed, string|null, string|null}> */
    public static function cas(): iterable
    {
        yield 'absent' => [null, null, null];
        yield 'vide' => ['   ', null, null];
        yield 'français' => ['06 12 34 56 78', '06 12 34 56 78', null];
        yield 'international' => [' +33  6.12.34.56.78 ', '+33 6.12.34.56.78', null];
        yield 'avec tirets et parenthèses' => ['(0)6-12-34-56-78', '(0)6-12-34-56-78', null];
        yield 'trop court' => ['12 34', null, 'Numéro de téléphone invalide.'];
        yield 'trop long' => ['+33 6 12 34 56 78 90 12 34', null, 'Numéro de téléphone invalide.'];
        yield 'lettres' => ['06 12 AB 56 78', null, 'Numéro de téléphone invalide.'];
        yield 'pas une chaîne' => [612345678, null, 'Numéro de téléphone invalide.'];
    }

    #[DataProvider('cas')]
    public function testLeTelephoneEstFacultatifEtNettoye(mixed $brut, ?string $attendu, ?string $refus): void
    {
        self::assertSame([$attendu, $refus], ServiceDInscription::lireTelephone($brut));
    }
}
