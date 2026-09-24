<?php

declare(strict_types=1);

namespace App\Module\Assistant\Service;

/**
 * Le résultat de {@see TraducteurDePropositions} : le résumé, les demandes bien formées,
 * les portraits bien formés, et les avertissements — ceux du modèle, puis ceux de la
 * traduction.
 */
final readonly class PropositionsTraduites
{
    /**
     * @param list<PropositionTraduite> $propositions
     * @param list<string>              $avertissements
     * @param list<PortraitTraduit>     $portraits
     */
    public function __construct(
        public string $resume,
        public array $propositions,
        public array $avertissements,
        public array $portraits = [],
    ) {
    }
}
