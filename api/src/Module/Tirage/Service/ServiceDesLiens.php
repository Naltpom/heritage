<?php

declare(strict_types=1);

namespace App\Module\Tirage\Service;

use App\Module\Core\Contract\AnnuaireDesMembresInterface;
use App\Module\Core\Service\GenerateurDeCle;
use App\Module\Tirage\Entity\Participant;
use App\Module\Tirage\Entity\Tirage;
use App\Module\Tirage\Enum\Presence;
use App\Module\Tirage\Enum\StatutDEdition;
use App\Module\Tirage\Exception\ErreurDeTirage;
use App\Module\Tirage\Repository\TirageRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Le **lien personnel** d'un participant : `/t/<clé>`, pour qu'une personne sans compte
 * suive son tirage — sa présence, puis à qui elle offre.
 *
 * ## Qui le crée — et pourquoi pas l'organisateur pour tout le monde
 *
 * La clé en clair passe par les mains de celui qui la crée : **créer un lien, c'est pouvoir
 * lire l'attribution du participant**. Or le secret d'un participant tient contre
 * l'organisateur (ADR 0009, § « Le secret ») ; si l'organisateur pouvait créer le lien de
 * n'importe qui, il lui suffirait d'en créer un par participant, après le tirage, pour
 * reconstituer toutes les paires — sans qu'un participant qui a son compte ne remarque rien.
 *
 * D'où la règle, qui ne donne la clé qu'à qui **connaît déjà**, ou connaîtra forcément, ce
 * qu'elle ouvre :
 *
 * - **le référent** du participant, toujours : `mesAttributions` lui montre déjà
 *   l'attribution de qui il accompagne, le lien ne lui apprend rien ;
 * - **l'organisateur** (ou un administrateur), seulement pour un participant **sans
 *   compte et sans référent** : personne d'autre ne peut lui transmettre son tirage. C'est
 *   la seule brèche du secret contre l'organisateur, et elle est la condition du service —
 *   l'ADR le dit en clair ;
 * - personne pour un participant qui a son compte et pas de référent : il suit son tirage
 *   par son compte, et son secret reste entier.
 *
 * Pas le participant lui-même : s'il a un compte, il n'en a pas besoin, et s'il n'en a pas,
 * il ne peut pas le demander.
 *
 * La règle se relit **à chaque ouverture du lien** ({@see self::participantDuLien()}) : un
 * lien créé par l'organisateur pour une grand-mère sans compte cesse de valoir le jour où
 * elle en ouvre un — sans quoi l'organisateur, qui a eu la clé, continuerait de lire un
 * secret qui ne le regarde plus.
 *
 * ## Ce que le lien ouvre, et rien de plus
 *
 * Le lien vaut **la personne dans ce tirage**, jamais un compte : il ne lit pas l'arbre, ne
 * liste pas les autres participants, n'ouvre aucune autre route. Ce qu'il montre est écrit
 * dans {@see SerialiseurDeTirage::lien()}, à côté du reste du secret — le seul endroit où
 * un receveur est nommé.
 *
 * ## Ce qu'il permet d'écrire
 *
 * La présence de **son** participant à l'édition courante, tant qu'elle est ouverte et à
 * venir. Après le tirage, sortir laisserait quelqu'un sans cadeau, et même passer de « sur
 * place » à « à distance » change l'organisation de celui qui offre à cette personne : ces
 * gestes-là passent par un compte (organisateur ou référent), qui en répond.
 */
