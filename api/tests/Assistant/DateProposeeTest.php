<?php

declare(strict_types=1);

namespace App\Tests\Assistant;

use App\Module\Assistant\Service\DateProposee;
use App\Module\Proposal\Enum\ValueKind;
use App\Module\Proposal\Service\NormaliseurDeValeur;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * La traduction d'une date décrite par le modèle en bloc date d'une demande.
 *
 * Deux choses sont éprouvées, et la seconde compte autant que la première :
 *
 * 1. **le bloc est celui que le formulaire aurait produit** — mêmes `raw`, mêmes bornes,
 *    même qualificatif (`app/src/components/proposition/dates.ts::composerBlocDate()`) ;
 * 2. **il passe le normaliseur du dépôt** tel quel. Un bloc que `NormaliseurDeValeur`
 *    refuse serait une demande écartée au moment de la vérifier, c'est-à-dire une
 *    information du membre perdue pour une faute de forme.
 */
final class DateProposeeTest extends TestCase
{
    /**
     * @return iterable<string, array{array<string, mixed>, array{raw: string|null, qualifier: string, min: string|null, max: string|null, place: string|null}}>
     */
    public static function datesExploitables(): iterable
    {
        yield 'une année' => [
            ['qualificatif' => 'annee', 'date' => '1921'],
            ['raw' => '1921', 'qualifier' => 'annee', 'min' => '1921-01-01', 'max' => '1921-12-31', 'place' => null],
        ];
        yield 'un jour et un lieu' => [
            ['qualificatif' => 'exacte', 'date' => '1921-03-03', 'lieu' => ' Brest '],
            ['raw' => '3 MAR 1921', 'qualifier' => 'exacte', 'min' => '1921-03-03', 'max' => '1921-03-03', 'place' => 'Brest'],
        ];
        yield '« exacte » sur une année seule : la précision écrite décide' => [
            ['qualificatif' => 'exacte', 'date' => '1921'],
            ['raw' => '1921', 'qualifier' => 'annee', 'min' => '1921-01-01', 'max' => '1921-12-31', 'place' => null],
        ];
        yield '« annee » avec un jour : on garde la plus grossière, le jour a pu être inventé' => [
            ['qualificatif' => 'annee', 'date' => '1921-03-03'],
            ['raw' => '1921', 'qualifier' => 'annee', 'min' => '1921-01-01', 'max' => '1921-12-31', 'place' => null],
        ];
        yield 'un mois bissextile' => [
            ['qualificatif' => 'mois', 'date' => '1924-02'],
            ['raw' => 'FEB 1924', 'qualifier' => 'mois', 'min' => '1924-02-01', 'max' => '1924-02-29', 'place' => null],
        ];
        yield 'vers : jamais élargi' => [
            ['qualificatif' => 'environ', 'date' => '1889'],
            ['raw' => 'ABT 1889', 'qualifier' => 'environ', 'min' => '1889-01-01', 'max' => '1889-12-31', 'place' => null],
        ];
        yield 'avant : la borne est exclue' => [
            ['qualificatif' => 'avant', 'date' => '1920'],
            ['raw' => 'BEF 1920', 'qualifier' => 'avant', 'min' => null, 'max' => '1919-12-31', 'place' => null],
        ];
        yield 'après : la borne est exclue' => [
            ['qualificatif' => 'apres', 'date' => '1900'],
            ['raw' => 'AFT 1900', 'qualifier' => 'apres', 'min' => '1901-01-01', 'max' => null, 'place' => null],
        ];
        yield 'une fourchette' => [
            ['qualificatif' => 'entre', 'date' => '1901', 'date_fin' => '1909'],
            ['raw' => 'BET 1901 AND 1909', 'qualifier' => 'entre', 'min' => '1901-01-01', 'max' => '1909-12-31', 'place' => null],
        ];
        yield 'un lieu sans date' => [
            ['lieu' => 'Trochu'],
            ['raw' => null, 'qualifier' => 'inconnue', 'min' => null, 'max' => null, 'place' => 'Trochu'],
        ];
    }

    /**
     * @param array<string, mixed>                                                                          $date
     * @param array{raw: string|null, qualifier: string, min: string|null, max: string|null, place: string|null} $attendu
     */
    #[DataProvider('datesExploitables')]
    public function testLeBlocEstCeluiDuFormulaireEtPasseLeNormaliseurDuDepot(array $date, array $attendu): void
    {
        $bloc = DateProposee::versBloc($date);

        self::assertSame($attendu, $bloc);

        $normaliseur = new NormaliseurDeValeur();
        self::assertSame(
            $attendu,
            $normaliseur->versJson(ValueKind::DateIncertaine, $normaliseur->versPhp(ValueKind::DateIncertaine, $bloc, 'birth')),
            'Le bloc doit traverser le normaliseur du dépôt sans être refusé ni réécrit.',
        );
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function datesInexploitables(): iterable
    {
        yield 'un 30 février' => [['qualificatif' => 'exacte', 'date' => '1921-02-30']];
        yield 'une phrase' => [['qualificatif' => 'environ', 'date' => 'vers 1920']];
        yield 'une fourchette d’une seule année' => [['qualificatif' => 'entre', 'date' => '1901', 'date_fin' => '1901']];
        yield 'une fourchette à l’envers' => [['qualificatif' => 'entre', 'date' => '1909', 'date_fin' => '1901']];
        yield 'une fourchette sans fin' => [['qualificatif' => 'entre', 'date' => '1901']];
        yield 'l’avenir' => [['qualificatif' => 'annee', 'date' => '2999']];
        yield 'rien' => [[]];
        yield 'pas un objet' => ['1921'];
    }

    #[DataProvider('datesInexploitables')]
    public function testUneDateInexploitableNeDevientPasUneDateApproximative(mixed $date): void
    {
        self::assertNull(DateProposee::versBloc($date));
    }
}
