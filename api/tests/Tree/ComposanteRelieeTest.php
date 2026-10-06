<?php

declare(strict_types=1);

namespace App\Tests\Tree;

use App\Module\Tree\Service\ComposanteReliee;
use PHPUnit\Framework\TestCase;

/**
 * « Mon arbre » (décision du 05/10/2026) : la composante reliée de la fiche du lecteur.
 */
final class ComposanteRelieeTest extends TestCase
{
    public function testLesLiensDeFamilleSeSuiventDansLesDeuxSens(): void
    {
        $familles = [
            ['mari' => 1, 'epouse' => 2, 'enfants' => [3, 4]],
            ['mari' => 3, 'epouse' => 5, 'enfants' => []],      // l'épouse d'un fils
            ['mari' => 6, 'epouse' => null, 'enfants' => [5]],  // le père de cette épouse
            ['mari' => 8, 'epouse' => 9, 'enfants' => []],      // un îlot sans lien
        ];

        $composante = ComposanteReliee::de(4, [1, 2, 3, 4, 5, 6, 7, 8, 9], $familles);

        self::assertNotNull($composante);
        $ids = array_keys($composante);
        sort($ids);
        self::assertSame([1, 2, 3, 4, 5, 6], $ids, 'La fiche isolée (7) et l’îlot (8, 9) n’en sont pas.');
    }

    public function testUneAncreInconnueNeDesignePasDArbre(): void
    {
        self::assertNull(ComposanteReliee::de(42, [1, 2], []));
    }

    public function testUneFicheSansFamilleEstSonPropreArbre(): void
    {
        self::assertSame([7 => true], ComposanteReliee::de(7, [1, 7], [['mari' => 1, 'epouse' => null, 'enfants' => []]]));
    }
}
