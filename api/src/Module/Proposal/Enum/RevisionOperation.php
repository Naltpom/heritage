<?php

declare(strict_types=1);

namespace App\Module\Proposal\Enum;

/**
 * Ce qu'une révision a fait à l'arbre (§ 5.7 du modèle de données).
 */
enum RevisionOperation: string
{
    case Creation = 'creation';
    case Modification = 'modification';
    case Suppression = 'suppression';
    case LienAjoute = 'lien_ajoute';
    case LienRetire = 'lien_retire';

    public function label(): string
    {
        return match ($this) {
            self::Creation => 'Création',
            self::Modification => 'Modification',
            self::Suppression => 'Suppression',
            self::LienAjoute => 'Lien ajouté',
            self::LienRetire => 'Lien retiré',
        };
    }
}
