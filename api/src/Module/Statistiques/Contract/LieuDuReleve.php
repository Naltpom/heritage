<?php

declare(strict_types=1);

namespace App\Module\Statistiques\Contract;

/**
 * Une ligne de `tree_place` : la chaîne `PLAC` repliée (`cle`, même repliement que la
 * carte — `Core\Service\CleDeRecherche::replier()`), et ce que l'appariement en a tiré.
 * Les coordonnées peuvent manquer : une commune reconnue sans position sert encore au
 * palmarès des communes ; une distance, elle, exige les deux bouts.
 */
final readonly class LieuDuReleve
{
    public function __construct(
        public string $cle,
        public string $libelle,
        public ?string $commune,
        public ?string $subdivision,
        /** Code ISO 3166-1 alpha-2 : `FR`, `DZ`… */
        public ?string $pays,
        /** Le nom français du pays (CLDR), pour qu'aucun module ne recopie la table. */
        public ?string $nomDuPays,
        public ?float $lat,
        public ?float $lon,
    ) {
    }
}
