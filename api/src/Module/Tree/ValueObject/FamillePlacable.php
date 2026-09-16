<?php

declare(strict_types=1);

namespace App\Module\Tree\ValueObject;

/**
 * Une union, réduite à ce dont le moteur de placement a besoin (ADR 0005).
 *
 * **L'ordre des deux listes porte de l'information et ne doit jamais être trié à la
 * volée.** `epoux` est dans l'ordre `HUSB` puis `WIFE` : c'est lui qui fixe le premier
 * parent d'un enfant, donc la fratrie de référence de la phase 2 du moteur. `enfants`
 * est dans l'ordre des lignes `CHIL` du GEDCOM, que porte `tree_child.sibling_order` —
 * ce n'est **pas** l'ordre des naissances, 50 personnes n'ayant aucune date.
 */
final readonly class FamillePlacable
{
    /**
     * @param list<string> $epoux   0 à 2 identifiants, `HUSB` d'abord ; 3 familles des
     *                              données réelles n'ont qu'une `WIFE`
     * @param list<string> $enfants 0 à n identifiants, dans l'ordre des `CHIL`
     */
    public function __construct(
        public string $id,
        public array $epoux,
        public array $enfants,
        public bool $aDivorce,
    ) {
    }
}
