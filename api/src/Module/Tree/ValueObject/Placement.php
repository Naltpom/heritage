<?php

declare(strict_types=1);

namespace App\Module\Tree\ValueObject;

/**
 * La sortie du moteur : les nœuds positionnés, les arêtes, la boîte englobante.
 *
 * Sur les données réelles : 377 nœuds (274 personnes + 103 familles) et 377 arêtes.
 *
 * **Cet objet est l'étage 1 du cache, et il ne sort jamais tel quel de l'API** (ADR 0005).
 * C'est une structure interne, servie uniquement à travers la projection de l'étage 2.
 * Le « calculé sans filtre de confidentialité » porte sur le calcul du placement ; il
 * n'ouvre aucun mode « sans filtre » dans la sérialisation, que l'ADR 0003 interdit.
 *
 * Il ne contient d'ailleurs **aucune donnée personnelle** — que des identifiants et des
 * coordonnées. C'est ce qui rend l'étage 1 partageable entre tous les lecteurs : le
 * cercle de visibilité masque des champs, pas des nœuds.
 */
final readonly class Placement
{
    /**
     * @param array<string, NoeudPlace> $noeuds indexés par identifiant, **dans l'ordre de
     *                                          placement** : rangées croissantes, puis
     *                                          gauche à droite, puis les nœuds familiaux
     * @param list<AreteDePlacement>    $aretes
     * @param string                    $egoId  la personne racine, ramenée à `x = 0`. Le
     *                                          viewer y adosse son cadrage initial, et la
     *                                          projection de l'étage 2 doit pouvoir
     *                                          restaurer l'invariant après insertion —
     *                                          d'où sa présence ici plutôt qu'une
     *                                          détection par « le nœud qui est à zéro »,
     *                                          que deux nœuds peuvent satisfaire
     */
    public function __construct(
        public array $noeuds,
        public array $aretes,
        public BoiteEnglobante $boite,
        public string $egoId,
    ) {
    }

    public function noeud(string $id): ?NoeudPlace
    {
        return $this->noeuds[$id] ?? null;
    }
}
