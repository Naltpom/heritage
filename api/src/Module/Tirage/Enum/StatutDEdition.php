<?php

declare(strict_types=1);

namespace App\Module\Tirage\Enum;

/**
 * Où en est l'édition d'une année : les inscriptions sont ouvertes, ou le tirage est fait.
 *
 * Deux états seulement. « Terminée » n'en est pas un : une édition dont la date est passée
 * se lit à sa date, et un état de plus serait une seconde source de vérité à tenir à jour
 * par une tâche que ce module n'a pas.
 */
enum StatutDEdition: string
{
    case Ouverte = 'ouverte';
    case Tiree = 'tiree';

    public function libelle(): string
    {
        return match ($this) {
            self::Ouverte => 'Tirage à venir',
            self::Tiree => 'Tirage effectué',
        };
    }
}
