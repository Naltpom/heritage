<?php

declare(strict_types=1);

namespace App\Module\Assistant\Contract;

use App\Module\Core\Enum\Sex;
use App\Module\Core\ValueObject\UncertainDate;

/**
 * Une personne de l'arbre telle que l'assistant la voit : **non filtrée**.
 *
 * `vivante` est la règle de `Tree` (décès connu, ou âge probable), pas le simple drapeau
 * `deceased` : c'est elle que le graphe applique, et c'est donc elle qui décide, à la
 * sortie, si le lecteur voit les dates de cette personne.
 */
final readonly class PersonneDeLArbre
{
    /**
     * @param list<PersonneDeLArbre> $parents   les parents, **sans** leur propre parenté —
     *                                          des personnes et non des noms : l'assistant
     *                                          confronte chacune au cercle du lecteur
     *                                          (`vivante`) avant d'en écrire le nom, comme pour
     *                                          la personne elle-même (révision du 2026-10-01
     *                                          de l'ADR 0003). Vides quand la
     *                                          personne est rendue comme parente d'une autre
     * @param list<PersonneDeLArbre> $conjoints les conjoints, mêmes remarques
     */
    public function __construct(
        public int $id,
        public string $nomAffiche,
        public Sex $sexe,
        public bool $vivante,
        public UncertainDate $naissance,
        public UncertainDate $deces,
        public array $parents = [],
        public array $conjoints = [],
    ) {
    }
}
