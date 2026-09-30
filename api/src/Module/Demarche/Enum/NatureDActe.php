<?php

declare(strict_types=1);

namespace App\Module\Demarche\Enum;

/**
 * L'acte que la démarche cherche à obtenir.
 *
 * `Autre` couvre ce qui n'est pas de l'état civil — une minute notariale, un registre
 * matricule, un jugement — : l'intitulé de la démarche dit alors de quoi il s'agit.
 */
enum NatureDActe: string
{
    case Naissance = 'naissance';
    case Mariage = 'mariage';
    case Deces = 'deces';
    case Autre = 'autre';

    public function libelle(): string
    {
        return match ($this) {
            self::Naissance => 'Acte de naissance',
            self::Mariage => 'Acte de mariage',
            self::Deces => 'Acte de décès',
            self::Autre => 'Autre pièce',
        };
    }
}
