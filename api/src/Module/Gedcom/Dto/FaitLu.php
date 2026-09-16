<?php

declare(strict_types=1);

namespace App\Module\Gedcom\Dto;

use App\Module\Core\ValueObject\UncertainDate;
use App\Module\Tree\Enum\FactType;

/**
 * Un événement daté autre que naissance, décès, mariage et divorce, avant de devenir
 * une `tree_fact`.
 *
 * Ces quatre-là sont des colonnes de `tree_person` et `tree_family` : le § 3.1 du modèle
 * interdit explicitement d'en faire **aussi** des faits, parce qu'un fait dupliqué est
 * une divergence qui attend son heure.
 */
final class FaitLu
{
    /**
     * @param list<NoteLue>     $notes
     * @param list<CitationLue> $citations aucune dans le fichier actuel — les 483 citations
     *                                     sont toutes de niveau 1 —, mais `tree_citation.fact_id`
     *                                     existe et un autre exporteur s'en sert
     */
    public function __construct(
        public readonly FactType $type,
        public readonly ?string $libelle,
        public readonly UncertainDate $quand,
        public readonly ?string $adresse,
        public readonly ?string $age,
        public readonly ?string $cause,
        public readonly int $ordre,
        public readonly array $notes,
        public readonly array $citations,
        public readonly int $ligne,
    ) {
    }
}
