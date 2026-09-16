<?php

declare(strict_types=1);

namespace App\Module\Gedcom\Enum;

/**
 * L'état d'une exécution GEDCOM (§ 8.4 du modèle de données).
 */
enum RunStatus: string
{
    case EnCours = 'en_cours';
    case Reussi = 'reussi';
    case Echoue = 'echoue';

    public function label(): string
    {
        return match ($this) {
            self::EnCours => 'En cours',
            self::Reussi => 'Réussi',
            self::Echoue => 'Échoué',
        };
    }
}
