<?php

declare(strict_types=1);

namespace App\Module\Messagerie\Enum;

/**
 * Ce qu'un membre vient dire à l'administration : une question, une idée, un défaut.
 *
 * Choisie à l'ouverture du fil, elle ne change plus : c'est ce qui permet à
 * l'administrateur de trier sa liste — un bogue qui bloque quelqu'un passe avant une idée
 * pour plus tard — sans relire chaque message.
 */
enum NatureDuFil: string
{
    case Question = 'question';
    case Suggestion = 'suggestion';
    case Bug = 'bug';

    public function libelle(): string
    {
        return match ($this) {
            self::Question => 'Question',
            self::Suggestion => 'Suggestion',
            self::Bug => 'Bug',
        };
    }
}
