<?php

declare(strict_types=1);

namespace App\Module\Tree\Enum;

/**
 * La nature d'un événement daté **autre que** naissance, décès, mariage et divorce
 * (§ 3.9 du modèle de données).
 *
 * Ces quatre-là sont des colonnes de `tree_person` et `tree_family`, pas des
 * `tree_fact` : ils sont lus à chaque sérialisation, à chaque passage du moteur de
 * placement et à chaque barre de la vue chronologique. Corollaire à respecter par
 * toute écriture : la naissance et le décès vont dans les colonnes et **n'en font pas
 * aussi** des faits. Un fait dupliqué est une divergence qui attend son heure.
 */
enum FactType: string
{
    case Profession = 'profession';
    case Residence = 'residence';
    case Inhumation = 'inhumation';
    case Recensement = 'recensement';
    case BaptemeReligieux = 'bapteme_religieux';
    case Bapteme = 'bapteme';
    case Autre = 'autre';

    public function label(): string
    {
        return match ($this) {
            self::Profession => 'Profession',
            self::Residence => 'Résidence',
            self::Inhumation => 'Inhumation',
            self::Recensement => 'Recensement',
            self::BaptemeReligieux => 'Baptême religieux',
            self::Bapteme => 'Baptême',
            self::Autre => 'Événement',
        };
    }
}
