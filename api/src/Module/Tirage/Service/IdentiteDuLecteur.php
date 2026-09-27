<?php

declare(strict_types=1);

namespace App\Module\Tirage\Service;

use App\Module\Core\Contract\AnnuaireDesMembresInterface;
use App\Module\Core\Entity\User;
use App\Module\Core\Exception\NotFoundException;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Le compte connecté, et sa personne — **le seul fichier du module qui touche au composant
 * Security**.
 *
 * ## La bascule « voir comme un membre » n'est PAS rejouée ici, et c'est le secret qui
 * l'exige
 *
 * Partout ailleurs, la bascule sert à vérifier ce que voit un membre. Ici, ce qu'il voit
 * est **à qui il offre un cadeau** : la rejouer donnerait à tout administrateur le moyen
 * de lire l'attribution de n'importe quel participant, en deux clics. On rend donc le
 * compte **réel**, comme `Timeline` le fait pour ses préférences ; les écritures, elles,
 * sont refusées sous la bascule par le contrôleur, comme partout.
 */
final readonly class IdentiteDuLecteur
{
    public function __construct(
        private Security $securite,
        private AnnuaireDesMembresInterface $annuaire,
    ) {
    }

    public function lecteur(): LecteurDuTirage
    {
        $utilisateur = $this->securite->getUser();

        if (!$utilisateur instanceof User || null === $id = $utilisateur->getId()) {
            throw new NotFoundException();
        }

        return new LecteurDuTirage(
            $id,
            $utilisateur->getDisplayName(),
            $this->annuaire->personneDuCompte($id),
            \in_array('ROLE_ADMIN', $utilisateur->getRoles(), true),
        );
    }
}
