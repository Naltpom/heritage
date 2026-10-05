<?php

declare(strict_types=1);

namespace App\Module\Tree\Service;

/**
 * La composante reliée d'une fiche : toutes celles qu'un chemin de liens de famille
 * (parent, enfant, conjoint, dans les deux sens) relie à elle. Pur, sans base :
 * {@see \App\Tests\Tree\ComposanteRelieeTest}.
 */
final class ComposanteReliee
{
    /**
     * @param list<int>                                                         $personnes toutes les fiches de l'arbre
     * @param list<array{mari: int|null, epouse: int|null, enfants: list<int>}> $familles
     *
     * @return array<int, true>|null `null` si l'ancre n'est pas une fiche connue : il n'y a
     *                               pas d'arbre à désigner, et l'appelant ne retire rien
     */
    public static function de(int $ancre, array $personnes, array $familles): ?array
    {
        $connues = array_fill_keys($personnes, true);

        if (!isset($connues[$ancre])) {
            return null;
        }

        $voisins = [];
        foreach ($familles as $famille) {
            $membres = array_values(array_filter(
                [$famille['mari'], $famille['epouse'], ...$famille['enfants']],
                static fn (?int $id): bool => null !== $id,
            ));
            // Chaque membre est relié au premier : une étoile suffit à la connexité.
            foreach (\array_slice($membres, 1) as $id) {
                $voisins[$membres[0]][] = $id;
                $voisins[$id][] = $membres[0];
            }
        }

        $dedans = [$ancre => true];
        $pile = [$ancre];
        while ([] !== $pile) {
            foreach ($voisins[array_pop($pile)] ?? [] as $voisin) {
                if (!isset($dedans[$voisin])) {
                    $dedans[$voisin] = true;
                    $pile[] = $voisin;
                }
            }
        }

        return $dedans;
    }
}
