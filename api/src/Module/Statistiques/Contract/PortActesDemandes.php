<?php

declare(strict_types=1);

namespace App\Module\Statistiques\Contract;

/**
 * Le chantier (administration) : les actes demandés aux mairies et aux archives qui ne
 * sont pas encore arrivés. Implémenté par `Demarche`, seul juge de ce qu'« en attente »
 * veut dire (`StatutDeDemarche::enAttente()` : envoyée, relancée ou expédiée).
 */
interface PortActesDemandes
{
    public function enAttente(): int;
}
