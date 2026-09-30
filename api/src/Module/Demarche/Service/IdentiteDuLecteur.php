<?php

declare(strict_types=1);

namespace App\Module\Demarche\Service;

use App\Module\Core\Entity\User;
use App\Module\Core\Exception\NotFoundException;
use App\Module\Core\Repository\UserRepository;
use App\Module\Core\Service\BasculeVoirComme;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Qui lit, sous la bascule « voir comme un membre » comprise.
 *
 * Sous la bascule, l'administrateur lit **comme** le membre emprunté — il ne se voit pas
 * reconnaître de droit d'administration — et toute écriture est refusée en amont, dans le
 * contrôleur. Le filtre du cercle, lui, suit déjà la bascule de son côté.
 */
final readonly class IdentiteDuLecteur
{
    public function __construct(
        private Security $securite,
        private BasculeVoirComme $bascule,
        private UserRepository $utilisateurs,
    ) {
    }

    public function lecteur(): LecteurDeDemarche
    {
        $emprunte = $this->bascule->userIdEmprunte();

        if (null !== $emprunte) {
            $membre = $this->utilisateurs->find($emprunte) ?? throw new NotFoundException();

            return new LecteurDeDemarche($emprunte, $membre->getDisplayName(), false);
        }

        $utilisateur = $this->securite->getUser();

        if (!$utilisateur instanceof User || null === $id = $utilisateur->getId()) {
            throw new NotFoundException();
        }

        return new LecteurDeDemarche(
            $id,
            $utilisateur->getDisplayName(),
            \in_array('ROLE_ADMIN', $utilisateur->getRoles(), true),
        );
    }
}
