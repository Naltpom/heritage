<?php

declare(strict_types=1);

namespace App\Module\Core\Enum;

/**
 * Les quarts d'ascendance, relatifs à la personne racine (§ 2.8 du modèle de données).
 *
 * Dans `Core` parce que `Timeline` l'instancie aussi (`tml_preference.branches`).
 *
 * `Branch` et `generation` sont les **deux coordonnées** de l'ordre du placement
 * partagé (ADR 0005) : c'est pourquoi la branche figure dans la liste blanche servie
 * même pour une personne vivante hors cercle (ADR 0003, amendé — arbitrage M). Un nœud
 * livré sans sa branche n'est ni posable ni colorable par un canevas qui reçoit déjà sa
 * position.
 */
enum Branch: string
{
    /** Branche paternelle du père. */
    case Pp = 'pp';
    /** Branche maternelle du père. */
    case Pm = 'pm';
    /** Branche paternelle de la mère. */
    case Mp = 'mp';
    /** Branche maternelle de la mère. */
    case Mm = 'mm';
    /** Le tronc : la lignée directe et la fratrie de la racine. */
    case Tr = 'tr';

    public function label(): string
    {
        return match ($this) {
            self::Pp => 'Branche paternelle du père',
            self::Pm => 'Branche maternelle du père',
            self::Mp => 'Branche paternelle de la mère',
            self::Mm => 'Branche maternelle de la mère',
            self::Tr => 'Tronc',
        };
    }
}
