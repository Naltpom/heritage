<?php

declare(strict_types=1);

namespace App\Module\Timeline\Service;

/**
 * Le découpage de l'axe en bandes de `N` années — § 9.1 du contrat d'API, **à la lettre**.
 *
 * ## L'opération, recopiée telle qu'elle est écrite
 *
 * > `debut(k) = bornes.min + k × bande` et `fin(k) = debut(k) + bande − 1`, pour
 * > `k = 0` tant que `debut(k) ≤ bornes.max`. Il n'y a **ni arrondi, ni division** : la
 * > dernière bande est partielle et sa `fin` dépasse `bornes.max`, ce qui est correct et
 * > voulu.
 *
 * Le § 9.1 prend la peine de l'écrire sous cette forme « pour qu'un second implémenteur
 * retombe sur les mêmes bornes ». Ce fichier est ce second implémenteur, et il ne fait
 * rien d'autre que transcrire.
 *
 * ## Les trois façons de se tromper ici, et pourquoi elles sont tentantes
 *
 * 1. **Diviser pour obtenir le nombre de bandes.** `ceil((max − min + 1) / bande)` semble
 *    équivalent et l'est presque toujours. « Presque » est le problème : ce projet a déjà
 *    perdu du temps sur un `arrondi` posé là où la source faisait un `tronqué`, et une
 *    division introduit exactement ce choix-là — `ceil` ou `intdiv` ? — là où la
 *    récurrence n'en offre aucun. On itère, on ne divise pas.
 * 2. **Rogner la dernière bande à `bornes.max`.** Elle *a l'air* fausse : avec `min = 1646`,
 *    `max = 2026` et `bande = 25`, la seizième bande va de 2021 à **2045**. C'est le
 *    résultat attendu — une bande est une tranche de `N` années, et une tranche rognée
 *    n'en fait plus `N`. Le front dessine des tranches de largeur égale ; une dernière
 *    plus courte décalerait toute la grille.
 * 3. **Recalculer les bandes côté client.** « Le front ne les recalcule pas » (§ 9.1) :
 *    c'est ce serveur qui fait foi. Deux implémentations d'une même récurrence, c'est une
 *    divergence qui attend son heure.
 *
 * ## Le garde-fou sur `bande`
 *
 * `bande` est validée **avant** d'arriver ici — 1 à 500, § 9.1 et `ck_tml_preference_bande`.
 * L'assertion ci-dessous n'est pas une seconde validation, c'est le filet qui empêche la
 * boucle de ne jamais finir si un appelant futur oubliait la première : à `bande ≤ 0`,
 * `debut(k)` ne progresse plus et la condition `debut(k) ≤ bornes.max` reste vraie
 * indéfiniment. Une exception franche vaut mieux qu'un processus qui tourne.
 */
final class CalculDesBandes
{
    /** @return list<Bande> au moins une, toujours : `k = 0` vérifie la condition par construction */
    public function bandes(Bornes $bornes, int $bande): array
    {
        if ($bande < 1) {
            throw new \InvalidArgumentException(\sprintf('La largeur de bande doit valoir au moins 1 année, reçu %d. Elle est bornée à 1–500 par le § 9.1 et validée avant d\'arriver ici.', $bande));
        }

        $bandes = [];

        for ($k = 0; ($debut = $bornes->min + $k * $bande) <= $bornes->max; ++$k) {
            $bandes[] = new Bande($debut, $debut + $bande - 1);
        }

        return $bandes;
    }
}
