<?php

declare(strict_types=1);

namespace App\Tests\Core;

use App\Module\Core\Enum\DateQualifier;
use App\Module\Core\Exception\DomainException;
use App\Module\Core\Service\LectureDeBlocDate;
use App\Module\Media\Http\BlocDate;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * La lecture d'un bloc date à cinq clés, **remontée de `Media` dans `Core`** pour que la
 * ressemblance de l'arbre et celle des propositions refusent les mêmes blocs que le
 * téléversement — et sur le champ que chacune nomme.
 */
final class LectureDeBlocDateTest extends TestCase
{
    private LectureDeBlocDate $lecture;

    protected function setUp(): void
    {
        $this->lecture = new LectureDeBlocDate();
    }

    public function testUnBlocDecodeTraverseSansSAplatir(): void
    {
        $date = $this->lecture->depuisValeur([
            'raw' => 'BET 1901 AND 1909',
            'qualifier' => 'entre',
            'min' => '1901-01-01',
            'max' => '1909-12-31',
            'place' => 'Saule, Ombrage, Sylvanie',
        ], 'fiche.birth');

        self::assertNotNull($date);
        self::assertSame(DateQualifier::Entre, $date->getQualifier());
        self::assertSame('1901-01-01', $date->getMin()?->format('Y-m-d'));
        self::assertSame('1909-12-31', $date->getMax()?->format('Y-m-d'));
        self::assertSame('BET 1901 AND 1909', $date->getRaw());
        self::assertSame('Saule, Ombrage, Sylvanie', $date->getPlace());
    }

    public function testUneCleAbsenteVautNullEtLeLieuSeulFaitUneDateInconnue(): void
    {
        $date = $this->lecture->depuisValeur(['qualifier' => 'inconnue', 'place' => 'Sorbière'], 'fiche.birth');

        self::assertNotNull($date);
        self::assertFalse($date->isKnown());
        self::assertSame('Sorbière', $date->getPlace());
        self::assertNull($date->getRaw());
    }

    public function testUnBlocAbsentResteAbsent(): void
    {
        self::assertNull($this->lecture->depuisValeur(null, 'fiche.birth'));
        self::assertNull($this->lecture->depuisJson(null, 'taken'));
        self::assertNull($this->lecture->depuisJson('   ', 'taken'));
    }

    /** @return iterable<string, array{mixed}> */
    public static function blocsInvalides(): iterable
    {
        yield 'texte, en lecture seule' => [['raw' => null, 'qualifier' => 'annee', 'min' => '1899-01-01', 'max' => '1899-12-31', 'place' => null, 'texte' => '1899']];
        yield 'qualificatif inconnu' => [['qualifier' => 'peut_etre']];
        yield 'qualificatif absent' => [['min' => '1899-01-01']];
        yield 'fourchette sans bornes' => [['qualifier' => 'entre']];
        yield 'fourchette plate' => [['qualifier' => 'entre', 'min' => '1899-01-01', 'max' => '1899-01-01']];
        yield 'inconnue mais bornée' => [['qualifier' => 'inconnue', 'min' => '1899-01-01']];
        yield 'bornes inversées' => [['qualifier' => 'annee', 'min' => '1900-01-01', 'max' => '1899-12-31']];
        yield 'horodatage' => [['qualifier' => 'exacte', 'min' => '1899-01-01T00:00:00Z']];
        yield 'date impossible' => [['qualifier' => 'exacte', 'min' => '1899-02-30']];
        yield 'lieu qui n’est pas une chaîne' => [['qualifier' => 'inconnue', 'place' => 12]];
        yield 'une chaîne au lieu d’un objet' => ['1899'];
        yield 'un nombre' => [1899];
    }

    /**
     * Chaque refus est un `422 donnees_invalides`, posé sur **le champ que l'appelant
     * nomme** — `fiche.birth` ici, `taken` pour le téléversement.
     */
    #[DataProvider('blocsInvalides')]
    public function testLesBlocsIncoherentsSontRefusesSurLeChampNomme(mixed $bloc): void
    {
        try {
            $this->lecture->depuisValeur($bloc, 'fiche.birth');
            self::fail('Ce bloc date aurait dû être refusé.');
        } catch (DomainException $erreur) {
            self::assertSame(422, $erreur->statutHttp());
            self::assertSame('donnees_invalides', $erreur->codeErreur());
            self::assertSame(['fiche.birth'], array_keys((array) $erreur->champs()));
        }
    }

    /**
     * `Media\Http\BlocDate` délègue ici **à l'identique** : même date rendue, même refus,
     * toujours sur `taken`.
     */
    public function testLeTeleversementLitLesBlocsCommeAvant(): void
    {
        $json = '{"raw":"ABT 1934","qualifier":"environ","min":"1934-01-01","max":"1934-12-31","place":"Sorbière"}';

        self::assertEquals($this->lecture->depuisJson($json, 'taken'), BlocDate::depuisJson($json));

        try {
            BlocDate::depuisJson('{"qualifier":');
            self::fail('Un JSON cassé doit être refusé.');
        } catch (DomainException $erreur) {
            self::assertSame(['taken'], array_keys((array) $erreur->champs()));
        }
    }
}
