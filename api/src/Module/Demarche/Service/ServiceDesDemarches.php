<?php

declare(strict_types=1);

namespace App\Module\Demarche\Service;

use App\Module\Core\Security\CercleDeVisibiliteInterface;
use App\Module\Demarche\Contract\PortArbrePourDemarches;
use App\Module\Demarche\Entity\Demarche;
use App\Module\Demarche\Entity\Echange;
use App\Module\Demarche\Enum\SensDEchange;
use App\Module\Demarche\Enum\StatutDeDemarche;
use App\Module\Demarche\Exception\ErreurDeDemarche;
use App\Module\Demarche\Repository\DemarcheRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Les démarches : qui les voit, qui les écrit.
 *
 * ## Qui voit
 *
 * **Une démarche se montre à qui voit toutes les personnes qu'elle concerne**, et à
 * personne d'autre. Le texte d'une demande d'acte nomme ces personnes, donne leurs dates,
 * leurs lieux, le nom d'un ex-époux : c'est exactement ce que le cercle protège
 * (ADR 0003). Une personne masquée suffit à masquer la démarche — la même règle que pour
 * un média de famille, dont un seul époux masqué masque l'union.
 *
 * Une démarche masquée et une démarche inexistante rendent la même chose : `404` au
 * détail, absence dans les listes.
 *
 * ## Qui écrit
 *
 * **Tout membre qui la voit peut la faire avancer** : ajouter un message, changer son
 * statut, corriger sa description. C'est le cas d'usage : la réponse d'une mairie arrive
 * chez celui dont l'adresse figurait sur la demande, qui n'est pas forcément celui qui l'a
 * saisie. Seuls les gestes qui effacent sont réservés — supprimer une démarche à son
 * auteur, retirer un message à qui l'a saisi, et aux administrateurs dans les deux cas.
 *
 * Aucune écriture ne touche l'arbre (voir {@see Demarche}).
 */
