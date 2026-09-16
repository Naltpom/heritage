<?php

declare(strict_types=1);

namespace App\Module\Gedcom\Enum;

/**
 * La gravité d'une anomalie relevée à l'import (§ 8.4 du modèle de données).
 *
 * `Erreur` bloque l'import hors `dry_run` ; `Avertissement` le laisse passer en le
 * signalant ; `Info` documente le bruit MyHeritage qu'on a décidé d'ignorer — et c'est
 * pour pouvoir **taire une catégorie entière** une fois qu'on a établi qu'elle est sans
 * effet que `ged_issue.code` existe à côté de la sévérité.
 */
enum IssueSeverity: string
{
    case Info = 'info';
    case Avertissement = 'avertissement';
    case Erreur = 'erreur';

    public function label(): string
    {
        return match ($this) {
            self::Info => 'Information',
            self::Avertissement => 'Avertissement',
            self::Erreur => 'Erreur',
        };
    }
}
