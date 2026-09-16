<?php

declare(strict_types=1);

namespace App\Module\Media\Security;

use App\Module\Core\Entity\User;

/**
 * Traduit le compte authentifié en {@see LecteurMedia}.
 *
 * **Elle ne calcule pas de régime.** Elle l'a fait un temps, à partir de
 * `core_user.status`, et c'était une seconde correspondance statut → régime à côté de
 * `Core\Security\RegimeVoter::regimeDe()`. Deux correspondances, même triviales,
 * finissent par ne plus dire la même chose — et celle-ci décidait de ce qu'un compte en
 * attente de validation a le droit de lire. Le régime se lit désormais sur
 * {@see VisibiliteDesSujetsInterface::regimeDuLecteur()}, qui le tient du noyau.
 *
 * Ne reste donc ici que ce qui est vrai du **compte** et non du cercle : son identifiant,
 * son libellé d'affichage, et son rôle.
 */
final class FabriqueDeLecteur
{
    public function depuisUtilisateur(User $utilisateur): LecteurMedia
    {
        $id = $utilisateur->getId();

        if (null === $id) {
            throw new \LogicException('Un compte non persisté ne peut pas lire de média.');
        }

        $libelle = '' !== $utilisateur->getDisplayName()
            ? $utilisateur->getDisplayName()
            : $utilisateur->getEmail();

        return new LecteurMedia(
            userId: $id,
            // 120 : la largeur de `med_media.uploaded_by_label`. On tronque ici plutôt
            // que de laisser la base refuser l'insertion au dernier moment.
            libelle: mb_substr($libelle, 0, 120),
            administrateur: \in_array('ROLE_ADMIN', $utilisateur->getRoles(), true),
        );
    }
}
