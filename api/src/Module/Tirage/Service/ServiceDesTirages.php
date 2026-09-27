<?php

declare(strict_types=1);

namespace App\Module\Tirage\Service;

use App\Module\Core\Contract\AnnuaireDesMembresInterface;
use App\Module\Core\Enum\NotificationKind;
use App\Module\Core\Service\NotificationService;
use App\Module\Tirage\Contract\PortArbrePourTirages;
use App\Module\Tirage\Entity\Attribution;
use App\Module\Tirage\Entity\Edition;
use App\Module\Tirage\Entity\Participant;
use App\Module\Tirage\Entity\Tirage;
use App\Module\Tirage\Enum\Presence;
use App\Module\Tirage\Exception\ErreurDeTirage;
use App\Module\Tirage\Repository\TirageRepository;
use Doctrine\ORM\EntityManagerInterface;
use Random\Randomizer;

/**
 * Tout ce qu'on fait d'un tirage, et **qui a le droit de le faire** — les deux au même
 * endroit, pour qu'aucune route ne décide seule.
 *
 * ## Trois rôles, et ce que chacun voit
 *
 * - **L'organisateur** (et un administrateur, pour qu'un tirage ne reste pas orphelin
 *   quand son organisateur s'en va) règle le tirage, gère les participants, ouvre
 *   l'édition de l'année et lance le tirage. **Il ne voit pas qui offre à qui** : il
 *   participe souvent lui-même.
 * - **Un participant** voit le tirage, ses participants, et **sa propre** attribution.
 * - **Un référent** voit en plus l'attribution de ceux qu'il accompagne.
 *
 * Un tirage **ouvert** est visible de tout compte connecté, qui peut le rejoindre s'il est
 * rattaché à une personne de l'arbre. Un tirage fermé est introuvable pour les autres :
 * `404`, pas `403` (§ 15.3 du contrat).
 */
