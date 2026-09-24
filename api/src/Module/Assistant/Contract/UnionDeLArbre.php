<?php

declare(strict_types=1);

namespace App\Module\Assistant\Contract;

use App\Module\Core\ValueObject\UncertainDate;

/**
 * Une union d'une personne : le conjoint (s'il est connu), les dates, les enfants.
 *
 * `familleId` est donné au modèle parce qu'une demande `ajout_lien` peut rattacher un
 * enfant à **un couple existant** plutôt qu'à deux parents nommés — c'est le seul moyen de
 * ne pas faire créer une seconde famille pour le même couple.
 */
final readonly class UnionDeLArbre
{
    /**
     * @param list<PersonneDeLArbre> $enfants
     */
    public function __construct(
        public int $familleId,
        public ?PersonneDeLArbre $conjoint,
        public UncertainDate $mariage,
        public bool $divorce,
        public array $enfants,
    ) {
    }
}
