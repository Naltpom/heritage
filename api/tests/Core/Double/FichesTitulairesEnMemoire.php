<?php

declare(strict_types=1);

namespace App\Tests\Core\Double;

use App\Module\Core\Contract\FichesTitulairesInterface;

/**
 * Les fiches tenues par leur titulaire (ADR 0024), **posées à la main** : pour les tests
 * qui ne veulent pas de `mbr_member`.
 */
final class FichesTitulairesEnMemoire implements FichesTitulairesInterface
{
    /** @var array<int, \DateTimeImmutable> */
    private array $fiches = [];

    /** @param array<int, \DateTimeImmutable>|list<int> $fiches les identifiants, avec ou sans date */
    public function __construct(array $fiches = [])
    {
        foreach ($fiches as $cle => $valeur) {
            if ($valeur instanceof \DateTimeImmutable) {
                $this->fiches[$cle] = $valeur;
            } else {
                $this->fiches[$valeur] = new \DateTimeImmutable('2026-11-01T10:00:00+01:00');
            }
        }
    }

    public function fichesTitulaires(): array
    {
        return $this->fiches;
    }
}
