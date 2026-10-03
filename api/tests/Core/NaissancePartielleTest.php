<?php

declare(strict_types=1);

namespace App\Tests\Core;

use App\Module\Core\Enum\DateQualifier;
use App\Module\Core\ValueObject\ComposantesDeDate;
use App\Module\Core\ValueObject\UncertainDate;
use App\Module\Tree\Service\EtiquetteDeDateGedcom;
use PHPUnit\Framework\TestCase;

/**
 * **La date dégradée** de la réciprocité des vivants (ADR 0016) : ce qu'il reste d'une
 * naissance quand on n'en voit que certaines composantes — en prose, en forme compacte,
 * en composantes chiffrées. Et la règle qui tient tout : **`raw` ne sort jamais** d'une
 * date réduite, puisqu'il porte toutes les composantes d'un coup.
 */
final class NaissancePartielleTest extends TestCase
{
    public function testUneDateExacteSeDegradeComposanteParComposante(): void
    {
        $date = $this->exacte('1985-03-12');

        self::assertSame('12 mars 1985', $date->formatPartiel(ComposantesDeDate::tout()));
        self::assertSame('mars 1985', $date->formatPartiel(new ComposantesDeDate(annee: true, mois: true)));
        self::assertSame('1985', $date->formatPartiel(new ComposantesDeDate(annee: true)));
        self::assertSame('12 mars', $date->formatPartiel(new ComposantesDeDate(mois: true, jour: true)));
        self::assertSame('mars', $date->formatPartiel(new ComposantesDeDate(mois: true)));
        self::assertSame('le 12', $date->formatPartiel(new ComposantesDeDate(jour: true)));
        self::assertSame('1985', $date->formatPartiel(new ComposantesDeDate(annee: true, jour: true)), 'Un jour sans son mois ne dit rien : l’année seule.');
        self::assertSame('', $date->formatPartiel(ComposantesDeDate::aucune()));
    }

    public function testLaDateReduiteNePortePlusRaw(): void
    {
        $date = $this->exacte('1985-03-12');

        $annee = $date->reduite(new ComposantesDeDate(annee: true));
        self::assertNotNull($annee);
        self::assertNull($annee->getRaw());
        self::assertSame(DateQualifier::Annee, $annee->getQualifier());
        self::assertSame('1985-01-01', $annee->getMin()?->format('Y-m-d'));
        self::assertSame('1985-12-31', $annee->getMax()?->format('Y-m-d'));
        self::assertSame('Saule', $annee->getPlace(), 'Le lieu n’est pas concerné.');

        $mois = $date->reduite(new ComposantesDeDate(annee: true, mois: true));
        self::assertNotNull($mois);
        self::assertSame(DateQualifier::Mois, $mois->getQualifier());
        self::assertSame('1985-03-31', $mois->getMax()?->format('Y-m-d'));

        self::assertNull($date->reduite(new ComposantesDeDate(mois: true, jour: true)), 'Sans l’année, pas de date de l’arbre.');
        self::assertSame($date, $date->reduite(ComposantesDeDate::tout()));
    }

    public function testUneFourchetteNeLivreQueSesAnnees(): void
    {
        $vers = new UncertainDate('ABT 12 MAR 1934', DateQualifier::Environ, new \DateTimeImmutable('1934-03-12'), new \DateTimeImmutable('1934-03-12'));

        self::assertSame('vers 1934', $vers->formatPartiel(new ComposantesDeDate(annee: true, mois: true)));
        self::assertSame('', $vers->formatPartiel(new ComposantesDeDate(mois: true, jour: true)), '« vers » n’établit aucun mois ni aucun jour.');
        self::assertSame([], $vers->composantesVisibles(new ComposantesDeDate(mois: true, jour: true)));

        // `BEF 15 MAR 1920` est stocké `max = 1920-03-14` : élargie à l'année, elle reste vraie.
        $avant = new UncertainDate('BEF 15 MAR 1920', DateQualifier::Avant, null, new \DateTimeImmutable('1920-03-14'));
        self::assertSame('avant 1921', $avant->formatPartiel(new ComposantesDeDate(annee: true)));

        $entre = new UncertainDate('BET 1901 AND 1909', DateQualifier::Entre, new \DateTimeImmutable('1901-01-01'), new \DateTimeImmutable('1909-12-31'));
        self::assertSame('entre 1901 et 1909', $entre->formatPartiel(new ComposantesDeDate(annee: true)));
    }

    public function testLesComposantesVisiblesSontSuresEtPermises(): void
    {
        $date = $this->exacte('1985-03-12');

        self::assertSame(['mois' => 3, 'jour' => 12], $date->composantesVisibles(new ComposantesDeDate(mois: true, jour: true)));
        self::assertSame(['annee' => 1985], $date->composantesVisibles(new ComposantesDeDate(annee: true)));

        $auMois = new UncertainDate('MAR 1985', DateQualifier::Mois, new \DateTimeImmutable('1985-03-01'), new \DateTimeImmutable('1985-03-31'));
        self::assertSame(['mois' => 3], $auMois->composantesVisibles(new ComposantesDeDate(mois: true, jour: true)), 'Un mois n’a pas de jour sûr.');
    }

    public function testLaFormeCompacteNeLitJamaisRaw(): void
    {
        $etiquettes = new EtiquetteDeDateGedcom();
        // Un `raw` qui contredirait les bornes : s'il était lu, on le verrait.
        $date = new UncertainDate('12 MAR 1985', DateQualifier::Exacte, new \DateTimeImmutable('1985-03-12'), new \DateTimeImmutable('1985-03-12'));

        self::assertSame('1985', $etiquettes->etiquettePartielle($date, new ComposantesDeDate(annee: true)));
        self::assertSame('3/1985', $etiquettes->etiquettePartielle($date, new ComposantesDeDate(annee: true, mois: true)));
        self::assertSame('12.3', $etiquettes->etiquettePartielle($date, new ComposantesDeDate(mois: true, jour: true)));
        self::assertSame('mars', $etiquettes->etiquettePartielle($date, new ComposantesDeDate(mois: true)));
        self::assertSame('', $etiquettes->etiquettePartielle($date, ComposantesDeDate::aucune()));
        self::assertSame('12.3.1985', $etiquettes->etiquettePartielle($date, ComposantesDeDate::tout()));
    }

    public function testLIntersectionEstLaRegleDeReciprocite(): void
    {
        $moi = new ComposantesDeDate(annee: true, mois: true);
        $autre = new ComposantesDeDate(mois: true, jour: true);

        self::assertEquals(new ComposantesDeDate(mois: true), $moi->inter($autre));
        self::assertEquals($moi->inter($autre), $autre->inter($moi));
        self::assertSame(3, $moi->masque());
        self::assertEquals($moi, ComposantesDeDate::depuisMasque(3));
    }

    private function exacte(string $jour): UncertainDate
    {
        $d = new \DateTimeImmutable($jour);

        return new UncertainDate(strtoupper($d->format('j M Y')), DateQualifier::Exacte, $d, $d, 'Saule');
    }
}
