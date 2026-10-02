<?php

declare(strict_types=1);

namespace App\Module\Evenement\Service;

/**
 * Qui lit un événement : son compte, et la personne de l'arbre à laquelle il est rattaché.
 *
 * `personId` est `null` pour un compte sans rattachement actif. Il peut alors organiser un
 * événement, mais pas y être invité : une invitation désigne **une personne**, et ce compte
 * n'en a pas.
 *
 * `vueEmpruntee` : l'administrateur regarde sous la bascule « voir comme un membre ». Il lit
 * alors comme ce membre, mais **ses lectures ne comptent pas comme des vues** — sans quoi
 * vérifier ce que voit un cousin marquerait « vu » à sa place, et le suivi de l'organisateur
 * mentirait.
 */
final readonly class LecteurDEvenement
{
    public function __construct(
        public int $userId,
        public string $libelle,
        public ?int $personId,
        public bool $administrateur,
        public bool $vueEmpruntee = false,
    ) {
    }
}
