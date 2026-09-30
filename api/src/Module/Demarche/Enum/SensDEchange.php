<?php

declare(strict_types=1);

namespace App\Module\Demarche\Enum;

/** Un message parti de la famille, ou arrivé d'un service. */
enum SensDEchange: string
{
    case Envoye = 'envoye';
    case Recu = 'recu';

    public function libelle(): string
    {
        return match ($this) {
            self::Envoye => 'Envoyé',
            self::Recu => 'Reçu',
        };
    }
}
