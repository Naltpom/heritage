<?php

declare(strict_types=1);

namespace App\Tests\Gedcom;

use App\Module\Gedcom\Service\TotauxAttendus;
use PHPUnit\Framework\TestCase;

/**
 * Le mécanisme de contrôle lui-même.
 *
 * **Il doit être vérifiable sans les données réelles**, qui ne sont pas versionnées. Ce
 * test porte donc sur le mécanisme — l'indexation par empreinte, l'analyse de l'option
 * `--attendus` — et non sur les 274 personnes, que seule la machine qui a l'atelier peut
 * voir.
 */
final class TotauxAttendusTest extends TestCase
{
    /**
     * Les huit totaux que le dossier de mission exige sont **tous** dans le jeu de
     * référence, et aux valeurs recomptées sur `arbre-romarin-v5.ged`.
     *
     * Si quelqu'un « corrige » l'un de ces nombres pour faire passer un import, ce test
     * tombe. C'est sa raison d'être : un contrôle qu'on aligne sur le résultat ne
     * contrôle plus rien.
     */
    public function testLeJeuDeReferenceEstCeluiRecompteSurLeFichier(): void
    {
        $attendus = (new TotauxAttendus())->pour(TotauxAttendus::SHA256_REFERENCE);

        self::assertNotNull($attendus);
        self::assertSame(274, $attendus['personnes']);
        self::assertSame(103, $attendus['familles']);
        self::assertSame(483, $attendus['citations']);
        self::assertSame(414, $attendus['citations_individus']);
        self::assertSame(69, $attendus['citations_familles']);
        self::assertSame(175, $attendus['notes']);
        self::assertSame(28, $attendus['sources']);
        self::assertSame(78, $attendus['sans_deces']);
        self::assertSame(84, $attendus['fiches_actees']);
        self::assertSame(12, $attendus['generations']);
        self::assertSame(['pm' => 134, 'mm' => 64, 'mp' => 51, 'pp' => 20, 'tr' => 5], [
            'pm' => $attendus['branche_pm'],
            'mm' => $attendus['branche_mm'],
            'mp' => $attendus['branche_mp'],
            'pp' => $attendus['branche_pp'],
            'tr' => $attendus['branche_tr'],
        ]);
    }

    /** La somme des branches vaut le nombre de personnes : aucune n'est laissée de côté. */
    public function testLesBranchesCouvrentToutesLesPersonnes(): void
    {
        $attendus = (new TotauxAttendus())->pour(TotauxAttendus::SHA256_REFERENCE);

        self::assertNotNull($attendus);
        self::assertSame(
            $attendus['personnes'],
            $attendus['branche_pp'] + $attendus['branche_pm'] + $attendus['branche_mp'] + $attendus['branche_mm'] + $attendus['branche_tr'],
        );
    }

    /** Les citations d'individus et de familles se somment aux 483 citations. */
    public function testLaVentilationDesCitationsEstCoherente(): void
    {
        $attendus = (new TotauxAttendus())->pour(TotauxAttendus::SHA256_REFERENCE);

        self::assertNotNull($attendus);
        self::assertSame($attendus['citations'], $attendus['citations_individus'] + $attendus['citations_familles']);
    }

    /**
     * Un autre fichier n'a pas de jeu de référence, et on le dit.
     *
     * L'atelier contient onze autres `.ged` — des versions antérieures. Le contrôle ne
     * doit jamais leur appliquer les nombres de la v5 : il retournerait « conforme » sur
     * un fichier qu'il n'a pas recompté.
     */
    public function testUnAutreFichierNaPasDeJeuDeReference(): void
    {
        self::assertNull((new TotauxAttendus())->pour(str_repeat('0', 64)));
    }

    public function testLEmpreinteEstInsensibleALaCasse(): void
    {
        self::assertNotNull((new TotauxAttendus())->pour(strtoupper(TotauxAttendus::SHA256_REFERENCE)));
    }

    public function testLOptionAttendusEstAnalysee(): void
    {
        self::assertSame(
            ['personnes' => 11, 'familles' => 5, 'generations' => 4],
            (new TotauxAttendus())->depuisOption('personnes=11, familles=5,generations=4'),
        );
    }

    public function testUneCleInconnueEstRefusee(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('boulangeries');

        (new TotauxAttendus())->depuisOption('boulangeries=3');
    }

    public function testUneOptionMalFormeeEstRefusee(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new TotauxAttendus())->depuisOption('personnes: onze');
    }

    /** Chaque clé du jeu de référence a un libellé : sinon elle n'apparaîtrait pas au rapport. */
    public function testChaqueTotalDeReferenceEstAffiche(): void
    {
        $attendus = (new TotauxAttendus())->pour(TotauxAttendus::SHA256_REFERENCE);

        self::assertNotNull($attendus);

        foreach (array_keys($attendus) as $cle) {
            self::assertArrayHasKey($cle, TotauxAttendus::LIBELLES, \sprintf('Le total « %s » n’a pas de libellé.', $cle));
        }
    }
}
