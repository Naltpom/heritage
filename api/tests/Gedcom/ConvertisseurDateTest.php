<?php

declare(strict_types=1);

namespace App\Tests\Gedcom;

use App\Module\Core\Enum\DateQualifier;
use App\Module\Gedcom\Service\ConvertisseurDate;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Le convertisseur de dates, forme par forme.
 *
 * **C'est le test qui garde la règle du § 9.1** : une chaîne GEDCOM nomme un intervalle
 * calendaire, jamais un point. Le bogue qu'il empêche a un nom dans ce projet — Marne
 * Dolomite Sureau, née `BET 1901 AND 1909`, que l'aplatissement faisait naître en 1987,
 * après ses propres enfants.
 */
final class ConvertisseurDateTest extends TestCase
{
    /**
     * La table de conversion du § 0.5 du modèle de données, exhaustive sur les 620
     * valeurs `DATE` du fichier réel.
     *
     * @return iterable<string, array{0: ?string, 1: DateQualifier, 2: ?string, 3: ?string}>
     */
    public static function formesDuModele(): iterable
    {
        yield 'jour exact' => ['11 MAR 1930', DateQualifier::Exacte, '1930-03-11', '1930-03-11'];
        yield 'année seule' => ['1930', DateQualifier::Annee, '1930-01-01', '1930-12-31'];
        yield 'environ' => ['ABT 1934', DateQualifier::Environ, '1934-01-01', '1934-12-31'];
        yield 'fourchette' => ['BET 1901 AND 1909', DateQualifier::Entre, '1901-01-01', '1909-12-31'];
        yield 'mois' => ['JAN 1920', DateQualifier::Mois, '1920-01-01', '1920-01-31'];
        yield 'avant une année' => ['BEF 1920', DateQualifier::Avant, null, '1919-12-31'];
        yield 'avant un jour' => ['BEF 12 DEC 1920', DateQualifier::Avant, null, '1920-12-11'];
        yield 'depuis un jour' => ['FROM 15 JUN 1900', DateQualifier::Depuis, '1900-06-15', null];
        yield 'après une année' => ['AFT 1920', DateQualifier::Apres, '1921-01-01', null];
        yield 'jusqu’à' => ['TO 1920', DateQualifier::JusquA, null, '1920-12-31'];
        yield 'absente' => [null, DateQualifier::Inconnue, null, null];
        yield 'vide' => ['', DateQualifier::Inconnue, null, null];
    }

    #[DataProvider('formesDuModele')]
    public function testLesFormesDuModeleDonnentLesBornesAttendues(?string $brut, DateQualifier $attendu, ?string $min, ?string $max): void
    {
        $date = (new ConvertisseurDate())->convertir($brut)->date;

        self::assertSame($attendu, $date->getQualifier());
        self::assertSame($min, $date->getMin()?->format('Y-m-d'));
        self::assertSame($max, $date->getMax()?->format('Y-m-d'));
    }

    /**
     * La chaîne GEDCOM est conservée telle quelle, y compris quand on a su la lire.
     *
     * C'est la provenance : elle survit à toute réécriture de l'application, et c'est ce
     * qui reste quand aucune forme connue n'a pu être analysée.
     */
    public function testLaChaineDOrigineEstToujoursConservee(): void
    {
        $convertisseur = new ConvertisseurDate();

        self::assertSame('BET 1901 AND 1909', $convertisseur->convertir('BET 1901 AND 1909')->date->getRaw());
        self::assertSame('vers la Saint-Jean', $convertisseur->convertir('vers la Saint-Jean')->date->getRaw());
    }

    /**
     * Une forme inconnue ne fait pas perdre la donnée : elle part en `raw` avec
     * `qualifier = 'inconnue'`, et l'appelant reçoit de quoi la journaliser.
     */
    public function testUneFormeInconnueEstSignaleeSansEtreJetee(): void
    {
        $analyse = (new ConvertisseurDate())->convertir('vers la Saint-Jean');

        self::assertSame(DateQualifier::Inconnue, $analyse->date->getQualifier());
        self::assertSame('vers la Saint-Jean', $analyse->date->getRaw());
        self::assertNull($analyse->date->getMin());
        self::assertNull($analyse->date->getMax());
        self::assertNotNull($analyse->anomalie);
    }

