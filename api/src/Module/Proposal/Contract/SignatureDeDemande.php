<?php

declare(strict_types=1);

namespace App\Module\Proposal\Contract;

/**
 * Qui a déposé la demande, et quand — la signature que `PortArbre::appliquerChamps()`
 * reçoit pour écrire une fin de recherche (ADR 0023, § 5).
 *
 * L'auteur est le **déposant**, pas l'administrateur qui valide ; la date est celle du
 * **dépôt** (`submitted_at`), pas de la validation. Une fin de recherche date l'état des
 * sources consultées. Sous un jeton d'API, `auteurUserId` est le compte du jeton.
 */
final readonly class SignatureDeDemande
{
    public function __construct(
        public int $auteurUserId,
        public string $auteurLabel,
        public \DateTimeImmutable $deposeeLe,
    ) {
    }
}
