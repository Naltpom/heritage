<?php

declare(strict_types=1);

namespace App\Module\Membership\Service;

use App\Module\Membership\Service\Port\VueDeLArbre;

/**
 * **Les proches** d'une personne, au sens de la réciprocité des vivants (ADR 0016,
 * révision du 2026-10-07) — ceux avec qui le niveau `proches` partage une date de
 * naissance :
 *
 * ```
 * P(u) = Asc(u) ∪ Desc(u) ∪ Fratrie(u)
 *      ∪ Conj(u) ∪ Asc(Conj(u))
 *      ∪ Conj(Desc(u))
 *      \ { u }
 * ```
 *
 * - `Asc`, `Desc` : à toute profondeur — les vivants s'arrêtent d'eux-mêmes à trois ou
 *   quatre générations ;
 * - `Fratrie` : les enfants d'un de ses parents, demi-frères et demi-sœurs compris ;
 * - `Conj` : les conjoints **actuels** — une union rompue (divorce, séparation) n'en fait
 *   plus un proche, ni ses parents.
 *
 * ## La relation est symétrique, et c'est voulu
 *
 * `v ∈ P(u) ⇔ u ∈ P(v)` : ascendant ↔ descendant, frère ↔ frère, conjoint ↔ conjoint,
 * ascendant d'un conjoint ↔ conjoint d'un descendant. C'est ce qui permet à la réciprocité
 * de se lire en une phrase — « si je vois ta date, tu vois la mienne » — et ce qui interdit
 * d'ajouter un terme sans son miroir.
 *
 * Service **sans état, sans base et sans session**, comme {@see CalculDuCercle} : une vue,
 * une personne, une liste.
 */
final class CalculDesProches
{
    /** @return array<int, true> les proches de `$personne`, elle exclue */
    public function proches(VueDeLArbre $arbre, int $personne): array
    {
        if (null === $arbre->personne($personne)) {
            return [];
        }

        $ascendants = $this->parcourir([$personne], $arbre->parentsDe(...));
        $descendants = $this->parcourir([$personne], $arbre->enfantsDe(...));
        $conjoints = $arbre->conjointsActuelsDe($personne);

        $proches = $ascendants + $descendants;

        foreach ($arbre->parentsDe($personne) as $parent) {
            foreach ($arbre->enfantsDe($parent) as $frere) {
                $proches[$frere] = true;
            }
        }

        foreach ($conjoints as $conjoint) {
            $proches[$conjoint] = true;
        }
        $proches += $this->parcourir($conjoints, $arbre->parentsDe(...));

        foreach (array_keys($descendants) as $descendant) {
            foreach ($arbre->conjointsActuelsDe($descendant) as $conjoint) {
                $proches[$conjoint] = true;
            }
        }

        unset($proches[$personne]);

        return $proches;
    }

    /**
     * Toutes les personnes atteintes depuis `$depart` en suivant `$voisins`, départ exclu.
     * En largeur, avec un ensemble de visités : l'implexe fait des boucles dans l'arbre.
     *
     * @param list<int>                $depart
     * @param \Closure(int): list<int> $voisins
     *
     * @return array<int, true>
     */
    private function parcourir(array $depart, \Closure $voisins): array
    {
        $vus = array_fill_keys($depart, true);
        $trouves = [];
        $file = $depart;

        while ([] !== $file) {
            $suivant = [];
            foreach ($file as $courant) {
                foreach ($voisins($courant) as $voisin) {
                    if (!isset($vus[$voisin])) {
                        $vus[$voisin] = true;
                        $trouves[$voisin] = true;
                        $suivant[] = $voisin;
                    }
                }
            }
            $file = $suivant;
        }

        return $trouves;
    }
}
