<?php

declare(strict_types=1);

namespace App\Module\Assistant\Service;

/**
 * Ce que la boucle d'agent rend : les arguments bruts de `proposer`, et de quoi
 * renseigner le journal d'audit (combien de tours, quels outils) — des comptes, jamais
 * de contenu.
 */
final readonly class ResultatDeLAgent
{
    /**
     * @param array<string, mixed> $arguments     les arguments de `proposer`, non validés
     * @param array<string, int>   $appelsDOutils le nombre d'appels par outil de lecture
     */
    public function __construct(
        public array $arguments,
        public int $tours,
        public array $appelsDOutils,
    ) {
    }
}
