<?php

declare(strict_types=1);

namespace App\Module\Tirage\Enum;

/**
 * Comment un participant prend part à l'édition d'une année.
 *
 * `ADistance` participe au tirage comme les autres : il offre et il reçoit. La seule
 * différence est pratique — son cadeau doit être **transmis** pour être au lieu dit le
 * jour J. `Absente` ne participe pas cette année : elle n'entre pas dans le tirage.
 */
enum Presence: string
{
    case SurPlace = 'sur_place';
    case ADistance = 'a_distance';
    case Absente = 'absente';

    public function libelle(): string
    {
        return match ($this) {
            self::SurPlace => 'Sur place',
            self::ADistance => 'À distance, cadeau transmis',
            self::Absente => 'Ne participe pas cette année',
        };
    }

    public function participe(): bool
    {
        return self::Absente !== $this;
    }
}