final readonly class ServiceDesLiens
{
    public function __construct(
        private EntityManagerInterface $em,
        private TirageRepository $tirages,
        private ServiceDesTirages $service,
        private GenerateurDeCle $generateur,
        private AnnuaireDesMembresInterface $annuaire,
    ) {
    }

    /**
     * Le lecteur peut créer, régénérer ou révoquer le lien de ce participant — la règle du
     * docbloc de la classe.
     *
     * Révoquer suit la même règle que créer, par simplicité : retirer le participant tue
     * aussi son lien, et l'organisateur garde ce geste-là.
     */
    public function peutPartager(Tirage $tirage, LecteurDuTirage $lecteur, Participant $participant): bool
    {
        if (null !== $lecteur->personId && $participant->getReferentPersonId() === $lecteur->personId) {
            return true;
        }

        return $this->service->peutGerer($tirage, $lecteur)
            && null === $participant->getReferentPersonId()
            && !$this->aUnCompte($participant);
    }

    /**
     * Crée le lien, ou le **régénère** : la clé précédente cesse de valoir à l'instant.
     *
     * @return string la clé en clair — rendue **une seule fois**, jamais relisible
     */
    public function creer(Tirage $tirage, LecteurDuTirage $lecteur, int $personId): string
    {
        $participant = $this->participantPartageable($tirage, $lecteur, $personId);
        $cle = $this->generateur->cle();

        $participant->poserLien($this->generateur->empreinte($cle), new \DateTimeImmutable());
        $this->em->flush();

        return $cle;
    }

    /** Idempotent : révoquer un lien déjà révoqué n'est pas une erreur. */
    public function revoquer(Tirage $tirage, LecteurDuTirage $lecteur, int $personId): void
    {
        $this->participantPartageable($tirage, $lecteur, $personId)->revoquerLien();
        $this->em->flush();
    }

    /**
     * Le participant que cette clé désigne. Clé mal formée, inconnue, révoquée, remplacée,
     * participant retiré, tirage supprimé : **une seule** erreur, {@see ErreurDeTirage::lienInvalide()}.
     */
    public function participantDuLien(string $cle): Participant
    {
        if (!$this->generateur->formeValide($cle)) {
            throw ErreurDeTirage::lienInvalide();
        }

        $participant = $this->tirages->participantParEmpreinte($this->generateur->empreinte($cle))
            ?? throw ErreurDeTirage::lienInvalide();

        // Plus personne ne pourrait créer ce lien : il ne vaut plus. C'est le cas d'un
        // participant qui a ouvert un compte depuis, sans référent — le lien venait de
        // l'organisateur, qui garde la clé. Même refus que les autres : rien ne dit pourquoi.
        if (null === $participant->getReferentPersonId() && $this->aUnCompte($participant)) {
            throw ErreurDeTirage::lienInvalide();
        }

        return $participant;
    }

    public function peutChangerPresence(Participant $participant, \DateTimeImmutable $aujourdhui): bool
    {
        $edition = $this->service->editionCourante($participant->getTirage(), $aujourdhui);

        return null !== $edition
            && StatutDEdition::Ouverte === $edition->getStatut()
            && $edition->getDate() >= $aujourdhui->setTime(0, 0);
    }

    public function definirPresence(Participant $participant, Presence $presence, \DateTimeImmutable $aujourdhui): void
    {
        $edition = $this->service->editionCourante($participant->getTirage(), $aujourdhui);

        if (null === $edition || !$this->peutChangerPresence($participant, $aujourdhui)) {
            throw ErreurDeTirage::presenceFigee();
        }

        $edition->definirPresence($participant->getPersonId(), $presence);
        $this->em->flush();
    }

    /**
     * La personne du participant est rattachée à un compte. Un rattachement en attente
     * compte aussi : il sera validé, et l'organisateur aurait eu la clé entre-temps. Une
     * invitation non acceptée, elle, ne compte pas — la personne n'a encore rien pour suivre
     * son tirage, et le lien cessera de valoir à son acceptation.
     */
    private function aUnCompte(Participant $participant): bool
    {
        return null !== $this->annuaire->rattachementsDeLaPersonne($participant->getPersonId())['membreId'];
    }

    private function participantPartageable(Tirage $tirage, LecteurDuTirage $lecteur, int $personId): Participant
    {
        $participant = $tirage->participant($personId) ?? throw ErreurDeTirage::introuvable();

        if (!$this->peutPartager($tirage, $lecteur, $participant)) {
            throw ErreurDeTirage::nonAutorisePourLeLien();
        }

        return $participant;
    }
}
