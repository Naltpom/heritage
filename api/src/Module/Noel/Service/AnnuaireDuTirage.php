<?php

declare(strict_types=1);

namespace App\Module\Noel\Service;

use App\Module\Core\Entity\User;
use App\Module\Noel\Contract\IdentiteDuCompte;
use App\Module\Noel\Contract\PortComptesPourNoel;
use App\Module\Noel\Contract\PortPhotosPourNoel;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Qui sont les comptes, vus du site de Noël : prénom, nom, photo, âge.
 *
 * Le nom vient de `Membership` (la déclaration de l'inscription, sinon la fiche
 * rattachée) ; un compte sans membre retombe sur son nom affiché, puis sur le début de son
 * courriel. La photo : celle de `Media` si elle existe — servie par
 * `GET /api/noel/photos/{userId}` —, sinon la photo de profil Google.
 */
final class AnnuaireDuTirage implements ResetInterface
{
    /** @var array<int, IdentiteDuCompte> */
    private array $identites = [];

    /** @var array<int, bool> */
    private array $photos = [];

    public function __construct(
        private readonly PortComptesPourNoel $comptes,
        private readonly PortPhotosPourNoel $medias,
    ) {
    }

    public function reset(): void
    {
        $this->identites = [];
        $this->photos = [];
    }

    /** @param list<User> $comptes charge en une fois ce que la sérialisation demandera */
    public function precharger(array $comptes): void
    {
        $ids = array_values(array_filter(
            array_map(static fn (User $u): ?int => $u->getId(), $comptes),
            fn (?int $id): bool => null !== $id && !isset($this->identites[$id]),
        ));

        if ([] === $ids) {
            return;
        }

        $this->identites += $this->comptes->identites($ids);

        $parCompte = [];
        foreach ($ids as $id) {
            $parCompte[$id] = $this->identites[$id]->personId ?? null;
            $this->photos[$id] = false;
        }
        foreach ($this->medias->avecPhoto($parCompte) as $id) {
            $this->photos[$id] = true;
        }
    }

    public function identite(User $compte): ?IdentiteDuCompte
    {
        $this->precharger([$compte]);

        return $this->identites[(int) $compte->getId()] ?? null;
    }

    /** @return array{prenom: string, nom: string} */
    public function nom(User $compte): array
    {
        $identite = $this->identite($compte);

        if (null !== $identite) {
            return ['prenom' => $identite->prenom, 'nom' => $identite->nom];
        }

        $affiche = trim($compte->getDisplayName());

        return ['prenom' => '' !== $affiche ? $affiche : explode('@', $compte->getEmail())[0], 'nom' => ''];
    }

    public function photoUrl(User $compte): ?string
    {
        $this->precharger([$compte]);

        if ($this->photos[(int) $compte->getId()] ?? false) {
            return '/api/noel/photos/'.$compte->getId();
        }

        return $compte->getAvatarUrl();
    }

    /** L'âge au jour de la remise des cadeaux : exact par la date déclarée, sinon par l'année. */
    public function age(User $compte, \DateTimeImmutable $le): ?int
    {
        $identite = $this->identite($compte);

        if (null !== $identite?->naissance) {
            return $identite->naissance->diff($le)->y;
        }

        if (null !== $identite?->anneeNaissance) {
            return max(0, (int) $le->format('Y') - $identite->anneeNaissance);
        }

        return null;
    }
}
