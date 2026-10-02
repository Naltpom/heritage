<?php

declare(strict_types=1);

namespace App\Module\Statistiques\Contract;

/** Le compte d'un mois, pour le graphique d'activité du chantier. */
final readonly class DemandesDuMois
{
    public function __construct(
        /** `AAAA-MM`. */
        public string $mois,
        /** Déposées ce mois-là (`submittedAt`). */
        public int $proposees,
        /** Validées ce mois-là (`decidedAt`, statut « validée », validations automatiques comprises). */
        public int $validees,
    ) {
    }
}
