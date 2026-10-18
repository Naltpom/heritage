<?php

declare(strict_types=1);

namespace App\Module\Noel\Enum;

/** Un tirage de Noël est ouvert jusqu'au tirage, puis tiré — sans retour possible. */
enum StatutDuTirage: string
{
    case Ouvert = 'ouvert';
    case Tire = 'tire';
}
