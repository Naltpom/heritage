<?php

declare(strict_types=1);

namespace App\Module\Core\Enum;

/**
 * Le côté d'une fin de recherche : celui du père ou celui de la mère (ADR 0023, § 2.8 du
 * modèle de données).
 *
 * « Les deux » n'est pas une valeur : c'est un geste de saisie, qui dépose deux ops dans la
 * même demande. Dans `Core` parce que `Tree` le mappe et que `Proposal` le valide.
 */
enum CoteDeRecherche: string
{
    case Pere = 'pere';
    case Mere = 'mere';

    /** Le champ de la surface éditable d'une personne qui porte ce côté. */
    public function champ(): string
    {
        return match ($this) {
            self::Pere => 'finDeRecherchePere',
            self::Mere => 'finDeRechercheMere',
        };
    }

    public static function depuisChamp(string $champ): ?self
    {
        foreach (self::cases() as $cote) {
            if ($cote->champ() === $champ) {
                return $cote;
            }
        }

        return null;
    }
}
