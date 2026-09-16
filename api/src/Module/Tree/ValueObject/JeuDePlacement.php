<?php

declare(strict_types=1);

namespace App\Module\Tree\ValueObject;

/**
 * L'entrée du moteur de placement : l'arbre entier, en mémoire (ADR 0005).
 *
 * **L'ordre des deux listes fait partie du contrat.** Ce n'est pas une commodité : le
 * point fixe en 12 passes et le décrochage rangée par rangée départagent par l'ordre de
 * parcours deux nœuds à égalité de position. Le script d'origine parcourait les
 * dictionnaires `indi` et `fam` dans l'ordre du fichier GEDCOM ; le portage doit
 * recevoir le même ordre, sans quoi quelques nœuds se retrouvent décalés de la largeur
 * d'une fiche sans qu'aucun invariant ne s'en plaigne.
 *
 * Dans l'application, l'ordre à fournir est celui de `tree_person.id` puis
 * `tree_family.id` croissants — l'import GEDCOM les crée dans l'ordre du fichier, donc
 * les deux coïncident.
 */
final readonly class JeuDePlacement
{
    /**
     * @param list<PersonnePlacable> $personnes dans l'ordre d'origine, jamais retriées
     * @param list<FamillePlacable>  $familles  dans l'ordre d'origine, jamais retriées
     * @param string                 $egoId     la personne racine, ramenée à `x = 0` en fin de calcul
     */
    public function __construct(
        public array $personnes,
        public array $familles,
        public string $egoId,
    ) {
    }
}
