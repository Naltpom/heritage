<?php

declare(strict_types=1);

namespace App\Module\Tree\Service;

use App\Module\Tree\ValueObject\JeuDePlacement;

/**
 * Ce qui sait charger l'arbre validé complet depuis la base, pour le donner au moteur.
 *
 * Cette interface existe pour **tenir la contrainte de l'ADR 0005** : le moteur n'a aucun
 * accès à la base, et c'est elle qui le rend testable contre `graphe.json`. Toute la
 * lecture Doctrine est donc rejetée derrière ce contrat, et le moteur ne voit jamais
 * qu'un `JeuDePlacement` en mémoire.
 *
 * ## Deux contraintes que l'implémentation doit respecter
 *
 * **1. L'ordre.** `personnes` et `familles` doivent être rendues dans un ordre
 * déterministe et stable — `ORDER BY id` sur `tree_person` puis sur `tree_family`.
 * L'import GEDCOM les crée dans l'ordre du fichier, donc cet ordre est celui du GEDCOM,
 * celui-là même que le script d'origine parcourait. Ce n'est pas une commodité : le
 * point fixe en 12 passes et le décrochage rangée par rangée y départagent les égalités
 * (voir {@see JeuDePlacement}).
 *
 * **2. Aucun filtre de confidentialité.** L'étage 1 est calculé sur l'arbre validé
 * **complet** : le cercle de visibilité masque des champs, pas des nœuds. Filtrer ici
 * donnerait un placement par compte et ferait tomber tout le cache partagé.
 *
 * L'implémentation Doctrine n'est pas fournie par la tâche de portage du moteur : elle
 * suppose des méthodes de lecture sur `PersonRepository`, `FamilyRepository` et
 * `ChildRepository`, ainsi que le formatage des dates incertaines (§ 9.1) et la règle
 * « personne vivante » (§ 9.2), qui appartiennent à d'autres tâches.
 */
interface FournisseurDeJeuDePlacement
{
    public function jeuComplet(): JeuDePlacement;
}