    /**
     * **Le verrou principal** : une fourchette ne devient jamais une date ferme.
     *
     * `UncertainDate` n'expose ni `getDate()` ni `getYear()` ; on vérifie ici que ce que
     * le convertisseur produit reste un intervalle, et que le rendu français le dit.
     */
    public function testUneFourchetteResteUnIntervalle(): void
    {
        $date = (new ConvertisseurDate())->convertir('BET 1901 AND 1909')->date;

        self::assertTrue($date->isRange());
        self::assertSame('entre 1901 et 1909', $date->format());
        self::assertNotSame($date->getMin()?->format('Y-m-d'), $date->getMax()?->format('Y-m-d'));
    }

    /**
     * Une fourchette dont les deux bornes se recouvrent n'est pas une fourchette.
     *
     * `ck_<table>_<x>_intervalle` refuse en base `qualifier = 'entre'` avec des bornes
     * égales, et elle a raison : « entre 1930 et 1930 » ne veut rien dire. Le
     * convertisseur la ramène à la forme simple qu'elle décrit — se battre contre la
     * contrainte serait ici le vrai bogue.
     */
    public function testUneFourchetteDegenereeNeViolePasLaContrainteDeBase(): void
    {
        $analyse = (new ConvertisseurDate())->convertir('BET 11 MAR 1930 AND 11 MAR 1930');

        self::assertSame(DateQualifier::Exacte, $analyse->date->getQualifier());
        self::assertSame('1930-03-11', $analyse->date->getMin()?->format('Y-m-d'));
        self::assertSame('1930-03-11', $analyse->date->getMax()?->format('Y-m-d'));
        self::assertNotNull($analyse->anomalie);
        self::assertSame('BET 11 MAR 1930 AND 11 MAR 1930', $analyse->date->getRaw());
    }

    /**
     * `BET 1930 AND 1930`, en revanche, **reste** une fourchette : elle dit « quelque
     * part en 1930 », ses bornes sont distinctes, et `ck_<table>_<x>_intervalle` l'accepte.
     *
     * La nuance mérite son test : c'est celle qu'on aplatit par excès de zèle en
     * croyant bien faire, et l'aplatir ferait perdre le doute que la chaîne exprime.
     */
    public function testUneFourchetteDAnneesIdentiquesResteUneFourchette(): void
    {
        $date = (new ConvertisseurDate())->convertir('BET 1930 AND 1930')->date;

        self::assertSame(DateQualifier::Entre, $date->getQualifier());
        self::assertSame('1930-01-01', $date->getMin()?->format('Y-m-d'));
        self::assertSame('1930-12-31', $date->getMax()?->format('Y-m-d'));
    }

    /**
     * Le 31 février n'existe pas, et `createFromFormat` le transforme volontiers en
     * 3 mars. Une date d'acte mal recopiée deviendrait une date plausible et fausse.
     */
    public function testUneDateCalendairementImpossibleEstRefusee(): void
    {
        $analyse = (new ConvertisseurDate())->convertir('31 FEB 1900');

        self::assertSame(DateQualifier::Inconnue, $analyse->date->getQualifier());
        self::assertNotNull($analyse->anomalie);
    }

    public function testLeLieuAccompagneLaDateMemeQuandLaDateEstAbsente(): void
    {
        $date = (new ConvertisseurDate())->convertir(null, 'Cèdre, Fougerie, Florie')->date;

        self::assertSame('Cèdre, Fougerie, Florie', $date->getPlace());
        self::assertFalse($date->isKnown());
    }

    /** Le préfixe de calendrier de la norme n'est porté par aucun exporteur de ce projet — il l'est par d'autres. */
    public function testLePrefixeDeCalendrierEstIgnore(): void
    {
        $date = (new ConvertisseurDate())->convertir('@#DGREGORIAN@ 12 DEC 1920')->date;

        self::assertSame(DateQualifier::Exacte, $date->getQualifier());
        self::assertSame('1920-12-12', $date->getMin()?->format('Y-m-d'));
    }
}
