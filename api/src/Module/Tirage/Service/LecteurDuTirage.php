<?php

declare(strict_types=1);

namespace App\Module\Tirage\Service;

/**
 * Qui lit un tirage : son compte, et la personne de l'arbre à laquelle il est rattaché.
 *
 * `personId` est `null` pour un compte sans rattachement actif. Il peut alors organiser un
 * tirage, mais ni y participer ni lire une attribution : une attribution se lit **par la
 * personne**, et ce compte n'en a pas.
 */
final readonly class LecteurDuTirage
{
    public function __construct(
        public int $userId,
        public string $libelle,
        public ?int $personId,
        public bool $administrateur,
    ) {
    }
}
