<?php

declare(strict_types=1);

namespace App\Module\Noel\Service;

use App\Module\Core\Entity\User;
use App\Module\Core\Enum\NotificationKind;
use App\Module\Core\Exception\ConflictException;
use App\Module\Core\Exception\ForbiddenException;
use App\Module\Core\Exception\NotFoundException;
use App\Module\Core\Exception\ValidationException;
use App\Module\Core\Service\NotificationService;
use App\Module\Noel\Entity\Participation;
use App\Module\Noel\Entity\TirageNoel;
use App\Module\Noel\Enum\Revelation;
use App\Module\Noel\Repository\TirageNoelRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Random\Randomizer;

/**
 * Les règles d'un tirage de Noël.
 *
 * - **N'importe quel compte connecté crée un tirage** ; il en devient l'organisateur, sans
 *   y participer d'office.
 * - **Tant que le tirage est ouvert, tout le monde change d'avis** : rejoindre, partir,
 *   changer sa présence, son vote, son conjoint.
 * - **Une fois tiré, plus rien ne bouge** — ni réponse, ni réglage — et **rien ne
 *   l'annule** : il n'existe aucune méthode pour le faire.
 * - L'organisateur change les dates et le budget, ou tire en avance. Sinon le tirage se
 *   fait tout seul à sa date ({@see self::tirerLesEcheances()}).
 * - Le vote de révélation est dépouillé au tirage : majorité simple, égalité ou aucun vote
 *   ⇒ la magie de Noël.
 */
