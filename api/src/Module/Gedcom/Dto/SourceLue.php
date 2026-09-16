<?php

declare(strict_types=1);

namespace App\Module\Gedcom\Dto;

use App\Module\Tree\Enum\SourceKind;

/**
 * Un fonds documentaire lu dans le fichier, avant de devenir une `tree_source`.
 *
 * Son `NOTE` va dans {@see \App\Module\Tree\Entity\Source::$note} et **pas** dans
 * `tree_note` : c'est tout l'écart entre les 189 `NOTE` du fichier et les 175 notes de
 * recherche.
 */
final class SourceLue
{
    public function __construct(
        public readonly string $xref,
        public readonly string $titre,
        public readonly ?string $auteur,
        public readonly ?string $publication,
        public readonly ?string $texte,
        public readonly ?string $note,
        public readonly ?string $typeMedia,
        public readonly SourceKind $genre,
        public readonly int $ligne,
    ) {
    }
}
