<?php

declare(strict_types=1);

namespace App\Module\Gedcom\Dto;

use App\Module\Core\ValueObject\UncertainDate;

/**
 * Une union lue dans le fichier, avant de devenir une `tree_family`.
 *
 * Une famille peut n'avoir qu'un seul époux — 3 cas `WIFE` sans `HUSB` — et n'avoir
 * aucun enfant — 7 cas. Ce sont des données valides, pas des lignes à écarter. Zéro
 * époux, en revanche, n'est pas une famille : `ck_tree_family_au_moins_un_epoux` le
 * refuse en base.
 */
final class FamilleLue
{
    /**
     * @param list<string>      $enfants   xrefs, **dans l'ordre des lignes `CHIL`**
     * @param list<NoteLue>     $notes
     * @param list<CitationLue> $citations
     */
    public function __construct(
        public readonly string $xref,
        public readonly ?string $uid,
        public readonly ?string $rin,
        public readonly ?string $mari,
        public readonly ?string $femme,
        public readonly array $enfants,
        public readonly UncertainDate $mariage,
        public readonly bool $divorce,
        public readonly UncertainDate $dateDivorce,
        public readonly array $notes,
        public readonly array $citations,
        public readonly int $ligne,
    ) {
    }

    /**
     * Les époux présents, **mari d'abord** : c'est l'ordre de `graphe.py`, et il décide
     * du barycentre du nœud de jonction comme de la propagation des générations.
     *
     * @return list<string>
     */
    public function epoux(): array
    {
        return array_values(array_filter([$this->mari, $this->femme], static fn (?string $x): bool => null !== $x));
    }
}