final class ServiceDesTiragesNoel
{
    private const string ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    private const int LONGUEUR_DU_CODE = 8;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TirageNoelRepository $tirages,
        private readonly TirageAuSortNoel $tirage,
        private readonly NotificationService $notifications,
        private readonly LoggerInterface $journal,
        private readonly Randomizer $hasard = new Randomizer(),
    ) {
    }

    public function parCode(string $code): TirageNoel
    {
        $code = strtoupper(trim($code));

        return 1 === preg_match('/^[A-Z0-9]{4,12}$/', $code) ? ($this->tirages->parCode($code) ?? throw new NotFoundException('Ce tirage n’existe pas.')) : throw new NotFoundException('Ce tirage n’existe pas.');
    }

    /** @return list<TirageNoel> */
    public function duCompte(User $compte): array
    {
        return $this->tirages->duCompte($compte);
    }

    public function creer(User $createur, ReglagesDuTirageNoel $reglages, \DateTimeImmutable $maintenant = new \DateTimeImmutable()): TirageNoel
    {
        $champs = [];
        if (null === $reglages->titre) {
            $champs['titre'] = 'Le titre est requis.';
        }
        if (null === $reglages->dateTirage) {
            $champs['dateTirage'] = 'La date du tirage est requise.';
        }
        if (null === $reglages->dateEvenement) {
            $champs['dateEvenement'] = 'La date de remise des cadeaux est requise.';
        }
        if (null === $reglages->budget) {
            $champs['budget'] = 'Le budget est requis.';
        }
        if ([] !== $champs) {
            throw new ValidationException($champs);
        }
        \assert(null !== $reglages->titre && null !== $reglages->dateTirage && null !== $reglages->dateEvenement && null !== $reglages->budget);

        $this->verifierLesDates($reglages->dateTirage, $reglages->dateEvenement, $maintenant);

        $tirage = new TirageNoel($this->nouveauCode(), $createur, $reglages->titre, $reglages->dateTirage, $reglages->dateEvenement, $reglages->budget);
        $tirage->setLieu($reglages->lieu)->setMessage($reglages->message)->setPresentation($reglages->presentation);

        $this->em->persist($tirage);
        $this->em->flush();

        return $tirage;
    }

    public function modifier(TirageNoel $tirage, User $compte, ReglagesDuTirageNoel $reglages, \DateTimeImmutable $maintenant = new \DateTimeImmutable()): TirageNoel
    {
        $this->exigerLOrganisateur($tirage, $compte);
        $this->exigerOuvert($tirage);

        $dateTirage = $reglages->dateTirage ?? $tirage->getDateTirage();
        $dateEvenement = $reglages->dateEvenement ?? $tirage->getDateEvenement();
        $this->verifierLesDates($dateTirage, $dateEvenement, $maintenant, $dateTirage == $tirage->getDateTirage());

        $avant = [$tirage->getDateTirage(), $tirage->getDateEvenement(), $tirage->getBudget()];

        $tirage->setDateTirage($dateTirage)->setDateEvenement($dateEvenement);
        if (null !== $reglages->titre) {
            $tirage->setTitre($reglages->titre);
        }
        if (null !== $reglages->budget) {
            $tirage->setBudget($reglages->budget);
        }
        if ($reglages->lieuFourni) {
            $tirage->setLieu($reglages->lieu);
        }
        if ($reglages->messageFourni) {
            $tirage->setMessage($reglages->message);
        }
        if ($reglages->presentationFournie) {
            $tirage->setPresentation($reglages->presentation);
        }
        $tirage->toucher();
        $this->em->flush();

        if ($avant != [$tirage->getDateTirage(), $tirage->getDateEvenement(), $tirage->getBudget()]) {
            $this->prevenir(
                $tirage,
                NotificationKind::NoelTirageModifie,
                \sprintf('« %s » a changé', $tirage->getTitre()),
                'L’organisateur a modifié la date ou le budget du tirage de Noël.',
                $compte,
            );
        }

        return $tirage;
    }

    public function repondre(TirageNoel $tirage, User $compte, ReponseAuTirage $reponse): TirageNoel
    {
        $this->exigerOuvert($tirage);

        $conjoint = null;
        if (null !== $reponse->conjointUserId) {
            $participationDuConjoint = $this->participationParCompte($tirage, $reponse->conjointUserId);

            if ($reponse->conjointUserId === $compte->getId() || null === $participationDuConjoint || !$participationDuConjoint->participe()) {
                throw new ValidationException(['conjointUserId' => 'Le conjoint doit être un autre participant de ce tirage.']);
            }
            $conjoint = $participationDuConjoint->getUser();
        }

        $participation = $tirage->participationDe($compte);
        if (null === $participation) {
            $participation = new Participation($tirage, $compte, $reponse->reponse);
            $tirage->getParticipations()->add($participation);
            $this->em->persist($participation);
        }
        $participation->repondre($reponse->reponse, $reponse->presence, $reponse->vote, $conjoint);
        $this->accorderLesConjoints($tirage, $participation);

        $tirage->toucher();
        $this->em->flush();

        return $tirage;
    }

    /**
     * Le lien de conjoint est **réciproque** : une seule réponse suffit. Qui me désigne me
     * voit le désigner ; qui je quitte — ou qui part — ne me désigne plus ; et un tiers qui
     * désignait l'un de nous deux est délié.
     */
    private function accorderLesConjoints(TirageNoel $tirage, Participation $participation): void
    {
        $moi = $participation->getUser()->getId();
        $conjoint = $participation->getConjoint();

        foreach ($tirage->getParticipations() as $autre) {
            if ($autre === $participation) {
                continue;
            }

            $estMonConjoint = null !== $conjoint && $autre->getUser()->getId() === $conjoint->getId();
            $designe = $autre->getConjoint()?->getId();

            if ($estMonConjoint) {
                $autre->repondre($autre->getReponse(), $autre->getPresence(), $autre->getVote(), $participation->getUser());
            } elseif (null !== $designe && ($designe === $moi || $designe === $conjoint?->getId())) {
                $autre->repondre($autre->getReponse(), $autre->getPresence(), $autre->getVote(), null);
            }
        }
    }

    /** Le tirage en avance, par l'organisateur. */
    public function lancer(TirageNoel $tirage, User $compte): TirageNoel
    {
        $this->exigerLOrganisateur($tirage, $compte);

        return $this->tirer($tirage);
    }

    /**
     * Les tirages arrivés à leur date. Un tirage impossible (moins de trois participants,
     * conjoints qui ne laissent aucune solution) reste ouvert et sera retenté au prochain
     * passage — l'organisateur peut encore décaler la date ou les participants changer.
     *
     * @return int le nombre de tirages faits
     */
    public function tirerLesEcheances(\DateTimeImmutable $maintenant = new \DateTimeImmutable()): int
    {
        $faits = 0;

        foreach ($this->tirages->echus($maintenant) as $id) {
            $tirage = $this->tirages->find($id);

            if (null === $tirage) {
                continue;
            }

            try {
                $this->tirer($tirage);
                ++$faits;
            } catch (ConflictException $echec) {
                $this->journal->warning('Tirage de Noël échu non tiré.', ['code' => $tirage->getCode(), 'raison' => $echec->getMessage()]);
            }

            $this->em->clear();
        }

        return $faits;
    }

    /** À qui le compte offre-t-il ? */
    public function attributionDe(TirageNoel $tirage, User $compte): User
    {
        foreach ($tirage->getAttributions() as $attribution) {
            if ($attribution->getDonneur()->getId() === $compte->getId()) {
                return $attribution->getReceveur();
            }
        }

        throw new NotFoundException('Aucune attribution pour toi dans ce tirage.');
    }

    /** Qui a offert au compte — seulement si le vote l'a voulu, après la remise. */
    public function donneurDe(TirageNoel $tirage, User $compte, \DateTimeImmutable $maintenant = new \DateTimeImmutable()): User
    {
        if (!$tirage->revelationOuverte($maintenant)) {
            throw new ForbiddenException('revelation_fermee', 'La révélation n’est pas ouverte pour ce tirage.');
        }

        foreach ($tirage->getAttributions() as $attribution) {
            if ($attribution->getReceveur()->getId() === $compte->getId()) {
                return $attribution->getDonneur();
            }
        }

        throw new NotFoundException('Personne ne t’a tiré dans ce tirage.');
    }

    /** @return array{magie: int, apresOuverture: int} */
    public function votes(TirageNoel $tirage): array
    {
        $votes = ['magie' => 0, 'apresOuverture' => 0];

        foreach ($tirage->participants() as $participation) {
            match ($participation->getVote()) {
                Revelation::Magie => ++$votes['magie'],
                Revelation::ApresOuverture => ++$votes['apresOuverture'],
                null => null,
            };
        }

        return $votes;
    }

    /** Le compte voit-il ce tirage de l'intérieur : organisateur ou a répondu. */
    public function estMembreDuTirage(TirageNoel $tirage, User $compte): bool
    {
        return $tirage->estCreePar($compte) || null !== $tirage->participationDe($compte);
    }

    private function tirer(TirageNoel $tirage): TirageNoel
    {
        // Une transaction à la main plutôt que `wrapInTransaction()` : celle-ci ferme
        // l'EntityManager sur toute exception, et un tirage impossible n'est pas une panne
        // — la commande d'échéance passe au suivant avec le même EntityManager.
        $this->em->beginTransaction();
        try {
            // Sous verrou : deux lancements simultanés (l'organisateur et la commande
            // d'échéance) ne tirent qu'une fois — le second relit `tire` et s'arrête.
            $this->em->refresh($tirage, LockMode::PESSIMISTIC_WRITE);
            $this->exigerOuvert($tirage);

            $participants = $tirage->participants();
            $comptes = [];
            $couples = [];
            foreach ($participants as $participation) {
                $id = $participation->getUser()->getId();
                \assert(null !== $id);
                $comptes[$id] = $participation->getUser();
            }
            foreach ($participants as $participation) {
                $conjoint = $participation->getConjoint()?->getId();
                if (null !== $conjoint && isset($comptes[$conjoint])) {
                    $couples[] = [(int) $participation->getUser()->getId(), $conjoint];
                }
            }

            if (\count($comptes) < TirageAuSortNoel::PARTICIPANTS_MIN) {
                throw new ConflictException('trop_peu_de_participants', \sprintf('Il faut au moins %d participants pour tirer au sort.', TirageAuSortNoel::PARTICIPANTS_MIN));
            }

            $attributions = $this->tirage->tirer(array_keys($comptes), $couples, $this->hasard)
                ?? throw new ConflictException('tirage_impossible', 'Le tirage est impossible avec ces conjoints : il faut plus de participants.');

            $votes = $this->votes($tirage);
            $revelation = $votes['apresOuverture'] > $votes['magie'] ? Revelation::ApresOuverture : Revelation::Magie;

            $tirage->marquerTire($attributions, $comptes, $revelation, new \DateTimeImmutable());
            $this->em->flush();
            $this->em->commit();
        } catch (\Throwable $echec) {
            $this->em->rollback();

            throw $echec;
        }

        $this->prevenir(
            $tirage,
            NotificationKind::NoelTirageEffectue,
            \sprintf('🎁 Le tirage « %s » est fait !', $tirage->getTitre()),
            'Découvre à qui tu offres un cadeau.',
        );

        return $tirage;
    }

    private function prevenir(TirageNoel $tirage, NotificationKind $kind, string $titre, string $corps, ?User $sauf = null): void
    {
        foreach ($tirage->participants() as $participation) {
            $id = $participation->getUser()->getId();

            if (null === $id || $id === $sauf?->getId()) {
                continue;
            }

            $this->notifications->notifier($id, $kind, $titre, $corps, 'noel.tirage', ['code' => $tirage->getCode()]);
        }
    }

    private function participationParCompte(TirageNoel $tirage, int $userId): ?Participation
    {
        foreach ($tirage->getParticipations() as $participation) {
            if ($participation->getUser()->getId() === $userId) {
                return $participation;
            }
        }

        return null;
    }

    private function verifierLesDates(\DateTimeImmutable $dateTirage, \DateTimeImmutable $dateEvenement, \DateTimeImmutable $maintenant, bool $dateTirageInchangee = false): void
    {
        $champs = [];
        if (!$dateTirageInchangee && $dateTirage < $maintenant->modify('-5 minutes')) {
            $champs['dateTirage'] = 'La date du tirage doit être dans le futur.';
        }
        if ($dateEvenement < $dateTirage) {
            $champs['dateEvenement'] = 'La remise des cadeaux doit avoir lieu après le tirage.';
        }
        if ([] !== $champs) {
            throw new ValidationException($champs);
        }
    }

    private function exigerOuvert(TirageNoel $tirage): void
    {
        if (!$tirage->estOuvert()) {
            throw new ConflictException('tirage_verrouille', 'Le tirage a déjà eu lieu : plus personne ne peut rejoindre, partir ou modifier.');
        }
    }

    private function exigerLOrganisateur(TirageNoel $tirage, User $compte): void
    {
        if (!$tirage->estCreePar($compte)) {
            throw new ForbiddenException('non_createur', 'Seul l’organisateur du tirage peut faire cela.');
        }
    }

    private function nouveauCode(): string
    {
        do {
            $code = $this->hasard->getBytesFromString(self::ALPHABET, self::LONGUEUR_DU_CODE);
        } while (null !== $this->tirages->parCode($code));

        return $code;
    }
}
