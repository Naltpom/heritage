<?php

declare(strict_types=1);

namespace App\Tests\Schema;

use App\Module\Core\Enum\DateQualifier;
use App\Module\Core\ValueObject\UncertainDate;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Le verrou contre l'aplatissement des fourchettes, vérifié à l'endroit où il se pose.
 *
 * Le § 4 du dossier de mission en fait une règle de fond : « ne jamais aplatir une
 * fourchette en date ferme, ni en base, ni à l'écran ». Trois verrous superposés la
 * tiennent — aucune colonne `*_date` où écrire un point, une contrainte `CHECK` qui
 * refuse `qualifier = 'entre'` avec `min = max`, et **l'absence de tout accesseur qui
 * rendrait une date unique**.
 *
 * Ce dernier est le plus important des trois parce que c'est celui que le développeur
 * rencontre en premier : si `getDate()` existait, quelqu'un l'appellerait. Ce test le
 * garde, parce qu'une API qu'on ne vérifie pas se voit ajouter une méthode de confort un
 * jour de fatigue.
 */
final class UncertainDateTest extends TestCase
{
    /**
     * @return list<array{string}>
     */
    public static function methodesInterdites(): array
    {
        return [['getDate'], ['getYear'], ['getAnnee'], ['__toString'], ['toDateTime']];
    }

    #[DataProvider('methodesInterdites')]
    public function testAucunAccesseurNeRendUneDateUnique(string $methode): void
    {
        self::assertFalse(
            method_exists(UncertainDate::class, $methode),
            \sprintf(
                'UncertainDate::%s() ne doit pas exister : une fourchette deviendrait une date '
                .'ferme au premier appel. Un appelant qui a besoin d\'un point le calcule '
                .'lui-même depuis getMin() et getMax(), et assume ce choix là où il le fait.',
                $methode,
            ),
        );
    }

    public function testUneFourchetteNeSeRendJamaisCommeUnPoint(): void
    {
        $entre = new UncertainDate(
            'BET 1901 AND 1909',
            DateQualifier::Entre,
            new \DateTimeImmutable('1901-01-01'),
            new \DateTimeImmutable('1909-12-31'),
        );

        self::assertTrue($entre->isRange());
        self::assertSame('entre 1901 et 1909', $entre->format());
    }

    /**
     * La table de conversion du § 0.5, prise à l'envers : ce que `format()` doit rendre
     * pour chacune des formes GEDCOM rencontrées dans le fichier réel.
     *
     * @return list<array{UncertainDate, string}>
     */
    public static function rendus(): array
    {
        return [
            [new UncertainDate('11 MAR 1930', DateQualifier::Exacte, new \DateTimeImmutable('1930-03-11'), new \DateTimeImmutable('1930-03-11')), '11 mars 1930'],
            [new UncertainDate('1930', DateQualifier::Annee, new \DateTimeImmutable('1930-01-01'), new \DateTimeImmutable('1930-12-31')), '1930'],
            [new UncertainDate('ABT 1934', DateQualifier::Environ, new \DateTimeImmutable('1934-01-01'), new \DateTimeImmutable('1934-12-31')), 'vers 1934'],
            [new UncertainDate('JAN 1920', DateQualifier::Mois, new \DateTimeImmutable('1920-01-01'), new \DateTimeImmutable('1920-01-31')), 'janvier 1920'],
            [new UncertainDate('BEF 1920', DateQualifier::Avant, null, new \DateTimeImmutable('1919-12-31')), 'avant 1920'],
            [new UncertainDate('FROM 15 JUN 1900', DateQualifier::Depuis, new \DateTimeImmutable('1900-06-15'), null), 'depuis le 15 juin 1900'],
            [UncertainDate::inconnue(), ''],
            // `1 JAN 1900` est une date exacte : la granularité vient du qualificatif et
            // non des composantes, sinon un 1er janvier se rendrait « 1900 ».
            [new UncertainDate('1 JAN 1900', DateQualifier::Exacte, new \DateTimeImmutable('1900-01-01'), new \DateTimeImmutable('1900-01-01')), '1 janvier 1900'],
        ];
    }

    #[DataProvider('rendus')]
    public function testLeRenduFrancaisSuitLaTableDeConversion(UncertainDate $date, string $attendu): void
    {
        self::assertSame($attendu, $date->format());
    }

    public function testUneDateInconnueNeRecouvreRien(): void
    {
        $connue = new UncertainDate('1930', DateQualifier::Annee, new \DateTimeImmutable('1930-01-01'), new \DateTimeImmutable('1930-12-31'));

        // Une date sans aucune borne recouvrirait *tout* si on la laissait faire, et
        // l'appelant croirait avoir appris quelque chose. Elle ne recouvre rien.
        self::assertFalse($connue->overlaps(UncertainDate::inconnue()));
        self::assertFalse(UncertainDate::inconnue()->overlaps($connue));
    }

    public function testDeuxFourchettesQuiSeCroisentSeRecouvrent(): void
    {
        $premiere = new UncertainDate('BET 1901 AND 1909', DateQualifier::Entre, new \DateTimeImmutable('1901-01-01'), new \DateTimeImmutable('1909-12-31'));
        $seconde = new UncertainDate('ABT 1906', DateQualifier::Environ, new \DateTimeImmutable('1906-01-01'), new \DateTimeImmutable('1906-12-31'));
        $ailleurs = new UncertainDate('1930', DateQualifier::Annee, new \DateTimeImmutable('1930-01-01'), new \DateTimeImmutable('1930-12-31'));

        self::assertTrue($premiere->overlaps($seconde));
        self::assertTrue($seconde->overlaps($premiere));
        self::assertFalse($premiere->overlaps($ailleurs));

        // Une borne nulle est une borne ouverte, donc infinie de ce côté-là.
        $avant1920 = new UncertainDate('BEF 1920', DateQualifier::Avant, null, new \DateTimeImmutable('1919-12-31'));
        self::assertTrue($avant1920->overlaps($premiere));
        self::assertFalse($avant1920->overlaps($ailleurs));
    }

    public function testUneDateSansQualificatifNEstPasConnue(): void
    {
        self::assertFalse(UncertainDate::inconnue()->isKnown());
        self::assertFalse(UncertainDate::inconnue()->isRange());
    }
}
