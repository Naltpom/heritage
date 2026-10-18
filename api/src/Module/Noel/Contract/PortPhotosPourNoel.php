<?php

declare(strict_types=1);

namespace App\Module\Noel\Contract;

/**
 * Ce que `Noel` demande à `Media` : la photo d'un compte — le portrait de sa fiche, sinon
 * la photo déposée à son inscription.
 *
 * `Media` l'implémente (`Media\Service\PhotosPourNoel`). Le site de Noël n'affiche les
 * photos qu'entre participants d'un même tirage ; c'est `Noel` qui le vérifie.
 */
interface PortPhotosPourNoel
{
    /**
     * Les comptes qui ont une photo, parmi ceux demandés.
     *
     * @param array<int, int|null> $comptes identifiant de compte ⇒ fiche rattachée
     *
     * @return list<int>
     */
    public function avecPhoto(array $comptes): array;

    /** La vignette du compte, ou `null`. */
    public function vignette(int $userId, ?int $personId): ?PhotoDuCompte;
}
