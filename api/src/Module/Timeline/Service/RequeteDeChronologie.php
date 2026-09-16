<?php

declare(strict_types=1);

namespace App\Module\Timeline\Service;

use App\Module\Core\Enum\Branch;
use App\Module\Timeline\Enum\TimelineOrientation;

/**
 * Les trois réglages effectifs d'un `GET /api/chronologie`, une fois la préférence du
 * compte et les paramètres de requête résolus.
 *
 * L'ordre de résolution est celui du tableau du § 9.1, et il ne comporte que deux
 * niveaux : **le paramètre de requête s'il est fourni, la préférence sinon** — laquelle
 * retombe elle-même sur `timeline.bande_annees_defaut` quand aucune ligne n'existe. Les
 * trois paramètres sont indépendants : fournir `bande` seul laisse `orientation` et
 * `branches` à la préférence.
 *
 * `showUncertainty` n'est pas un paramètre de requête — il n'y en a que trois au § 9.1 —
 * mais il figure dans la réponse, donc dans cet objet : le front a besoin de savoir s'il
 * doit hachurer sans avoir à interroger `/preferences` en plus.
 */
final readonly class RequeteDeChronologie
{
    /** @param list<Branch> $branches */
    public function __construct(
        public int $bande,
        public TimelineOrientation $orientation,
        public array $branches,
        public bool $showUncertainty,
    ) {
    }

    public function afficheLaBranche(?Branch $branche): bool
    {
        return null !== $branche && \in_array($branche, $this->branches, true);
    }
}
