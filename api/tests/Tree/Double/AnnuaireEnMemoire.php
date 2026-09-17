<?php

declare(strict_types=1);

namespace App\Tests\Tree\Double;

use App\Module\Core\Contract\AnnuaireDesMembresInterface;

/**
 * Un annuaire des membres écrit à la main, pour les cas qui ont besoin de savoir
 * **depuis quelle personne un compte regarde l'arbre** — et de rien d'autre.
 *
 * Les quatre autres méthodes rendent le vide plutôt que d'inventer : un double qui répond
 * à des questions que le cas ne pose pas finit par masquer l'appel qu'on ne voulait pas
 * voir arriver.
 */
final class AnnuaireEnMemoire implements AnnuaireDesMembresInterface
{
    /** @param array<int, int> $personneParCompte */
    public function __construct(private readonly array $personneParCompte = [])
    {
    }

    public function personneDuCompte(int $userId): ?int
    {
        return $this->personneParCompte[$userId] ?? null;
    }

    public function libelleDuMembre(int $memberId): ?string
    {
        return null;
    }

    /** @return array<string, mixed>|null */
    public function membreDuCompte(int $userId): ?array
    {
        return null;
    }

    public function userIdDuMembre(int $memberId): ?int
    {
        return null;
    }

    /** @return array{membreId: int|null, membreLibelle: string|null, invitationsActives: int} */
    public function rattachementsDeLaPersonne(int $personneId): array
    {
        return ['membreId' => null, 'membreLibelle' => null, 'invitationsActives' => 0];
    }
}
