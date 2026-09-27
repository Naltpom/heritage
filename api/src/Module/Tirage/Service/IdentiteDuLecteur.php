<?php

declare(strict_types=1);

namespace App\Module\Tirage\Service;

use App\Module\Core\Contract\AnnuaireDesMembresInterface;
use App\Module\Core\Entity\User;
use App\Module\Core\Exception\NotFoundException;
use App\Module\Core\Repository\UserRepository;
use App\Module\Core\Service\BasculeVoirComme;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Le compte qui lit, et sa personne — **le seul fichier du module qui touche au composant
 * Security**.
 *
 * ## Sous la bascule « voir comme un membre », c'est le membre qui lit
 *
 * L'administrateur voit alors les tirages **exactement comme ce membre** : les siens, et son
 * attribution — celle des participants qu'il accompagne comprise. C'est un arbitrage du
 * commanditaire (ADR 0009, révision du 2026-09-27) : la bascule sert à vérifier ce que voit
 * un membre, et un écran de tirage qui montrerait autre chose ne vérifierait rien.
 *
 * Ce que cela coûte, dit franchement : **un administrateur peut lire le secret d'un
 * participant.** Ce qui le borne :
 *
 * - l'activation de la bascule est tracée au journal d'audit (`vue_comme_membre`) ;
 * - le lecteur emprunté n'est **jamais** administrateur : il ne voit ni plus ni moins que le
 *   membre, et ne gère un tirage que si le membre l'organise ;
 * - aucune écriture ne passe sous la bascule — le contrôleur la refuse avant de venir ici.
 */
final readonly class IdentiteDuLecteur
{
    public function __construct(
        private Security $securite,
        private AnnuaireDesMembresInterface $annuaire,
        private BasculeVoirComme $bascule,
        private UserRepository $utilisateurs,
    ) {
    }

    public function lecteur(): LecteurDuTirage
    {
        $emprunte = $this->bascule->userIdEmprunte();

        if (null !== $emprunte) {
            // Un compte emprunté introuvable : on ne retombe pas sur l'administrateur, qui
            // croirait regarder le membre en regardant ses propres tirages.
            $membre = $this->utilisateurs->find($emprunte) ?? throw new NotFoundException();

            return new LecteurDuTirage(
                $emprunte,
                $membre->getDisplayName(),
                $this->annuaire->personneDuCompte($emprunte),
                false,
            );
        }

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
