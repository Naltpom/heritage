<?php

declare(strict_types=1);

namespace App\Module\Assistant\Contract;

use App\Module\Core\ValueObject\UncertainDate;

/**
 * Un fait de la fiche : une profession, une résidence, un baptême, une inhumation.
 *
 * `type` est le **libellé français** du type de fait (« Profession »), pas la valeur
 * d'énumération : le modèle le lit, l'assistant ne le compare à rien — ce n'est donc pas
 * un vocabulaire qui traverse la frontière (ADR 0002, « un contrat ne transporte pas un
 * vocabulaire déguisé »).
 */
final readonly class FaitDeLArbre
{
    public function __construct(
        public string $type,
        public ?string $libelle,
        public UncertainDate $date,
        public ?string $adresse,
        public ?string $age,
        public ?string $cause,
    ) {
    }
}
