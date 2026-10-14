<?php

declare(strict_types=1);

namespace App\Module\Tree\ValueObject;

use App\Module\Core\Enum\Branch;
use App\Module\Tree\Enum\ParenteDansLArbre;

/**
 * Les colonnes dérivées que l'arbre attend : génération et branche par personne,
 * génération et rang par famille.
 *
 * Les identifiants sont ceux de l'appelant : les identifiants de nœud (`p1`, `f28`)
 * de l'arbre en base — l'import GEDCOM, supprimé depuis (ADR 0018), y passait les `xref`
 * (`@I500001@`). Ils portent leur type, ce qui est indispensable : `tree_person.id` et
 * `tree_family.id` sont deux séquences indépendantes, et la personne 1 comme la
 * famille 1 existent.
 *
 * « Logique » et non « graphique » : ce sont des **coordonnées d'ordre**, pas des
 * positions. Le `x` et le `y` du rendu sont l'affaire du moteur de placement (ADR 0005),
 * qui consomme ces valeurs et n'écrit rien en base.
 */
final class PlacementLogique
{
    /**
     * @param array<string, int>    $generations        xref de personne → rang, 0 pour l'ego, négatif en remontant
     * @param array<string, Branch> $branches           xref de personne → quart d'ascendance
     * @param array<string, int>    $generationsFamille xref de famille → rang
     * @param array<string, int>    $rangs              xref de famille → `2 × génération + 1`
     * @param array<string, ParenteDansLArbre> $parentes xref de personne → famille, conjoint ou
     *                                                   alliance, **vu du lecteur**. Vide hors
     *                                                   de {@see \App\Module\Tree\Service\CalculAscendance::vuDuLecteur()} :
     *                                                   la colonne persistée n'en a pas l'usage
     * @param array<string, string> $ponts xref d'une personne `alliance` → xref du
     *                                     conjoint « famille » qui la rattache au
     *                                     lecteur (un îlot qu'aucun chemin ne relie
     *                                     n'est pas `alliance` : il n'a pas de pont)
     */
    public function __construct(
        public readonly array $generations,
        public readonly array $branches,
        public readonly array $generationsFamille,
        public readonly array $rangs,
        public readonly array $parentes = [],
        public readonly array $ponts = [],
    ) {
    }

    /**
     * Le nombre de générations couvertes — 12 sur le fichier de référence, de 0 à −11.
     */
    public function nombreDeGenerations(): int
    {
        if ([] === $this->generations) {
            return 0;
        }

        return max($this->generations) - min($this->generations) + 1;
    }

    /**
     * @return array<value-of<Branch>, int>
     */
    public function repartitionParBranche(): array
    {
        $repartition = [];

        foreach (Branch::cases() as $branche) {
            $repartition[$branche->value] = 0;
        }

        foreach ($this->branches as $branche) {
            ++$repartition[$branche->value];
        }

        return $repartition;
    }
}
