<?php

declare(strict_types=1);

namespace App\Module\Evenement\Service;

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
 * Une copie de `Tirage\Service\IdentiteDuLecteur`, et non un emprunt : ce service est
 * interne à `Tirage`, et l'ADR 0002 n'autorise un `use` inter-modules que vers `Core\…` ou un
 * `Contract\`. Les deux copies divergent d'ailleurs déjà d'une ligne — le drapeau
 * `vueEmpruntee`, dont seul ce module a besoin.
 *
 * ## Sous la bascule « voir comme un membre », c'est le membre qui lit
 *
 * L'administrateur voit alors les événements **exactement comme ce membre** : ceux où il est
 * invité, sa réponse, ce qu'il voit de qui vient. Mais il ne **marque** rien : ni vue, ni
 * réponse — le contrôleur refuse les écritures sous la bascule, et le service ne pose pas
 * la vue d'un lecteur emprunté ({@see LecteurDEvenement::$vueEmpruntee}).
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

    public function lecteur(): LecteurDEvenement
    {
        $emprunte = $this->bascule->userIdEmprunte();

        if (null !== $emprunte) {
            // Un compte emprunté introuvable : on ne retombe pas sur l'administrateur, qui
            // croirait regarder le membre en regardant ses propres événements.
            $membre = $this->utilisateurs->find($emprunte) ?? throw new NotFoundException();

            return new LecteurDEvenement(
                $emprunte,
                $membre->getDisplayName(),
                $this->annuaire->personneDuCompte($emprunte),
                false,
                true,
            );
        }

        return $this->compteConnecte() ?? throw new NotFoundException();
    }

    /**
     * Le compte **réellement** connecté, s'il y en a un — sans bascule, sans refus.
     *
     * Sert la route publique du lien personnel, qui ne demande aucune session mais veut
     * savoir si celui qui l'ouvre est l'organisateur venu vérifier son lien : sa visite ne
     * doit pas compter comme celle de l'invité. La réponse, elle, reste la même.
     */
    public function compteConnecte(): ?LecteurDEvenement
    {
        $utilisateur = $this->securite->getUser();

        if (!$utilisateur instanceof User || null === $id = $utilisateur->getId()) {
            return null;
        }

        return new LecteurDEvenement(
            $id,
            $utilisateur->getDisplayName(),
            $this->annuaire->personneDuCompte($id),
            \in_array('ROLE_ADMIN', $utilisateur->getRoles(), true),
        );
    }
}
