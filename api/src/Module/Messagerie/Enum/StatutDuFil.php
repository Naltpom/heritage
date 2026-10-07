<?php

declare(strict_types=1);

namespace App\Module\Messagerie\Enum;

/**
 * Ouvert, ou réglé.
 *
 * `clos` est posé par l'administration : la question a sa réponse, le bogue est corrigé.
 * Un fil clos **reste lisible et se rouvre** dès que le membre y écrit de nouveau — la
 * suite d'une conversation se dit dans la conversation, pas dans une nouvelle qui aurait
 * perdu le début.
 */
enum StatutDuFil: string
{
    case Ouvert = 'ouvert';
    case Clos = 'clos';

    public function libelle(): string
    {
        return match ($this) {
            self::Ouvert => 'En cours',
            self::Clos => 'Réglé',
        };
    }
}
