<?php

declare(strict_types=1);

namespace App\Module\Timeline\Service;

use App\Module\Core\Enum\DateQualifier;
use App\Module\Core\ValueObject\UncertainDate;

/**
 * Le bloc `{min, max, certain}` du § 9.1 — **un intervalle d'années, jamais un point**.
 *
 * ```json
 * "debut": { "min": 1901, "max": 1909, "certain": false }
 * ```
 *
 * C'est le seul endroit du module où une `UncertainDate` devient des entiers, et c'est
 * délibéré qu'il n'y en ait qu'un. L'en-tête d'{@see UncertainDate} explique pourquoi
 * l'objet n'offre ni `getDate()`, ni `getYear()`, ni `__toString()` : *« si la méthode
 * existait, quelqu'un l'appellerait et une fourchette deviendrait une date ferme — c'est
 * exactement le bogue qui faisait naître Marne Dolomite Sureau en 1987, après ses propres
 * enfants »*. La conversion doit donc être **visible à l'endroit où elle est faite**. La
 * voici, et elle ne perd rien : deux bornes entrent, deux bornes sortent.
 *
 * ## `certain` vaut `qualifier === 'exacte'`, et rien d'autre
 *
 * Le § 9.1 le fixe, et le § 14.3 dit pourquoi c'est le **seul** test admissible :
 *
 * > `1930` donne déjà `1930-01-01` / `1930-12-31` : un client qui déduirait « `min ≠ max`
 * > donc fourchette » classerait les trois quarts de l'arbre en incertain.
 *
 * L'inverse se trompe tout autant : `BET 1901 AND 1909` et `1930` ont tous deux
 * `min ≠ max`, mais l'un est un doute et l'autre une granularité. **Ce n'est pas
 * `min`/`max` qui porte l'incertitude, c'est `qualifier`.** Comparer les bornes serait
 * plus « malin » et serait faux ; le test se lit sur le qualificatif.
 *
 * Conséquence à assumer, et qui surprend à la première lecture du § 9.1 : Marne Dolomite
 * Sureau est morte en **1987** — une année pleine, `qualifier = 'annee'` — et son bloc
 * `fin` porte donc `certain: false` alors que `min` et `max` valent tous deux 1987. Ce
 * n'est pas une incohérence de l'exemple : on sait l'année, on ne sait pas le jour, et le
 * § 7.4 du dossier demande que cela se **voie** à l'écran.
 *
 * ## Les trois clés sont toujours émises, `null` compris
 *
 * `BEF 1920` est stocké sans borne basse. Le bloc rend alors `min: null`, et non un bloc
 * amputé de sa clé : c'est la convention des blocs date du projet — « pour qu'un champ
 * absent soit toujours une erreur et jamais un non-renseigné implicite » (§ 5.2 du modèle,
 * repris par `Tree\Serializer\NormaliseurDeDate`). Une borne nulle est une borne
 * **ouverte**, pas une borne manquante.
 *
 * Quand les deux bornes sont nulles — `qualifier = 'inconnue'` — il n'y a pas d'intervalle
 * du tout : {@see self::pour()} rend `null`, et c'est cette absence que le § 9.1 emploie
 * déjà pour la `fin` d'une personne vivante.
 */
final class IntervalleDAnnees
{
    /**
     * @return array{min: int|null, max: int|null, certain: bool}|null `null` quand la date
     *                                                                 ne porte aucune borne
     */
    public function pour(UncertainDate $date): ?array
    {
        $min = self::annee($date->getMin());
        $max = self::annee($date->getMax());

        if (null === $min && null === $max) {
            return null;
        }

        return [
            'min' => $min,
            'max' => $max,
            'certain' => DateQualifier::Exacte === $date->getQualifier(),
        ];
    }

    /**
     * Les années qu'un intervalle pose sur l'axe — zéro, une ou deux.
     *
     * Sert au calcul des {@see Bornes} : une fourchette étend l'axe des **deux** côtés,
     * donc ses deux bornes comptent. Ne retenir que `min` raccourcirait l'axe de huit ans
     * sur le cas Sureau.
     *
     * @param array{min: int|null, max: int|null, certain: bool}|null $intervalle
     *
     * @return list<int>
     */
    public static function anneesDe(?array $intervalle): array
    {
        if (null === $intervalle) {
            return [];
        }

        return array_values(array_filter(
            [$intervalle['min'], $intervalle['max']],
            static fn (?int $annee): bool => null !== $annee,
        ));
    }

    /**
     * L'année d'une borne.
     *
     * `format('Y')` et non `->format('Y') + 0` par mégarde sur une date négative : les
     * dates de cet arbre commencent au XVIIᵉ siècle, il n'y a pas d'année avant J.-C. à
     * traiter, et un `(int)` suffit.
     */
    private static function annee(?\DateTimeImmutable $borne): ?int
    {
        return null === $borne ? null : (int) $borne->format('Y');
    }
}
