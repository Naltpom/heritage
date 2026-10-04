<?php

declare(strict_types=1);

namespace App\Tests\Tree;

use App\Module\Tree\Serializer\CercleDuLecteur;
use App\Module\Tree\Serializer\GrapheDuPerimetre;
use PHPUnit\Framework\TestCase;

/**
 * Révision du 2026-10-04 de l'ADR 0003 : l'arbre d'une autre famille ne sort pas du
 * graphe — ni ses personnes, défuntes comprises, ni ses familles, ni ses arêtes.
 */
final class GrapheDuPerimetreTest extends TestCase
{
    public function testLArbreDUnAutreCompteDisparaitEntierementDuGraphe(): void
    {
        // Le lecteur : 1 + 2 → 3 (famille F10). L'ami : 7 + 8 → 9 (famille F20).
        $noeuds = [
            $this->personne(1), $this->personne(2), $this->personne(3), $this->famille(10),
            $this->personne(7), $this->personne(8), $this->personne(9), $this->famille(20),
            // Une personne proposée par le lecteur, pas encore en base.
            ['id' => '#5/p1', 'type' => 'personne', 'personId' => null],
        ];
        $aretes = [
            $this->arete('P1', 'F10'), $this->arete('P2', 'F10'), $this->arete('F10', 'P3'),
            $this->arete('P7', 'F20'), $this->arete('P8', 'F20'), $this->arete('F20', 'P9'),
            $this->arete('#5/p1', 'F10'),
        ];

        $graphe = GrapheDuPerimetre::retirer($noeuds, $aretes, CercleDuLecteur::de([])->avecHorsPerimetre([7, 8, 9]));

        self::assertSame(['P1', 'P2', 'P3', 'F10', '#5/p1'], array_column($graphe['noeuds'], 'id'));
        self::assertCount(4, $graphe['aretes']);
        foreach ($graphe['aretes'] as $arete) {
            self::assertNotContains($arete['de'], ['P7', 'P8', 'P9', 'F20']);
            self::assertNotContains($arete['vers'], ['P7', 'P8', 'P9', 'F20']);
        }
    }

    public function testRienARetirerRendLeGrapheTelQuel(): void
    {
        $noeuds = [$this->personne(1), $this->famille(10)];
        $aretes = [$this->arete('P1', 'F10')];

        self::assertSame(['noeuds' => $noeuds, 'aretes' => $aretes], GrapheDuPerimetre::retirer($noeuds, $aretes, CercleDuLecteur::de([])));
    }

    /** @return array<string, mixed> */
    private function personne(int $id): array
    {
        return ['id' => 'P'.$id, 'type' => 'personne', 'personId' => $id];
    }

    /** @return array<string, mixed> */
    private function famille(int $id): array
    {
        return ['id' => 'F'.$id, 'type' => 'famille', 'familyId' => $id];
    }

    /** @return array{de: string, vers: string, genre: string} */
    private function arete(string $de, string $vers): array
    {
        return ['de' => $de, 'vers' => $vers, 'genre' => str_starts_with($de, 'F') ? 'filiation' : 'union'];
    }
}
