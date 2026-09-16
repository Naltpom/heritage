<?php

declare(strict_types=1);

namespace App\Module\Gedcom\Enum;

/**
 * Le sens d'une exécution GEDCOM (§ 8.4 du modèle de données).
 *
 * Une seule table pour les deux directions : les colonnes utiles sont les mêmes
 * (fichier, empreinte, dénombrements, durée, état), et deux tables jumelles auraient
 * divergé au premier ajout de colonne.
 */
enum RunDirection: string
{
    case Import = 'import';
    case Export = 'export';

    public function label(): string
    {
        return match ($this) {
            self::Import => 'Import',
            self::Export => 'Export',
        };
    }
}
