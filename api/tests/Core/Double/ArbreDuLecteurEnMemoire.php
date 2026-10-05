<?php

declare(strict_types=1);

namespace App\Tests\Core\Double;

use App\Module\Core\Contract\ArbreDuLecteurInterface;

/**
 * Le double de {@see ArbreDuLecteurInterface} pour tout l'environnement de test : par
 * défaut il ne retire rien (comme l'implémentation réelle sur une base sans arbre) ; un
 * cas qui exerce « mon arbre » le restreint avec {@see self::restreindreA()}. Le calcul
 * réel de la composante est éprouvé à part : `tests/Tree/ComposanteRelieeTest.php`.
 */
final class ArbreDuLecteurEnMemoire implements ArbreDuLecteurInterface
{
    /** @var array<int, true>|null */
    private static ?array $arbre = null;

    /** @param list<int> $personIds */
    public static function restreindreA(array $personIds): void
    {
        self::$arbre = array_fill_keys($personIds, true);
    }

    public static function reinitialiser(): void
    {
        self::$arbre = null;
    }

    public function filtrer(array $personIds, bool $toutLaBase = false): array
    {
        $arbre = self::$arbre;

        if ($toutLaBase || null === $arbre) {
            return array_values($personIds);
        }

        return array_values(array_filter($personIds, static fn (int $id): bool => isset($arbre[$id])));
    }
}
