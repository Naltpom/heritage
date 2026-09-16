<?php

declare(strict_types=1);

namespace App\Module\Timeline\Enum;

/**
 * La bascule lignes ↔ colonnes de la vue chronologique (§ 7.3 du modèle de données).
 */
enum TimelineOrientation: string
{
    case Lignes = 'lignes';
    case Colonnes = 'colonnes';

    public function label(): string
    {
        return match ($this) {
            self::Lignes => 'En lignes',
            self::Colonnes => 'En colonnes',
        };
    }
}
