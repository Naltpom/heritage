<?php

declare(strict_types=1);

namespace App\Module\Membership\Service;

use App\Module\Core\Contract\FichesTitulairesInterface;
use App\Module\Membership\Enum\MemberStatus;
use App\Module\Membership\Repository\MemberRepository;
use Symfony\Contracts\Service\ResetInterface;

/**
 * La réponse de `Membership` à {@see FichesTitulairesInterface} : les fiches dont un
 * compte **actif** est le titulaire (ADR 0024).
 *
 * « Actif » et rien d'autre : un compte en attente n'a pas encore été reconnu — par
 * l'invitation ou par un administrateur —, et un compte suspendu ne vaut plus
 * attestation. La date est celle de l'activation ; un rattachement activé d'office (le
 * compte d'amorçage, une invitation pré-liée) n'en a pas toujours, on prend alors celle du
 * rattachement.
 *
 * Mémorisée pour la requête — le graphe, son `ETag` et le relevé la demandent chacun —,
 * et oubliée entre deux requêtes (`ResetInterface`) : FrankenPHP garde le conteneur.
 */
final class FichesTitulairesDesMembres implements FichesTitulairesInterface, ResetInterface
{
    /** @var array<int, \DateTimeImmutable>|null */
    private ?array $memoire = null;

    public function __construct(private readonly MemberRepository $membres)
    {
    }

    public function fichesTitulaires(): array
    {
        if (null !== $this->memoire) {
            return $this->memoire;
        }

        $fiches = [];

        foreach ($this->membres->findBy(['status' => MemberStatus::Actif]) as $membre) {
            $personId = $membre->getPersonId();

            if (null !== $personId) {
                $fiches[$personId] = $membre->getActivatedAt() ?? $membre->getLinkedAt();
            }
        }

        ksort($fiches);

        return $this->memoire = $fiches;
    }

    public function reset(): void
    {
        $this->memoire = null;
    }
}
