<?php

declare(strict_types=1);

namespace App\Module\Tree\ValueObject;

use App\Module\Core\Enum\Sex;

/**
 * Une personne, réduite à ce dont le moteur de placement a besoin (ADR 0005).
 *
 * Volontairement pauvre : le moteur n'a **aucun accès à la base** — c'est la contrainte
 * qui le rend testable contre `graphe.json`. Tout ce qu'il sait d'une personne tient
 * dans cet objet, et rien de ce qui n'y figure pas ne peut déplacer un nœud.
 *
 * `generation` est une **entrée**, pas une sortie : l'ADR 0005 interdit au moteur
 * d'écrire `branch`, `generation` et `rank`, qui sont persistées par un service de
 * `Tree` après toute écriture structurante. Le moteur les reçoit déjà calculées.
 */
final readonly class PersonnePlacable
{
    /**
     * @param string   $id     identifiant stable ; `@I500001@` sur les données de
     *                         référence, l'identifiant de `tree_person` dans l'application
     * @param string   $nais   la date de naissance **déjà formatée** (« 26.9.2009 »), chaîne
     *                         vide si inconnue — le moteur en mesure la longueur, il ne la lit pas
     * @param string   $deces  idem pour le décès
     * @param int|null $anNais l'année de naissance, qui ordonne les fratries
     * @param bool     $vivant court-circuite la ligne de dates vers la constante « vivant·e ».
     *                         Sur `graphe.json` c'est la marque `RESN` du GEDCOM ; dans
     *                         l'application c'est l'état vivant calculé (§ 9.2 du modèle de
     *                         données). Ce n'est pas un détail d'affichage : sans cette
     *                         constante, **la largeur de la fiche encoderait la longueur des
     *                         dates d'une personne masquée** (ADR 0003 : tout champ dérivé
     *                         d'une donnée masquée est une donnée masquée). L'état vivant
     *                         étant une propriété de la personne et non du lecteur, la largeur
     *                         reste la même pour tous — et l'étage 1 du cache reste partageable.
     */
    public function __construct(
        public string $id,
        public string $prenom,
        public string $nom,
        public string $usage,
        public Sex $sexe,
        public string $nais,
        public string $deces,
        public ?int $anNais,
        public bool $vivant,
        public int $generation,
    ) {
    }
}
