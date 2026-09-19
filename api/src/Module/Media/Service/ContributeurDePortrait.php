<?php

declare(strict_types=1);

namespace App\Module\Media\Service;

use App\Module\Core\Contract\AnnuaireDesMembresInterface;
use App\Module\Core\Contract\SessionPayloadContributorInterface;
use App\Module\Core\Entity\User;
use App\Module\Media\Repository\MediaRepository;

/**
 * Ajoute à la charge de session (§ 2.2) le **portrait de la personne du lecteur**.
 *
 * Deux écrans en dépendent, et aucun des deux ne doit refaire la question :
 *
 * - la page « Mon compte », qui montre la photo courante et permet de la remplacer ;
 * - le rappel à l'ouverture — « ajoutez votre photo » quand il n'y en a pas, et
 *   « c'est bien vous ? garder ou remplacer » quand quelqu'un d'autre en a déjà déposé
 *   une avant que le titulaire ne rejoigne l'arbre. D'où `deposeParMoi` : c'est lui qui
 *   distingue les deux messages.
 *
 * `null` sans personne validée : un compte en attente n'a pas de fiche à lui, donc pas
 * de portrait, et {@see \App\Module\Core\Service\SessionPayloadBuilder} n'a rien à
 * refermer ici — la clé est fermée à la source.
 */
final readonly class ContributeurDePortrait implements SessionPayloadContributorInterface
{
    public function __construct(
        private AnnuaireDesMembresInterface $annuaire,
        private MediaRepository $medias,
    ) {
    }

    /** @return array<string, mixed> */
    public function contribuer(User $utilisateur): array
    {
        $userId = $utilisateur->getId();
        $personneId = null === $userId ? null : $this->annuaire->personneDuCompte($userId);

        if (null === $personneId) {
            return ['portrait' => null];
        }

        $portrait = $this->medias->portraitActif($personneId);

        if (null === $portrait || null === $portrait->getId()) {
            return ['portrait' => null];
        }

        return [
            'portrait' => [
                'mediaId' => $portrait->getId(),
                'url' => \sprintf('/api/medias/%d/fichier', $portrait->getId()),
                'vignetteUrl' => \sprintf('/api/medias/%d/vignette', $portrait->getId()),
                'deposeParMoi' => $portrait->getUploadedByUserId() === $userId,
            ],
        ];
    }
}
