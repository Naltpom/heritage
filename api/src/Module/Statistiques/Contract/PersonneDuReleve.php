<?php

declare(strict_types=1);

namespace App\Module\Statistiques\Contract;

use App\Module\Core\Enum\Branch;
use App\Module\Core\Enum\Sex;
use App\Module\Core\ValueObject\UncertainDate;

/**
 * Une personne de l'arbre, telle que les statistiques la lisent.
 *
 * `branch` et `generation` sont les colonnes dérivées de `tree_person`, **calculées depuis
 * la personne racine de l'arbre** (`ReleveDeLArbre::$racineId`) : les statistiques
 * décrivent l'arbre de la famille, pas le point de vue de chaque lecteur. C'est dit à
 * l'écran (« branches lues depuis la racine de l'arbre »).
 *
 * `bestQuay` et `citationCount` sont les deux colonnes dérivées qui définissent
 * « Vérifié » dans tout le produit : `bestQuay ≥ 2` (acte ou registre).
 */
final readonly class PersonneDuReleve
{
    public function __construct(
        public int $id,
        public string $displayName,
        public string $prenoms,
        public string $nom,
        public Sex $sex,
        public UncertainDate $naissance,
        public UncertainDate $deces,
        /** La règle calculée du § 9.2 (une fiche confidentielle est « vivante »). */
        public bool $vivant,
        /** Un décès est noté (`DEAT` présent), daté ou non. */
        public bool $decede,
        public ?Branch $branch,
        public ?int $generation,
        public ?int $bestQuay,
        public int $citationCount,
        public \DateTimeImmutable $creeLe,
    ) {
    }
}
