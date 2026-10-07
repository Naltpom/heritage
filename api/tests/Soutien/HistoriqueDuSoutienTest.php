<?php

declare(strict_types=1);

namespace App\Tests\Soutien;

use App\Module\Soutien\Entity\Releve;
use App\Module\Soutien\Service\BilanDuSoutien;
use PHPUnit\Framework\TestCase;

/**
 * Le calcul des mois et des années à partir des relevés quotidiens — sans base.
 *
 * La règle : chaque jour vaut un septième du dernier relevé connu à cette date, et un jour
 * sans relevé garde le rythme de la veille.
 */
final class HistoriqueDuSoutienTest extends TestCase
{
    public function testUnRythmeConstantDonneLeMoisEntier(): void
    {
        // 7 € par semaine = 1 € par jour : 31 € en octobre, 30 € en novembre.
        [$mois, $annees] = BilanDuSoutien::historique(
            [$this->releve('2026-10-01', 700)],
            new \DateTimeImmutable('2026-11-30'),
        );

        self::assertSame([
            ['mois' => '2026-11', 'centimes' => 3000, 'complet' => false],
            ['mois' => '2026-10', 'centimes' => 3100, 'complet' => true],
        ], $mois);
        self::assertSame([['annee' => 2026, 'centimes' => 6100]], $annees);
    }

    public function testLeReleveSuivantPrendLeRelais(): void
    {
        // 1 € par jour du 1er au 9, puis 2 € par jour du 10 au 12 : 9 + 6 = 15 €.
        [$mois] = BilanDuSoutien::historique(
            [$this->releve('2026-10-01', 700), $this->releve('2026-10-10', 1400)],
            new \DateTimeImmutable('2026-10-12'),
        );

        self::assertSame([['mois' => '2026-10', 'centimes' => 1500, 'complet' => false]], $mois);
    }

    public function testLesAnneesSeSeparent(): void
    {
        [, $annees] = BilanDuSoutien::historique(
            [$this->releve('2026-12-30', 700)],
            new \DateTimeImmutable('2027-01-02'),
        );

        self::assertSame([
            ['annee' => 2027, 'centimes' => 200],
            ['annee' => 2026, 'centimes' => 200],
        ], $annees);
    }

    public function testSansReleveIlNYARien(): void
    {
        self::assertSame([[], []], BilanDuSoutien::historique([], new \DateTimeImmutable('2026-10-07')));
    }

    public function testLeRythmeMensuelCompteCinquanteDeuxSemaines(): void
    {
        // 10 € par semaine × 52 / 12 = 43,33 €.
        self::assertSame(4333, BilanDuSoutien::parMois(1000));
    }

    private function releve(string $jour, int $centimesParSemaine): Releve
    {
        $date = new \DateTimeImmutable($jour);

        return new Releve('Naltpom', $date, $centimesParSemaine, 'EUR', 2, $date);
    }
}
