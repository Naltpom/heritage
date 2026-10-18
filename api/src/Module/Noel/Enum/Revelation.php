<?php

declare(strict_types=1);

namespace App\Module\Noel\Enum;

/**
 * Le vote : garder la magie de Noël, ou révéler qui a offert à qui après l'ouverture des
 * cadeaux. Tout le monde ou personne — le résultat vaut pour tout le tirage.
 */
enum Revelation: string
{
    case Magie = 'magie';
    case ApresOuverture = 'apres_ouverture';
}
