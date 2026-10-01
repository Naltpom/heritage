<?php

declare(strict_types=1);

namespace App\Module\Assistant\Service;

/**
 * Ce que l'agent de consultation reçoit : la question, les échanges précédents de la
 * conversation (déjà bornés et déjà repassés au cercle du lecteur), et la fiche du
 * lecteur — l'ego de « je », « mon grand-père », et de la parenté.
 */
final readonly class EntreeDeQuestion
{
    /**
     * @param list<EchangePasse> $historique du plus ancien au plus récent
     */
    public function __construct(
        public string $question,
        public array $historique = [],
        public ?int $personneDuLecteur = null,
    ) {
    }
}
