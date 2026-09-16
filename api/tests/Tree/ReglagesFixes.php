<?php

declare(strict_types=1);

namespace App\Tests\Tree;

use App\Module\Tree\Service\ReglagesDeLArbre;

/**
 * Les deux réglages, fixés pour le test.
 *
 * C'est la raison d'être de l'interface {@see ReglagesDeLArbre} : sans elle, éprouver le
 * fournisseur demanderait `core_setting`, donc les entités de `Core`, donc un noyau
 * Symfony — pour lire deux entiers.
 */
final class ReglagesFixes implements ReglagesDeLArbre
{
    public function __construct(
        private readonly int $seuil,
        private readonly ?int $racineId,
    ) {
    }

    public function seuilVivantAnnees(): int
    {
        return $this->seuil;
    }

    public function personneRacineId(): ?int
    {
        return $this->racineId;
    }
}
