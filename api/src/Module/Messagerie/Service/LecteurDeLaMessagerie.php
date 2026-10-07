<?php

declare(strict_types=1);

namespace App\Module\Messagerie\Service;

/**
 * Qui lit. `sousBascule` : un administrateur qui lit **comme** un membre (« voir comme ») —
 * il voit les fils de ce membre, n'écrit rien, et sa lecture ne pose aucun accusé : ce
 * n'est pas le membre qui a lu.
 */
final readonly class LecteurDeLaMessagerie
{
    public function __construct(
        public int $userId,
        public string $libelle,
        public bool $administrateur,
        public bool $sousBascule = false,
    ) {
    }
}
