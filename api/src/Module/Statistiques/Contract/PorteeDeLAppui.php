<?php

declare(strict_types=1);

namespace App\Module\Statistiques\Contract;

/** Ce qu'une citation appuie : la fiche en général, ou un événement précis. */
enum PorteeDeLAppui: string
{
    case Generale = 'generale';
    case Naissance = 'naissance';
    case Deces = 'deces';
    case Mariage = 'mariage';
    case Autre = 'autre';
}
