<?php

declare(strict_types=1);

namespace App\Module\Messagerie\Service;

use App\Module\Messagerie\Entity\Fil;
use App\Module\Messagerie\Entity\Message;
use App\Module\Messagerie\Enum\NatureDuFil;
use App\Module\Messagerie\Enum\StatutDuFil;
use App\Module\Messagerie\Exception\ErreurDeMessagerie;
use App\Module\Messagerie\Repository\FilRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * La messagerie entre les membres et l'administration : qui lit quoi, qui écrit, et ce
 * que chacun a vu.
 *
 * ## Qui lit
 *
 * **Un fil se montre à son membre et aux administrateurs**, à personne d'autre. Le fil
 * d'un autre et un fil inexistant rendent la même chose : `404`.
 *
 * ## Qui écrit
 *
 * Le membre dans ses fils ; l'administration dans tous. Écrire dans un fil **réglé** le
 * rouvre quand c'est le membre qui écrit : sa suite est une question de plus, pas un
 * remerciement à classer.
 *
 * ## Ce qui a été vu
 *
 * Ouvrir un fil pose l'accusé de lecture sur **les messages de l'autre côté** — ceux de
 * l'administration quand c'est le membre qui ouvre, ceux du membre quand c'est un
 * administrateur ({@see Message::marquerVu()}). Une lecture sous la bascule « voir
 * comme » n'en pose aucun : ce n'est pas le membre qui a lu.
 */
final readonly class ServiceDeLaMessagerie
{
    public function __construct(
        private EntityManagerInterface $em,
        private FilRepository $fils,
        private AvisDeMessage $avis,
    ) {
    }

    /** @return list<Fil> */
    public function filsDuMembre(LecteurDeLaMessagerie $lecteur): array
    {
        return $this->fils->duMembre($lecteur->userId);
    }

    /** @return list<Fil> */
    public function tousLesFils(): array
    {
        return $this->fils->tous();
    }

    /** Un fil du lecteur — `404` pour celui d'un autre, administrateur compris. */
    public function filDuMembre(int $id, LecteurDeLaMessagerie $lecteur): Fil
    {
        $fil = $this->fils->find($id);

        if (null === $fil || $fil->getMembreUserId() !== $lecteur->userId) {
            throw ErreurDeMessagerie::introuvable();
        }

        return $fil;
    }

    public function filPourLAdministration(int $id): Fil
    {
        return $this->fils->find($id) ?? throw ErreurDeMessagerie::introuvable();
    }

    public function ouvrir(LecteurDeLaMessagerie $lecteur, NatureDuFil $nature, string $sujet, string $texte): Fil
    {
        $fil = new Fil($nature, $sujet, $lecteur->userId, $lecteur->libelle);
        $message = new Message($fil, false, $lecteur->userId, $lecteur->libelle, $texte);
        $fil->ajouterMessage($message);

        $this->em->persist($fil);
        $this->em->flush();

        $this->avis->auxAdministrateurs($fil, nouveau: true);

        return $fil;
    }

    /** Le membre écrit dans son fil. */
    public function ecrire(Fil $fil, LecteurDeLaMessagerie $lecteur, string $texte): Fil
    {
        $dejaEnAttente = $this->attendUneLecture($fil, deLAdministration: false);

        $fil->ajouterMessage(new Message($fil, false, $lecteur->userId, $lecteur->libelle, $texte));
        $fil->changerStatut(StatutDuFil::Ouvert);
        // Il répond : il a donc lu ce qui précède, même si sa page n'a pas été rechargée.
        $this->marquerVuParLeMembre($fil, flush: false);
        $this->em->flush();

        // Une seule notification tant que la précédente n'a pas été lue : dix messages
        // d'affilée ne font pas sonner dix fois le téléphone de l'administrateur.
        if (!$dejaEnAttente) {
            $this->avis->auxAdministrateurs($fil, nouveau: false);
        }

        return $fil;
    }

    /** Un administrateur répond, au nom de l'administration. */
    public function repondre(Fil $fil, LecteurDeLaMessagerie $administrateur, string $texte): Fil
    {
        $dejaEnAttente = $this->attendUneLecture($fil, deLAdministration: true);

        $fil->ajouterMessage(new Message($fil, true, $administrateur->userId, $administrateur->libelle, $texte));
        $this->marquerVuParLAdministration($fil, $administrateur, flush: false);
        $this->em->flush();

        if (!$dejaEnAttente) {
            $this->avis->auMembre($fil);
        }

        return $fil;
    }

    public function changerStatut(Fil $fil, StatutDuFil $statut): Fil
    {
        $fil->changerStatut($statut);
        $this->em->flush();

        return $fil;
    }

    /** Le membre a ouvert son fil : les messages de l'administration sont vus. */
    public function marquerVuParLeMembre(Fil $fil, bool $flush = true): void
    {
        $maintenant = new \DateTimeImmutable();
        $change = false;

        foreach ($fil->getMessages() as $message) {
            if ($message->estDeLAdministration()) {
                $change = $message->marquerVu($maintenant) || $change;
            }
        }

        if ($change && $flush) {
            $this->em->flush();
        }
    }

    /**
     * Un administrateur a ouvert le fil : les messages du membre sont vus, et l'on dit par
     * qui. Un administrateur qui lit **son propre** fil ne s'accuse pas réception.
     */
    public function marquerVuParLAdministration(Fil $fil, LecteurDeLaMessagerie $administrateur, bool $flush = true): void
    {
        if ($fil->getMembreUserId() === $administrateur->userId) {
            return;
        }

        $maintenant = new \DateTimeImmutable();
        $change = false;

        foreach ($fil->getMessages() as $message) {
            if (!$message->estDeLAdministration()) {
                $change = $message->marquerVu($maintenant, $administrateur->libelle) || $change;
            }
        }

        if ($change && $flush) {
            $this->em->flush();
        }
    }

    /** Les messages d'un côté que l'autre n'a pas encore vus. */
    public function nonLus(Fil $fil, bool $deLAdministration): int
    {
        return \count(array_filter(
            $fil->getMessages(),
            static fn (Message $m): bool => $m->estDeLAdministration() === $deLAdministration && !$m->estVu(),
        ));
    }

    private function attendUneLecture(Fil $fil, bool $deLAdministration): bool
    {
        return $this->nonLus($fil, $deLAdministration) > 0;
    }
}
