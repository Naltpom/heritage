<?php

declare(strict_types=1);

namespace App\Module\Gedcom\Dto;

use App\Module\Tree\Enum\CitationContext;
use App\Module\Tree\Enum\CitationQuality;

/**
 * L'usage d'une source sur un enregistrement, avant de devenir une `tree_citation`.
 *
 * `quay` vaut `null` quand le fichier ne dit rien — ce qui n'est **pas** 0. Zéro est une
 * affirmation (« recopie non vérifiée ») ; `null` est une absence.
 */
final class CitationLue
{
    public function __construct(
        public readonly string $sourceXref,
        public readonly ?string $page,
        public readonly ?CitationQuality $quay,
        public readonly ?CitationContext $contexte,
        public readonly ?string $role,
        public readonly ?string $dateDonnees,
        public readonly ?string $texteDonnees,
        public readonly int $ligne,
    ) {
    }
}
