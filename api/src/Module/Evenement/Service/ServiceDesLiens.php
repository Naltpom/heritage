<?php

declare(strict_types=1);

namespace App\Module\Evenement\Service;

use App\Module\Core\Contract\AnnuaireDesMembresInterface;
use App\Module\Core\Service\GenerateurDeCle;
use App\Module\Evenement\Entity\Evenement;
use App\Module\Evenement\Entity\Invitation;
use App\Module\Evenement\Enum\Reponse;
use App\Module\Evenement\Exception\ErreurDEvenement;
use App\Module\Evenement\Repository\EvenementRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Le **lien personnel** d'un invité : `/e/<clé>`, pour qu'une personne sans compte voie
 * l'annonce et réponde — présente ou absente.
 *
 * ## Qui le crée : l'organisateur, pour un invité sans compte
 *
 * C'est la règle des liens de tirage (`Tirage\Service\ServiceDesLiens`) dans le cas où le
 * participant n'a ni compte ni référent — un événement n'a pas de référent : la grand-mère
 * sans compte reçoit son lien de l'organisateur, par SMS ou de vive voix.
 *
 * Il n'y a **pas de secret** à protéger ici comme dans un tirage — l'organisateur sait tout
 * de son événement. La raison de refuser le lien d'une personne qui a son compte est autre :
 * tenir sa clé, ce serait pouvoir **répondre à sa place** et **ouvrir l'annonce à sa place**,
 * c'est-à-dire fausser exactement ce que le suivi mesure. Avec un compte, la notification
 * suffit. Et la règle se relit **à chaque ouverture** ({@see self::invitationDuLien()}) : le
 * lien d'une personne qui ouvre son compte cesse de valoir.
 *
 * ## Une clé qu'on ne relit pas
 *
 * Seule l'empreinte est stockée ({@see GenerateurDeCle}) : le lien s'affiche **une fois**, à
 * sa création. « Le renvoyer » après coup, c'est en régénérer un — l'ancien cesse de valoir.
 * Le prix, pour un organisateur qui relance : il renvoie un lien neuf. Le gain : une base
 * volée n'ouvre aucun lien, et c'est la même règle partout dans l'application.
 *
 * ## Ce que le lien ouvre, écrit et marque
 *
 * Il montre **cet événement et cette invitation** ({@see SerialiseurDEvenement::lien()}), il
 * écrit **sa réponse** tant que l'événement tient et n'est pas terminé, et son ouverture
 * **marque la vue** — sauf quand c'est l'organisateur, connecté dans le même navigateur, qui
 * vient vérifier le lien qu'il s'apprête à envoyer : sa visite n'est pas celle de l'invité.
 */
final readonly class ServiceDesLiens
{
    public function __construct(
        private EntityManagerInterface $em,
        private EvenementRepository $evenements,
        private ServiceDesEvenements $service,
        private GenerateurDeCle $generateur,
        private AnnuaireDesMembresInterface $annuaire,
    ) {
    }

    /**
     * Crée le lien, ou le **régénère** : la clé précédente cesse de valoir à l'instant.
     *
     * @return string la clé en clair — rendue **une seule fois**, jamais relisible
     */
    public function creer(Evenement $evenement, LecteurDEvenement $lecteur, int $personId, \DateTimeImmutable $maintenant): string
    {
        $invitation = $this->invitationGeree($evenement, $lecteur, $personId);

        if ($evenement->estAnnule()) {
            throw ErreurDEvenement::annule();
        }

        if ($evenement->estPasse($maintenant)) {
            throw ErreurDEvenement::passe();
        }

        if ($this->aUnCompte($invitation)) {
            throw ErreurDEvenement::lienReserveAuxSansCompte();
        }

        $cle = $this->generateur->cle();
        $invitation->poserLien($this->generateur->empreinte($cle), $maintenant);
        $this->em->flush();

        return $cle;
    }

    /** Idempotent : révoquer un lien déjà révoqué n'est pas une erreur. Permis à tout moment. */
    public function revoquer(Evenement $evenement, LecteurDEvenement $lecteur, int $personId): void
    {
        $this->invitationGeree($evenement, $lecteur, $personId)->revoquerLien();
        $this->em->flush();
    }

    /**
     * L'invitation que cette clé désigne. Clé mal formée, inconnue, révoquée, remplacée,
     * invité retiré, ou invité qui a ouvert son compte depuis : **une seule** erreur,
     * {@see ErreurDEvenement::lienInvalide()}.
     */
    public function invitationDuLien(string $cle): Invitation
    {
        if (!$this->generateur->formeValide($cle)) {
            throw ErreurDEvenement::lienInvalide();
        }

        $invitation = $this->evenements->invitationParEmpreinte($this->generateur->empreinte($cle))
            ?? throw ErreurDEvenement::lienInvalide();

        if ($this->aUnCompte($invitation)) {
            throw ErreurDEvenement::lienInvalide();
        }

        return $invitation;
    }

    /**
     * L'invité ouvre son lien : la vue se pose — sauf si le compte connecté dans ce
     * navigateur gère l'événement (l'organisateur qui vérifie son lien avant de l'envoyer).
     */
    public function ouvrir(Invitation $invitation, ?LecteurDEvenement $compteConnecte, \DateTimeImmutable $maintenant): void
    {
        if (null !== $compteConnecte && $this->service->peutGerer($invitation->getEvenement(), $compteConnecte)) {
            return;
        }

        if ($invitation->marquerVue($maintenant)) {
            $this->em->flush();
        }
    }

    public function repondre(Invitation $invitation, Reponse $reponse, \DateTimeImmutable $maintenant): void
    {
        $this->service->enregistrerLaReponse($invitation, $reponse, $maintenant);
    }

    /**
     * Un rattachement en attente compte aussi : il sera validé, et l'organisateur aurait eu
     * la clé entre-temps. Une invitation à s'inscrire non acceptée, elle, ne compte pas.
     */
    private function aUnCompte(Invitation $invitation): bool
    {
        return null !== $this->annuaire->rattachementsDeLaPersonne($invitation->getPersonId())['membreId'];
    }

    private function invitationGeree(Evenement $evenement, LecteurDEvenement $lecteur, int $personId): Invitation
    {
        if (!$this->service->peutGerer($evenement, $lecteur)) {
            throw ErreurDEvenement::reserveALOrganisateur();
        }

        return $evenement->invitation($personId) ?? throw ErreurDEvenement::introuvable();
    }
}
