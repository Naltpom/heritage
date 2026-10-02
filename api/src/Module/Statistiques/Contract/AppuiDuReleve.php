<?php

declare(strict_types=1);

namespace App\Module\Statistiques\Contract;

/**
 * Une citation de source, réduite à ce qui fait « Vérifié » : sa qualité (`QUAY`, 0 à 3,
 * `null` si non notée), ce qu'elle appuie, et **sa date de saisie** — c'est elle qui
 * permet de retracer l'évolution de la part vérifiée mois par mois.
 *
 * La même règle que `tree_person.best_quay` : une citation de **personne** compte pour la
 * fiche ; une citation de famille ne compte que pour le mariage.
 */
final readonly class AppuiDuReleve
{
    public function __construct(
        public ?int $personId,
        public ?int $familyId,
        public PorteeDeLAppui $portee,
        public ?int $quay,
        public \DateTimeImmutable $creeLe,
    ) {
    }
}
