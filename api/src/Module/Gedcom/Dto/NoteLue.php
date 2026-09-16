<?php

declare(strict_types=1);

namespace App\Module\Gedcom\Dto;

use App\Module\Tree\Enum\NoteScope;

/**
 * Une note de recherche lue dans le fichier, avant de devenir une `tree_note`.
 *
 * La portée résout le découpage du § 3.1 : la naissance et le décès n'ont pas de ligne à
 * eux, ce sont des colonnes. Un `1 BIRT / 2 NOTE` devient donc une note de portée
 * `naissance` rattachée à la personne — et non une note orpheline.
 */
final class NoteLue
{
    public function __construct(
        public readonly string $corps,
        public readonly NoteScope $portee,
        public readonly int $ligne,
    ) {
    }
}