final readonly class ServiceDesTirages
{
    public function __construct(
        private EntityManagerInterface $em,
        private TirageRepository $tirages,
        private PortArbrePourTirages $arbre,
        private AnnuaireDesMembresInterface $annuaire,
        private NotificationService $notifications,
        private TirageAuSort $tirageAuSort,
    ) {
    }

    /** @return list<Tirage> */
    public function visibles(LecteurDuTirage $lecteur): array
    {
        return array_values(array_filter(
            $this->tirages->tous(),
            fn (Tirage $tirage): bool => $this->estVisible($tirage, $lecteur),
        ));
    }

    public function trouver(int $id, LecteurDuTirage $lecteur): Tirage
    {
        $tirage = $this->tirages->find($id);

        if (null === $tirage || !$this->estVisible($tirage, $lecteur)) {
            throw ErreurDeTirage::introuvable();
        }

        return $tirage;
    }

    public function estVisible(Tirage $tirage, LecteurDuTirage $lecteur): bool
    {
        return $tirage->isOuvert()
            || $this->peutGerer($tirage, $lecteur)
            || (null !== $lecteur->personId && null !== $tirage->participant($lecteur->personId));
    }

    public function peutGerer(Tirage $tirage, LecteurDuTirage $lecteur): bool
    {
        return $lecteur->administrateur || $tirage->getOrganisateurUserId() === $lecteur->userId;
    }

    /**
     * Les personnes dont ce lecteur lit l'attribution : la sienne, et celles des
     * participants dont il est le référent.
     *
     * @return list<int>
     */
    public function personnesSuivies(Tirage $tirage, LecteurDuTirage $lecteur): array
    {
        if (null === $lecteur->personId) {
            return [];
        }

        $suivies = [];

        foreach ($tirage->getParticipants() as $participant) {
            if ($participant->getPersonId() === $lecteur->personId || $participant->getReferentPersonId() === $lecteur->personId) {
                $suivies[] = $participant->getPersonId();
            }
        }

        return $suivies;
    }

    /**
     * La personne du lecteur, nommée : l'écran de création l'inscrit d'office.
     *
     * @return array{personId: int, libelle: string}|null
     */
    public function personneDuLecteur(LecteurDuTirage $lecteur): ?array
    {
        if (null === $lecteur->personId) {
            return null;
        }

        $libelle = $this->arbre->libelles([$lecteur->personId])[$lecteur->personId] ?? null;

        return null === $libelle ? null : ['personId' => $lecteur->personId, 'libelle' => $libelle];
    }

    /** @param list<int> $personIds */
    public function creer(LecteurDuTirage $lecteur, ReglesDuTirage $regles, array $personIds): Tirage
    {
        $tirage = new Tirage($regles->nom, $regles->jour, $regles->mois, $lecteur->userId, $lecteur->libelle);
        $this->appliquer($tirage, $regles);

        $libelles = $this->libellesExistants($personIds, 'participants');

        foreach ($libelles as $personId => $libelle) {
            $tirage->ajouterParticipant(new Participant($tirage, $personId, $libelle, $lecteur->userId));
        }

        $this->em->persist($tirage);
        $this->em->flush();

        return $tirage;
    }

    public function modifier(Tirage $tirage, LecteurDuTirage $lecteur, ReglesDuTirage $regles): Tirage
    {
        $this->exigerLaGestion($tirage, $lecteur);
        $this->appliquer($tirage, $regles);
        $tirage->toucher();
        $this->em->flush();

        return $tirage;
    }

    public function supprimer(Tirage $tirage, LecteurDuTirage $lecteur): void
    {
        $this->exigerLaGestion($tirage, $lecteur);
        $this->em->remove($tirage);
        $this->em->flush();
    }

    public function ajouterParticipant(Tirage $tirage, LecteurDuTirage $lecteur, int $personId, ?int $referentPersonId): Tirage
    {
        $this->exigerLaGestion($tirage, $lecteur);

        if (null !== $tirage->participant($personId)) {
            throw ErreurDeTirage::dejaParticipant();
        }

        $libelle = $this->libellesExistants([$personId], 'personId')[$personId];
        $participant = new Participant($tirage, $personId, $libelle, $lecteur->userId);

        if (null !== $referentPersonId) {
            $referent = $tirage->participant($referentPersonId);

            if (null === $referent || $referentPersonId === $personId) {
                throw ErreurDeTirage::donneesInvalides(['referentPersonId' => 'Le référent doit être un autre participant du tirage.']);
            }

            $participant->setReferent($referentPersonId, $referent->getPersonLabel());
        }

        $tirage->ajouterParticipant($participant);
        $tirage->toucher();
        $this->em->flush();

        return $tirage;
    }

    /** Un participant qui se rejoint lui-même, sur un tirage ouvert. */
    public function rejoindre(Tirage $tirage, LecteurDuTirage $lecteur): Tirage
    {
        if (null === $lecteur->personId) {
            throw ErreurDeTirage::sansRattachement();
        }

        if (!$tirage->isOuvert() && !$this->peutGerer($tirage, $lecteur)) {
            throw ErreurDeTirage::fermeAuxInscriptions();
        }

        if (null !== $tirage->participant($lecteur->personId)) {
            throw ErreurDeTirage::dejaParticipant();
        }

        $libelle = $this->libellesExistants([$lecteur->personId], 'personId')[$lecteur->personId];
        $tirage->ajouterParticipant(new Participant($tirage, $lecteur->personId, $libelle, $lecteur->userId));
        $tirage->toucher();
        $this->em->flush();

        return $tirage;
    }

    /**
     * L'organisateur retire quelqu'un ; un participant se retire lui-même, ou retire
     * celui qu'il accompagne.
     *
     * Refusé tant qu'une édition **à venir** est tirée avec cette personne dedans : elle
     * laisserait quelqu'un sans cadeau. Les éditions passées gardent leurs attributions, qui
     * sont l'historique.
     */
    public function retirerParticipant(Tirage $tirage, LecteurDuTirage $lecteur, int $personId, \DateTimeImmutable $aujourdhui): Tirage
    {
        $participant = $tirage->participant($personId) ?? throw ErreurDeTirage::introuvable();

        if (!$this->peutGerer($tirage, $lecteur) && !\in_array($personId, $this->personnesSuivies($tirage, $lecteur), true)) {
            throw ErreurDeTirage::nonAutorisePourCeParticipant();
        }

        foreach ($tirage->getEditions() as $edition) {
            if ($edition->estTiree() && $edition->getDate() >= $aujourdhui->setTime(0, 0) && null !== $edition->attributionDe($personId)) {
                throw ErreurDeTirage::retraitApresLeTirage();
            }
        }

        $tirage->retirerParticipant($participant);
        $tirage->toucher();
        $this->em->flush();

        return $tirage;
    }

    public function ouvrirEdition(Tirage $tirage, LecteurDuTirage $lecteur, int $annee, ?\DateTimeImmutable $date): Tirage
    {
        $this->exigerLaGestion($tirage, $lecteur);

        if (null !== $tirage->edition($annee)) {
            throw ErreurDeTirage::editionExistante($annee);
        }

        $date ??= $this->dateDeLAnnee($tirage, $annee);
        $tirage->ajouterEdition(new Edition($tirage, $annee, $date));
        $tirage->toucher();
        $this->em->flush();

        return $tirage;
    }

    public function definirPresence(Tirage $tirage, LecteurDuTirage $lecteur, int $annee, int $personId, Presence $presence): Tirage
    {
        $edition = $tirage->edition($annee) ?? throw ErreurDeTirage::introuvable();

        if (null === $tirage->participant($personId)) {
            throw ErreurDeTirage::introuvable();
        }

        if (!$this->peutGerer($tirage, $lecteur) && !\in_array($personId, $this->personnesSuivies($tirage, $lecteur), true)) {
            throw ErreurDeTirage::nonAutorisePourCeParticipant();
        }

        // Sur place ou à distance, on reste dans le tirage ; en sortir après coup
        // laisserait quelqu'un sans cadeau.
        if ($edition->estTiree() && !$presence->participe() && null !== $edition->attributionDe($personId)) {
            throw ErreurDeTirage::retraitApresLeTirage();
        }

        $edition->definirPresence($personId, $presence);
        $this->em->flush();

        return $tirage;
    }

    /**
     * Tire au sort l'édition de l'année, et prévient chaque participant qui a un compte.
     *
     * La mémoire relit les éditions **antérieures** tirées, la plus récente d'abord.
     */
    public function tirer(Tirage $tirage, LecteurDuTirage $lecteur, int $annee, ?Randomizer $hasard = null): Tirage
    {
        $this->exigerLaGestion($tirage, $lecteur);
        $edition = $tirage->edition($annee) ?? throw ErreurDeTirage::introuvable();

        if ($edition->estTiree()) {
            throw ErreurDeTirage::dejaTiree();
        }

        $presents = [];
        $libelles = [];

        foreach ($tirage->getParticipants() as $participant) {
            if ($edition->presenceDe($participant->getPersonId())->participe()) {
                $presents[] = $participant->getPersonId();
                $libelles[$participant->getPersonId()] = $participant->getPersonLabel();
            }
        }

        $historique = [];

        foreach ($tirage->getEditions() as $passee) {
            if ($passee->getAnnee() < $annee && $passee->estTiree()) {
                $paires = [];

                foreach ($passee->getAttributions() as $attribution) {
                    $paires[$attribution->getDonneurPersonId()] = $attribution->getReceveurPersonId();
                }

                $historique[] = $paires;
            }
        }

        $resultat = $this->tirageAuSort->tirer(
            $presents,
            $tirage->isExclureConjoints() && \count($presents) > 1 ? $this->arbre->couples($presents) : [],
            $historique,
            $tirage->getMemoire(),
            $hasard ?? new Randomizer(),
        );

        $attributions = [];

        foreach ($resultat->attributions as $donneur => $receveur) {
            $attributions[] = new Attribution($edition, $donneur, $libelles[$donneur], $receveur, $libelles[$receveur]);
        }

        $edition->marquerTiree($attributions, $resultat->memoireAppliquee, new \DateTimeImmutable());
        $tirage->toucher();
        $this->em->flush();

        $this->prevenir($tirage, $edition, $presents);

        return $tirage;
    }

    public function annulerLeTirage(Tirage $tirage, LecteurDuTirage $lecteur, int $annee): Tirage
    {
        $this->exigerLaGestion($tirage, $lecteur);
        $edition = $tirage->edition($annee) ?? throw ErreurDeTirage::introuvable();

        if (!$edition->estTiree()) {
            throw ErreurDeTirage::pasEncoreTiree();
        }

        $edition->annulerLeTirage();
        $tirage->toucher();
        $this->em->flush();

        return $tirage;
    }

    /** La date de l'édition d'une année : le jour habituel, ramené au 28 février hors bissextile. */
    public function dateDeLAnnee(Tirage $tirage, int $annee): \DateTimeImmutable
    {
        $joursDuMois = (int) (new \DateTimeImmutable(\sprintf('%04d-%02d-01', $annee, $tirage->getMois())))->format('t');
        $jour = min($tirage->getJour(), $joursDuMois);

        return new \DateTimeImmutable(\sprintf('%04d-%02d-%02d', $annee, $tirage->getMois(), $jour));
    }

    private function exigerLaGestion(Tirage $tirage, LecteurDuTirage $lecteur): void
    {
        if (!$this->peutGerer($tirage, $lecteur)) {
            throw ErreurDeTirage::reserveALOrganisateur();
        }
    }

    private function appliquer(Tirage $tirage, ReglesDuTirage $regles): void
    {
        $tirage
            ->setNom($regles->nom)
            ->setJourEtMois($regles->jour, $regles->mois)
            ->setLieu($regles->lieu)
            ->setOuvert($regles->ouvert)
            ->setMemoire($regles->memoire)
            ->setExclureConjoints($regles->exclureConjoints);
    }

    /**
     * @param list<int> $personIds
     *
     * @return array<int, string>
     */
    private function libellesExistants(array $personIds, string $champ): array
    {
        $personIds = array_values(array_unique($personIds));

        if ([] === $personIds) {
            return [];
        }

        $libelles = $this->arbre->libelles($personIds);

        if (\count($libelles) !== \count($personIds)) {
            throw ErreurDeTirage::donneesInvalides([$champ => 'Une personne désignée n’existe pas dans l’arbre.']);
        }

        return $libelles;
    }

    /**
     * Une notification par compte concerné : le participant, et le référent de ceux qui
     * n'en ont pas. Elle ne nomme **personne** — le secret ne passe pas par une boîte de
     * notifications.
     *
     * @param list<int> $presents
     */
    private function prevenir(Tirage $tirage, Edition $edition, array $presents): void
    {
        $comptes = [];

        foreach ($presents as $personId) {
            $participant = $tirage->participant($personId);

            foreach ([$personId, $participant?->getReferentPersonId()] as $suivi) {
                if (null === $suivi) {
                    continue;
                }

                $membreId = $this->annuaire->rattachementsDeLaPersonne($suivi)['membreId'];
                $userId = null === $membreId ? null : $this->annuaire->userIdDuMembre($membreId);

                if (null !== $userId) {
                    $comptes[$userId] = true;
                }
            }
        }

        foreach (array_keys($comptes) as $userId) {
            $this->notifications->notifier(
                $userId,
                NotificationKind::TirageEffectue,
                mb_substr(\sprintf('Tirage au sort : %s %d', $tirage->getNom(), $edition->getAnnee()), 0, 160),
                'Le tirage est fait : découvrez à qui vous offrez un cadeau.',
                'tirage.detail',
                ['id' => $tirage->getId()],
            );
        }
    }
}
