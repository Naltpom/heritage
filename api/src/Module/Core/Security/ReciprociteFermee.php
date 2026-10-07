<?php

declare(strict_types=1);

namespace App\Module\Core\Security;

use App\Module\Core\ValueObject\ComposantesDeDate;
use App\Module\Core\ValueObject\PartageDeNaissance;

/**
 * L'implémentation **par défaut** de {@see ReciprociteInterface} : aucune composante de
 * naissance, aucun portrait net.
 *
 * Même raisonnement que {@see CercleFerme}, dont c'est le pendant : une erreur de câblage
 * doit produire des dates absentes et des photos floues — visibles tout de suite —,
 * jamais des données de vivants servies sans consentement. Elle s'applique aussi aux
 * défunts, faute de savoir les distinguer : la panne est grossière, et c'est voulu.
 */
final class ReciprociteFermee implements ReciprociteInterface
{
    public function naissance(int $personId): ComposantesDeDate
    {
        return ComposantesDeDate::aucune();
    }

    public function naissances(array $personIds): array
    {
        return array_fill_keys($personIds, ComposantesDeDate::aucune());
    }

    public function portraitNet(int $personId): bool
    {
        return false;
    }

    public function partageDuLecteur(): ?PartageDeNaissance
    {
        return null;
    }

    public function restrictionIgnoree(): bool
    {
        return false;
    }

    public function lecteurAUnePhoto(): bool
    {
        return false;
    }

    public function empreinte(): string
    {
        return 'fermee';
    }
}
