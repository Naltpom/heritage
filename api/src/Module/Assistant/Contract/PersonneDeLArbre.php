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
     * @param list<string> $parents   les noms affichés des parents — vides quand la
     *                                personne est rendue comme parente d'une autre
     * @param list<string> $conjoints les noms affichés des conjoints, même remarque
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
