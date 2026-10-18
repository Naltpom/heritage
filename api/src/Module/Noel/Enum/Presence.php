<?php

declare(strict_types=1);

namespace App\Module\Noel\Enum;

/**
 * Sera-t-on là le jour de la remise des cadeaux ? Une information pour les autres, pas
 * une condition du tirage : un absent confie son cadeau à quelqu'un de présent.
 */
enum Presence: string
{
    case Present = 'present';
    case Absent = 'absent';
    case NeSaitPas = 'ne_sait_pas';
}
