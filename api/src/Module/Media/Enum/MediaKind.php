<?php

declare(strict_types=1);

namespace App\Module\Media\Enum;

/**
 * Les deux natures de média du § 6.5 du dossier de mission (§ 6.3 du modèle).
 *
 * Un seul portrait **actif** par personne, garanti par un index unique partiel et non
 * par la discipline d'un service ; les précédents sont conservés en `remplace`.
 */
enum MediaKind: string
{
    case Portrait = 'portrait';
    case Preuve = 'preuve';

    public function label(): string
    {
        return match ($this) {
            self::Portrait => 'Portrait',
            self::Preuve => 'Preuve',
        };
    }
}
