<?php

declare(strict_types=1);

namespace App\Module\Media\Security;

use App\Module\Core\Entity\User;
use App\Module\Core\Enum\PorteeDeJeton;
use App\Module\Core\Security\JetonDApiAuthentifie;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

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
 * son libellé d'affichage, et son rôle — que la portée d'un jeton d'API peut réduire pour
 * un dépôt ({@see self::deposant()}).
 */
final readonly class FabriqueDeLecteur
{
    public function __construct(private TokenStorageInterface $jetons)
    {
    }

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

    /**
     * **Qui téléverse** : le lecteur du compte — sauf sous un jeton d'API qui ne décide pas,
     * où l'administrateur dépose comme un membre (ADR 0021).
     *
     * ## Le dépôt d'un administrateur est une décision
     *
     * Il naît `actif`, remplace sur-le-champ le portrait que toute la famille voit, et
     * échappe au plafond de 20 dépôts par heure ({@see \App\Module\Media\Controller\MediaController::limiter()}).
     * Or la portée `contribution` — celle du jeton des agents — dépose **sans décider**, comme
     * elle dépose une demande sans la valider. Sous un jeton de portée inférieure à
     * `validation`, le déposant perd donc `administrateur`, et avec lui les trois : le média
     * naît `en_attente`, le portrait ne bascule qu'à la validation de la demande
     * `ajout_media` qui le désigne (la session principale la tranche), le quota des membres
     * est compté, et une pièce ne se joint qu'à ses propres propositions
     * (`FichesProposeesInterface::depot()`). Par cookie, ou sous un jeton `validation`, rien
     * ne change.
     *
     * ## La portée, jamais le libellé
     *
     * Elle se lit sur le jeton de sécurité, par {@see JetonDApiAuthentifie} — comme la liste
     * blanche de `PorteeDuJetonSubscriber` et la règle de l'auteur de `DemandeVoter`. Le
     * libellé d'un jeton n'est qu'un nom : renommer « Claude — agents » ne doit rien ouvrir.
     * Et pas d'`isGranted('ROLE_ADMIN')` relu à côté : le rôle reste celui de
     * {@see self::depuisUtilisateur()}, que la portée ne peut que retirer.
     */
    public function deposant(User $utilisateur): LecteurMedia
    {
        $lecteur = $this->depuisUtilisateur($utilisateur);
        $jeton = JetonDApiAuthentifie::depuisLeJetonDeSecurite($this->jetons->getToken());

        if (!$lecteur->administrateur || null === $jeton || $jeton->portee->couvre(PorteeDeJeton::Validation)) {
            return $lecteur;
        }

        return new LecteurMedia($lecteur->userId, $lecteur->libelle, administrateur: false);
    }
}
