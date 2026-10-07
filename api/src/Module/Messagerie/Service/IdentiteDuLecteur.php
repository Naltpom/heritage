<?php

declare(strict_types=1);

namespace App\Module\Messagerie\Service;

use App\Module\Core\Entity\User;
use App\Module\Core\Exception\NotFoundException;
use App\Module\Core\Repository\UserRepository;
use App\Module\Core\Service\BasculeVoirComme;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Qui lit, sous la bascule « voir comme un membre » comprise — même règle que les actes
 * demandés : sous la bascule, l'administrateur lit **comme** le membre emprunté, sans droit
 * d'administration, et toute écriture est refusée en amont, dans le contrôleur.
 */
final readonly class IdentiteDuLecteur
{
    public function __construct(
        private Security $securite,
        private BasculeVoirComme $bascule,
        private UserRepository $utilisateurs,
    ) {
    }

    public function lecteur(): LecteurDeLaMessagerie
    {
        $emprunte = $this->bascule->userIdEmprunte();

        if (null !== $emprunte) {
            $membre = $this->utilisateurs->find($emprunte) ?? throw new NotFoundException();

            return new LecteurDeLaMessagerie($emprunte, $this->libelleDe($membre), false, true);
        }

        $utilisateur = $this->securite->getUser();

        if (!$utilisateur instanceof User || null === $id = $utilisateur->getId()) {
            throw new NotFoundException();
        }

        return new LecteurDeLaMessagerie(
            $id,
            $this->libelleDe($utilisateur),
            \in_array('ROLE_ADMIN', $utilisateur->getRoles(), true),
        );
    }

    /** Le nom affiché, ou le courriel d'un compte qui n'en a pas encore (en attente). */
    private function libelleDe(User $utilisateur): string
    {
        $nom = trim($utilisateur->getDisplayName());

        return '' === $nom ? $utilisateur->getEmail() : $nom;
    }
}