final readonly class ServiceDesDemarches
{
    public function __construct(
        private EntityManagerInterface $em,
        private DemarcheRepository $demarches,
        private CercleDeVisibiliteInterface $cercle,
        private PortArbrePourDemarches $arbre,
    ) {
    }

    /**
     * @return list<Demarche>
     */
    public function visibles(?int $personId = null, ?bool $enAttente = null): array
    {
        if (null !== $personId && !$this->cercle->voitPersonne($personId)) {
            return [];
        }

        $candidates = null === $personId ? $this->demarches->toutes() : $this->demarches->concernant($personId);

        if (null !== $enAttente) {
            $candidates = array_values(array_filter(
                $candidates,
                static fn (Demarche $d): bool => $d->getStatut()->enAttente() === $enAttente,
            ));
        }

        return $this->filtrer($candidates);
    }

    public function trouver(int $id): Demarche
    {
        $demarche = $this->demarches->find($id);

        if (null === $demarche || [] === $this->filtrer([$demarche])) {
            throw ErreurDeDemarche::introuvable();
        }

        return $demarche;
    }

    public function creer(LecteurDeDemarche $lecteur, DescriptionDeDemarche $description, ?MessageSaisi $premier): Demarche
    {
        $libelles = $this->personnesAutorisees($description->personIds);

        $demarche = new Demarche(
            $description->nature,
            $description->forme,
            $description->intitule,
            $description->destinataire,
            $lecteur->userId,
            $lecteur->libelle,
        );
        $demarche->decrire(
            $description->nature,
            $description->forme,
            $description->intitule,
            $description->commune,
            $description->dateDeLActe,
            $description->destinataire,
        );
        $demarche->concerner($libelles);

        if (null !== $premier) {
            $demarche->ajouterEchange($this->echange($demarche, $premier, $lecteur));
        }

        $this->em->persist($demarche);
        $this->em->flush();

        return $demarche;
    }

    public function decrire(Demarche $demarche, DescriptionDeDemarche $description): Demarche
    {
        // On ne peut pas retirer de la démarche une personne qu'on ne voit pas — le cas
        // ne se présente pas, puisqu'une démarche qui la concerne serait masquée —, ni en
        // ajouter une : le contrôle vaut pour la liste entière.
        $libelles = $this->personnesAutorisees($description->personIds);

        $demarche->decrire(
            $description->nature,
            $description->forme,
            $description->intitule,
            $description->commune,
            $description->dateDeLActe,
            $description->destinataire,
        );
        $demarche->concerner($libelles);
        $this->em->flush();

        return $demarche;
    }

    public function suivre(Demarche $demarche, SuiviDeDemarche $suivi): Demarche
    {
        $demarche->suivre($suivi->statut, $suivi->resultat);
        $this->em->flush();

        return $demarche;
    }

    public function ajouterMessage(Demarche $demarche, MessageSaisi $message, LecteurDeDemarche $lecteur): Demarche
    {
        $demarche->ajouterEchange($this->echange($demarche, $message, $lecteur));

        // Une relance envoyée sur une démarche en attente la fait passer « relancée » :
        // c'est ce que le geste veut dire, et l'oublier laisserait la liste mentir sur le
        // nombre de fois qu'on a écrit. Une réponse reçue ne change rien d'elle-même — seul
        // celui qui l'a lue sait si la pièce est arrivée.
        if (SensDEchange::Envoye === $message->sens
            && StatutDeDemarche::Envoyee === $demarche->getStatut()
            && \count(array_filter($demarche->getEchanges(), static fn (Echange $e): bool => $e->estEnvoye())) > 1) {
            $demarche->suivre(StatutDeDemarche::Relancee, $demarche->getResultat());
        }

        $this->em->flush();

        return $demarche;
    }

    public function retirerMessage(Demarche $demarche, int $echangeId, LecteurDeDemarche $lecteur): Demarche
    {
        foreach ($demarche->getEchanges() as $echange) {
            if ($echange->getId() !== $echangeId) {
                continue;
            }

            if (!$lecteur->administrateur && $echange->getSaisiParUserId() !== $lecteur->userId) {
                throw ErreurDeDemarche::reserveAQuiLASaisi();
            }

            $demarche->retirerEchange($echange);
            $this->em->flush();

            return $demarche;
        }

        throw ErreurDeDemarche::echangeIntrouvable();
    }

    public function supprimer(Demarche $demarche, LecteurDeDemarche $lecteur): void
    {
        if (!$lecteur->administrateur && $demarche->getAuteurUserId() !== $lecteur->userId) {
            throw ErreurDeDemarche::reserveALAuteur();
        }

        $this->em->remove($demarche);
        $this->em->flush();
    }

    public function peutSupprimer(Demarche $demarche, LecteurDeDemarche $lecteur): bool
    {
        return $lecteur->administrateur || $demarche->getAuteurUserId() === $lecteur->userId;
    }

    /**
     * @param list<Demarche> $demarches
     *
     * @return list<Demarche>
     */
    private function filtrer(array $demarches): array
    {
        $toutes = [];
        foreach ($demarches as $demarche) {
            $toutes = [...$toutes, ...$demarche->getPersonIds()];
        }

        if ([] === $toutes) {
            return [];
        }

        // **Un seul appel au cercle**, pour l'union des personnes de toutes les démarches.
        $visibles = array_flip($this->cercle->filtrerPersonnes(array_values(array_unique($toutes))));

        return array_values(array_filter($demarches, static function (Demarche $demarche) use ($visibles): bool {
            $ids = $demarche->getPersonIds();

            // Une démarche qui ne concerne plus personne — ses personnes ont été fusionnées
            // ou supprimées — n'est pas « ouverte à tous » : rien à protéger ne vaut pas
            // autorisé.
            if ([] === $ids) {
                return false;
            }

            foreach ($ids as $id) {
                if (!isset($visibles[$id])) {
                    return false;
                }
            }

            return true;
        }));
    }

    /**
     * Les personnes désignées, avec leur libellé — **toutes visibles du lecteur et toutes
     * existantes**, sinon rien n'est écrit. Refuser en bloc, avec le même message pour
     * « inconnue » et « masquée », n'apprend rien sur qui existe.
     *
     * @param list<int> $personIds
     *
     * @return array<int, string>
     */
    private function personnesAutorisees(array $personIds): array
    {
        $visibles = $this->cercle->filtrerPersonnes($personIds);
        $libelles = $this->arbre->libelles($visibles);

        if (\count($visibles) !== \count($personIds) || \count($libelles) !== \count($personIds)) {
            throw ErreurDeDemarche::donneesInvalides(['personIds' => 'Une des personnes désignées est introuvable.']);
        }

        return $libelles;
    }

    private function echange(Demarche $demarche, MessageSaisi $message, LecteurDeDemarche $lecteur): Echange
    {
        $echange = new Echange(
            $demarche,
            $message->sens,
            $message->canal,
            $message->date,
            $message->texte,
            $lecteur->userId,
            $lecteur->libelle,
        );
        $echange->adresser($message->de, $message->a, $message->objet, $message->reference);

        return $echange;
    }
}
