<?php

declare(strict_types=1);

namespace App\Tests\Membership\Double;

use App\Module\Membership\Service\ReglagesDeMembership;

/**
 * Les quatre réglages d'administration, posés en une ligne.
 *
 * Un test qui doit semer `core_setting` pour vérifier qu'un plafond tient est un test
 * qu'on n'écrit pas — c'est l'argument qui a fait de {@see ReglagesDeMembership} une
 * interface plutôt qu'une classe.
 */
final class ReglagesFiges implements ReglagesDeMembership
{
    public function __construct(
        private int $generations = 3,
        private readonly int $ttlJours = 7,
        private readonly int $tentativesParHeure = 5,
        private readonly int $candidatsMax = 3,
    ) {
    }

    /** Change `N` comme le ferait un `PATCH /api/administration/reglages/cercle.generations`. */
    public function regler(int $generations): void
    {
        $this->generations = $generations;
    }

    public function generationsDuCercle(): int
    {
        return $this->generations;
    }

    public function ttlDInvitationEnJours(): int
    {
        return $this->ttlJours;
    }

    public function tentativesParHeure(): int
    {
        return $this->tentativesParHeure;
    }

    public function candidatsMax(): int
    {
        return $this->candidatsMax;
    }
}
