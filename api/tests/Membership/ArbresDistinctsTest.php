<?php

declare(strict_types=1);

namespace App\Tests\Membership;

use App\Module\Membership\Service\ArbresDistincts;
use App\Module\Membership\Service\Port\FamilleDeLArbre;
use App\Module\Membership\Service\Port\PersonneDeLArbre;
use App\Module\Membership\Service\Port\VueDeLArbre;
use PHPUnit\Framework\TestCase;

/**
 * Les arbres de la base (révision du 2026-10-04 de l'ADR 0003) : une composante reliée par
 * les liens de famille, rattachée dès qu'un compte en tient une fiche.
 */
final class ArbresDistinctsTest extends TestCase
{
    public function testUnMariageReunitDeuxFamillesEnUnSeulArbre(): void
    {
        // 1 + 2 → 3 ; 3 épouse 4 ; 4 a pour parents 5 et 6. Puis 7 + 8 → 9, à part. 10 seul.
        $arbres = ArbresDistincts::de($this->vue(
            [1, 2, 3, 4, 5, 6, 7, 8, 9, 10],
            [[[1, 2], [3]], [[3, 4], []], [[5, 6], [4]], [[7, 8], [9]]],
        ), []);

        foreach ([2, 3, 4, 5, 6] as $id) {
            self::assertSame(1, $arbres->arbreDe($id), (string) $id);
        }
        self::assertSame(7, $arbres->arbreDe(9));
        self::assertSame(10, $arbres->arbreDe(10));
        self::assertNull($arbres->arbreDe(99));
    }

    public function testLePerimetreEstSonArbrePlusLesArbresSansLien(): void
    {
        $arbres = ArbresDistincts::de($this->vue(
            [1, 2, 3, 7, 8, 9, 10],
            [[[1, 2], [3]], [[7, 8], [9]]],
        ), [3, 9]);

        // Le lecteur ancré sur 1 : son arbre, et la fiche seule 10, sans lien.
        self::assertTrue($arbres->dansLePerimetre(2, 1));
        self::assertTrue($arbres->dansLePerimetre(10, 1));
        self::assertFalse($arbres->dansLePerimetre(7, 1), 'L’arbre d’un autre compte.');
        // Sans ancre (régime `Aucun`) : les seuls arbres sans lien.
        self::assertFalse($arbres->dansLePerimetre(2, null));
        self::assertTrue($arbres->dansLePerimetre(10, null));
        // Une fiche inconnue n'est d'aucun arbre : rien ne l'exclut.
        self::assertTrue($arbres->dansLePerimetre(99, 1));
        self::assertTrue($arbres->estSansLien(10));
        self::assertFalse($arbres->estSansLien(8));
    }

    public function testUnRattachementInconnuNeFermeRien(): void
    {
        $arbres = ArbresDistincts::de($this->vue([1, 2], []), [404]);

        self::assertTrue($arbres->estSansLien(1));
        self::assertTrue($arbres->dansLePerimetre(2, 1));
    }

    /**
     * @param list<int>                         $ids
     * @param list<array{list<int>, list<int>}> $familles époux, enfants
     */
    private function vue(array $ids, array $familles): VueDeLArbre
    {
        return new VueDeLArbre(
            array_map(fn (int $id): PersonneDeLArbre => $this->personne($id), $ids),
            array_map(static fn (array $f): FamilleDeLArbre => new FamilleDeLArbre($f[0], $f[1]), $familles),
        );
    }

    private function personne(int $id): PersonneDeLArbre
    {
        return new PersonneDeLArbre(id: $id, prenoms: 'P'.$id, nom: 'N', libelle: 'P'.$id.' N', anneeNaissance: 1900, etiquetteNaissance: '1900', vivante: false, confidentielle: false);
    }
}
