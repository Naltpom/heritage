<?php

declare(strict_types=1);

namespace App\Module\Membership\Service;

use App\Module\Membership\Service\Port\VueDeLArbre;

/**
 * La formule de l'ADR 0003, et **la seule implémentation du produit**.
 *
 * ```
 * A(u) = { u } ∪ { a : a ancêtre de u, distance(a, u) ≤ N }
 * D(u) = { d : d descendant, à toute profondeur, d'un membre de A(u) }
 * C(u) = A(u) ∪ D(u) ∪ { c : c conjoint d'un membre de A(u) ∪ D(u) }
 * ```
 *
 * Service **sans état, sans base et sans session** : on lui donne une vue de l'arbre, une
 * personne et un `N`, il rend une liste d'identifiants. C'est ce qui rend écrivables les
 * tests de la règle la plus sensible du produit — et c'est la raison pour laquelle la
 * lecture des réglages, la résolution du lecteur et le cache vivent ailleurs.
 *
 * ## Les deux pièges de cette formule
 *
 * **1. Les conjoints sont ajoutés en UNE SEULE PASSE, en dernier, et ne propagent rien.**
 * Le conjoint d'un conjoint n'entre pas dans le cercle ; les ancêtres d'un conjoint non
 * plus ; les descendants d'un conjoint non plus. **Ce n'est pas un point fixe** — « un
 * agent qui itère jusqu'à stabilité implémente autre chose que ce qui est demandé, et
 * c'est ce qui sépare un cercle de trente-cinq personnes d'un cercle qui avale la moitié
 * de l'arbre au premier remariage » (ADR 0003, § 9.3 du modèle).
 *
 * Concrètement, dans le code ci-dessous : la boucle des conjoints itère sur une copie
 * **figée** de `A ∪ D`, jamais sur l'ensemble en cours de construction. C'est la seule
 * chose à ne pas casser dans ce fichier.
 *
 * **2. L'asymétrie est le sens même de la règle.** On remonte de `N` générations, puis on
 * redescend **sans limite de profondeur**. Un « cercle symétrique de parenté ≤ k », plus
 * simple à écrire, est explicitement écarté par l'ADR : « mes cousins, même éloignés, sont
 * ma famille ; les ancêtres de mon beau-frère ne le sont pas. »
 *
 * ## Le garde-fou contre l'implexe
 *
 * L'arbre contient des boucles de consanguinité — `graphe.py` en portait déjà la marque,
 * un ensemble `vus` gardant sa récursion d'ascendance. Les deux parcours ci-dessous sont
 * en largeur avec un ensemble de visités : une boucle y est une arête déjà vue, pas une
 * pile qui déborde.
 *
 * ## Ce que ce service ne fait pas
 *
 * Il ne connaît **ni le régime `Aucun`, ni l'état vivant, ni l'administrateur**. Un compte
 * en attente de validation n'a pas de cercle du tout : on ne l'appelle pas avec `N = 0`,
 * on ne l'appelle pas. La distinction est tranchée en amont, dans
 * {@see CercleDeVisibilite}, et elle est portée par un type — `Core\Enum\Regime` — qui ne
 * sait pas exprimer de profondeur.
 */
final class CalculDuCercle
{
    /**
     * @param int $generations le `N` du réglage `cercle.generations` (§ 2.2 du modèle),
     *                         borné à `[1, 6]` par le service de réglages ; une valeur
     *                         négative est ramenée à 0 ici plutôt que de faire boucler
     *                         un appelant qui l'aurait mal lue
     *
     * @return list<int> les identifiants de personnes du cercle, `$racine` comprise,
     *                   dans un ordre stable (croissant) pour que la valeur mise en cache
     *                   soit comparable d'un calcul à l'autre
     */
    public function cercle(VueDeLArbre $arbre, int $racine, int $generations): array
    {
        // **Un cercle qu'on ne sait pas ancrer n'est pas un cercle réduit à une personne :
        // c'est l'absence de cercle.** Sans ce garde, `A(u) = { u }` rendrait `[u]` même
        // sur un arbre que le port n'a pas su charger — et le lecteur « verrait » sa propre
        // fiche sur un arbre vide. Le cas se produit pour de bon dans deux situations : le
        // port vers `Tree` non branché, et un `mbr_member.person_id` pendant, la
        // contrepartie assumée de l'absence de clé étrangère inter-module (ADR 0002).
        if (null === $arbre->personne($racine)) {
            return [];
        }

        $ascendance = $this->ascendance($arbre, $racine, max(0, $generations));
        $avecDescendants = $this->avecLesDescendants($arbre, $ascendance);

        // « En une seule passe, en dernier, et ne propagent rien » : on lit `$avecDescendants`,
        // figé, et on écrit dans `$cercle`. Remplacer `$avecDescendants` par `$cercle` dans
        // la boucle ci-dessous transformerait la passe en point fixe — c'est LE bogue que
        // l'ADR 0003 nomme, et il ne se voit pas à la lecture du résultat sur un petit arbre.
        $cercle = $avecDescendants;

        foreach (array_keys($avecDescendants) as $membre) {
            foreach ($arbre->conjointsDe($membre) as $conjoint) {
                $cercle[$conjoint] = true;
            }
        }

        $identifiants = array_keys($cercle);
        sort($identifiants);

        return $identifiants;
    }

    /**
     * `A(u)` — la personne et ses ancêtres jusqu'à `N` générations au-dessus.
     *
     * Parcours en largeur : la profondeur est le nombre d'arêtes parcourues, donc le
     * nombre de générations remontées, et la borne se lit directement. Une récursion
     * profondeur d'abord donnerait le même ensemble mais rendrait la borne plus difficile
     * à prouver — et c'est une borne qu'un administrateur change en production.
     *
     * @return array<int, true>
     */
    private function ascendance(VueDeLArbre $arbre, int $racine, int $generations): array
    {
        $vus = [$racine => true];
        $rang = [$racine];

        for ($niveau = 0; $niveau < $generations && [] !== $rang; ++$niveau) {
            $suivant = [];

            foreach ($rang as $personne) {
                foreach ($arbre->parentsDe($personne) as $parent) {
                    if (!isset($vus[$parent])) {
                        $vus[$parent] = true;
                        $suivant[] = $parent;
                    }
                }
            }

            $rang = $suivant;
        }

        return $vus;
    }

    /**
     * `A(u) ∪ D(u)` — tous les descendants, **à toute profondeur**, de tout membre de
     * `A(u)`.
     *
     * Aucune borne ici, et c'est l'asymétrie voulue. La file part de l'ascendance entière
     * d'un coup plutôt que d'un parcours par ancêtre : le résultat est le même ensemble,
     * pour un seul balayage au lieu de `|A(u)|`.
     *
     * @param array<int, true> $ascendance
     *
     * @return array<int, true>
     */
    private function avecLesDescendants(VueDeLArbre $arbre, array $ascendance): array
    {
        $vus = $ascendance;
        $file = array_keys($ascendance);

        while ([] !== $file) {
            $personne = array_pop($file);

            foreach ($arbre->enfantsDe($personne) as $enfant) {
                if (!isset($vus[$enfant])) {
                    $vus[$enfant] = true;
                    $file[] = $enfant;
                }
            }
        }

        return $vus;
    }
}
