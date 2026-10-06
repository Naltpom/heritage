<?php

declare(strict_types=1);

namespace App\Module\Proposal\Service;

use App\Module\Core\Enum\Sex;
use App\Module\Core\ValueObject\UncertainDate;

/**
 * Ce que {@see RapprochementDeDoublons} compare d'une personne — la saisie du formulaire,
 * une fiche de l'arbre ou une fiche proposée, ramenées à la même forme.
 *
 * Les proches sont des **identifiants de personnes de l'arbre**, rien d'autre : une fiche
 * proposée n'en a pas, et un libellé ne se compare pas.
 */
final readonly class IdentiteComparee
{
    /**
     * @param list<int> $parents
     * @param list<int> $conjoints
     * @param list<int> $enfants
     */
    public function __construct(
        public string $givenNames,
        public string $surname,
        public ?string $marriedName,
        public Sex $sex,
        public UncertainDate $naissance,
        public UncertainDate $deces,
        public array $parents = [],
        public array $conjoints = [],
        public array $enfants = [],
    ) {
    }
}
