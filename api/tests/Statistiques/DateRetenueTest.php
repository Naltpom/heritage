<?php

declare(strict_types=1);

namespace App\Tests\Statistiques;

use App\Module\Statistiques\Service\DateRetenue;
use App\Module\Statistiques\Service\Echantillon;
use App\Tests\Statistiques\Double\ReleveEnMemoire;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * La règle des dates des statistiques (décision du 02/10/2026), et le seuil de discrétion.
 *
 * > Une date floue ou inconnue ne compte pas. Une date exacte compte. Une année seule
 * > compte pour les calculs à l'année, pas pour ce qui demande le mois.
 */
final class DateRetenueTest extends TestCase
{
    /** @return iterable<string, array{string, int|null, int|null}> */
    public static function dates(): iterable
    {
        yield 'exacte : année et mois' => ['14 MAR 1850', 1850, 3];
        yield 'mois et année : année et mois' => ['MAR 1850', 1850, 3];
        yield 'année seule : l’année, pas le mois' => ['1850', 1850, null];
        yield 'environ : rien' => ['ABT 1850', null, null];
        yield 'avant : rien' => ['BEF 1850', null, null];
        yield 'entre : rien' => ['BET 1850 AND 1860', null, null];
        yield 'inconnue : rien' => ['', null, null];
    }

    #[DataProvider('dates')]
    public function testCeQuiCompte(string $gedcom, ?int $annee, ?int $mois): void
    {
        $date = ReleveEnMemoire::date($gedcom);

        self::assertSame($annee, DateRetenue::annee($date));
        self::assertSame($mois, DateRetenue::mois($date));
    }

    public function testLAgeEntreDeuxDatesExactesEstRevolu(): void
    {
        self::assertSame(69, DateRetenue::ageEnAnnees(ReleveEnMemoire::date('14 MAR 1820'), ReleveEnMemoire::date('2 JAN 1890')));
        self::assertSame(70, DateRetenue::ageEnAnnees(ReleveEnMemoire::date('14 MAR 1820'), ReleveEnMemoire::date('14 MAR 1890')));
    }

    public function testUneAnneeSeuleCompteALAnnee(): void
    {
        self::assertSame(85, DateRetenue::ageEnAnnees(ReleveEnMemoire::date('1825'), ReleveEnMemoire::date('1910')));
        self::assertSame(85, DateRetenue::ageEnAnnees(ReleveEnMemoire::date('14 MAR 1825'), ReleveEnMemoire::date('1910')));
    }

    public function testDeuxMoisConnusCorrigentLAnniversaire(): void
    {
        self::assertSame(29, DateRetenue::ageEnAnnees(ReleveEnMemoire::date('MAR 1850'), ReleveEnMemoire::date('FEB 1880')));
    }

    public function testUneDateFloueNeDonneAucunAge(): void
    {
        self::assertNull(DateRetenue::ageEnAnnees(ReleveEnMemoire::date('ABT 1820'), ReleveEnMemoire::date('1890')));
        self::assertNull(DateRetenue::ageEnAnnees(ReleveEnMemoire::date('1820'), ReleveEnMemoire::date('BEF 1890')));
    }

    public function testUnAgeNegatifEstUneIncoherenceEtNeComptePas(): void
    {
        self::assertNull(DateRetenue::ageEnAnnees(ReleveEnMemoire::date('1890'), ReleveEnMemoire::date('1820')));
    }

    public function testLAgeAtteintSeCalculeJusquAUnInstant(): void
    {
        self::assertSame(36, DateRetenue::ageEnAnnees(ReleveEnMemoire::date('3 OCT 1989'), new \DateTimeImmutable('2026-10-02')));
    }

    public function testLeSiecle(): void
    {
        self::assertSame(19, DateRetenue::siecle(1801));
        self::assertSame(19, DateRetenue::siecle(1900));
        self::assertSame(20, DateRetenue::siecle(1901));
    }

    public function testLeSeuilDeDiscretionRetireLesFichesInvisiblesDUnePetiteCase(): void
    {
        $petite = new Echantillon();
        $petite->ajouter(30.0, false);
        $petite->ajouter(90.0, true);

        self::assertSame([30.0], $petite->valeurs(), 'Deux contributions dont une invisible : l’invisible est retirée.');

        $grande = new Echantillon();
        foreach ([10.0, 20.0, 30.0, 40.0] as $valeur) {
            $grande->ajouter($valeur, false);
        }
        $grande->ajouter(50.0, true);

        self::assertSame(5, $grande->n(), 'À cinq contributions, la case est publiée entière.');
        self::assertSame(30.0, $grande->moyenne());
        self::assertSame(0, Echantillon::comptePublie(1, 1), 'Une commune qui ne compte qu’une fiche invisible ne sort pas.');
        self::assertSame(6, Echantillon::comptePublie(6, 1));
    }
}
