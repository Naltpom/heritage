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
 *
 * `marie` est la nature de l'union (ADR 0020) : `true` mariés, `false` union libre, `null`
 * on ne sait pas. Elle dit au modèle qu'un mariage sans date est un mariage dont l'acte
 * manque — une recherche à faire —, et qu'une union libre n'en a pas à chercher. En dernier
 * et facultative : le modèle la lit, il ne la fabrique pas.
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
        public ?bool $marie = null,
    ) {
    }
}
