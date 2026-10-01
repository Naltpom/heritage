<?php

declare(strict_types=1);

namespace App\Module\Assistant\Contract;

/**
 * Ce que la fiche d'une personne établit, au-delà de son identité et de sa parenté —
 * ce dont l'assistant a besoin pour répondre « que sait-on de… ? » sans rien inventer.
 */
final readonly class DossierDeLaPersonne
{
    /**
     * @param list<FaitDeLArbre> $faits   profession, résidence, baptême… dans l'ordre de la fiche
     * @param list<SourceCitee>  $sources les citations de la personne **et de ses faits**
     * @param list<string>       $notes   le texte des notes, tel qu'il est saisi
     */
    public function __construct(
        public array $faits,
        public array $sources,
        public array $notes,
    ) {
    }
}
