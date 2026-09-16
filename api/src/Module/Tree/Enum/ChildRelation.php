<?php

declare(strict_types=1);

namespace App\Module\Tree\Enum;

/**
 * La nature d'une filiation (§ 3.9 du modèle de données).
 *
 * Le GEDCOM d'origine ne porte aucun `PEDI` : tout arrive en `Naissance`. Les autres
 * valeurs existent pour les ajouts futurs, qui sont des cas réels en généalogie.
 *
 * `Presume` est la plus utile des trois : le `README.md` du chantier décrit plusieurs
 * rattachements « qui reposent sur un faisceau et non sur un acte ». Marquer le lien
 * vaut mieux qu'écrire une note qu'on ne relira pas.
 */
enum ChildRelation: string
{
    case Naissance = 'naissance';
    case Adoption = 'adoption';
    case Reconnaissance = 'reconnaissance';
    case Presume = 'presume';

    public function label(): string
    {
        return match ($this) {
            self::Naissance => 'Naissance',
            self::Adoption => 'Adoption',
            self::Reconnaissance => 'Reconnaissance',
            self::Presume => 'Filiation présumée',
        };
    }
}
