<?php

declare(strict_types=1);

namespace App\Tests\Core\Double;

use App\Module\Core\Security\ReciprociteInterface;
use App\Module\Core\ValueObject\ComposantesDeDate;
use App\Module\Core\ValueObject\PartageDeNaissance;

/**
 * Une réciprocité des vivants (ADR 0016) **pré-résolue**, pour les tests qui ne veulent pas
 * de session : ce que le lecteur voit de chaque personne, et s'il a une photo de lui.
 *
 * Une personne absente de `$partages` est traitée comme un défunt — tout est visible —,
 * ce qui garde intacts les tests écrits avant la réciprocité.
 */
final class ReciprociteEnMemoire implements ReciprociteInterface
{
    /**
     * @param array<int, ComposantesDeDate> $partages
     * @param list<int>                     $portraitsFlous
     */
    public function __construct(
        public array $partages = [],
        public bool $photo = true,
        public array $portraitsFlous = [],
        public bool $ignoree = false,
        public ?PartageDeNaissance $partageDuLecteur = null,
    ) {
    }

    public function naissance(int $personId): ComposantesDeDate
    {
        return $this->ignoree ? ComposantesDeDate::tout() : ($this->partages[$personId] ?? ComposantesDeDate::tout());
    }

    public function naissances(array $personIds): array
    {
        $resultat = [];
        foreach ($personIds as $id) {
            $resultat[$id] = $this->naissance($id);
        }

        return $resultat;
    }

    public function portraitNet(int $personId): bool
    {
        return $this->photo || !\in_array($personId, $this->portraitsFlous, true);
    }

    public function partageDuLecteur(): ?PartageDeNaissance
    {
        return $this->partageDuLecteur;
    }

    public function restrictionIgnoree(): bool
    {
        return $this->ignoree;
    }

    public function lecteurAUnePhoto(): bool
    {
        return $this->photo;
    }

    public function empreinte(): string
    {
        $lignes = [];
        foreach ($this->partages as $id => $partage) {
            $lignes[] = $id.':'.$partage->masque();
        }
        sort($lignes);

        return hash('sha256', implode(',', $lignes).'|'.($this->photo ? 'p' : '-').($this->ignoree ? 'i' : '-'));
    }
}
