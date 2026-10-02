<?php

declare(strict_types=1);

namespace App\Module\Evenement\Service;

use App\Module\Core\Contract\AnnuaireDesMembresInterface;
use App\Module\Core\Enum\NotificationKind;
use App\Module\Core\Security\CercleDeVisibiliteInterface;
use App\Module\Core\Service\NotificationService;
use App\Module\Evenement\Contract\PortArbrePourEvenements;
use App\Module\Evenement\Entity\Evenement;
use App\Module\Evenement\Entity\Invitation;
use App\Module\Evenement\Enum\Reponse;
use App\Module\Evenement\Exception\ErreurDEvenement;
use App\Module\Evenement\Repository\EvenementRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Tout ce qu'on fait d'un événement, et **qui a le droit de le faire** — les deux au même
 * endroit, pour qu'aucune route ne décide seule. C'est la forme de
 * `Tirage\Service\ServiceDesTirages`, et ce sont ses règles quand rien ne demande d'en
 * changer.
 *
 * ## Qui crée
 *
 * **Tout membre**, comme pour un tirage : le contrôleur est `ReserveAuxMembres`, et rien de
 * plus. Une cousinade s'organise entre cousins ; la réserver à l'administrateur en ferait une
 * demande à lui adresser, c'est-à-dire un détour pour un geste qui n'engage que l'organisateur.
 *
 * ## Deux rôles, et ce que chacun voit
 *
 * - **L'organisateur** (et un administrateur, pour qu'un événement ne reste pas orphelin
 *   quand son organisateur s'en va) modifie, annule, invite, retire, partage les liens et
 *   relance. Il voit **le suivi** : qui a vu, qui a répondu, et quand.
 * - **Un invité** voit l'événement, sa propre réponse, et **qui vient** — rien de plus. Ni
 *   qui a décliné, ni qui a vu sans répondre, ni aucun horodatage : voir
 *   {@see SerialiseurDEvenement} pour le pourquoi.
 *
 * Un événement est introuvable pour qui n'en est ni l'organisateur ni l'invité : `404`, pas
 * `403` (§ 15.3 du contrat). Il n'y a pas d'événement « ouvert à la famille » : une
 * invitation est un geste, pas une inscription.
 *
 * ## Les notifications
 *
 * Trois natures, chacune envoyée **après** l'écriture qui la cause, aux seuls invités qui ont
 * un compte — l'auteur du geste jamais à lui-même :
 *
 * - `evenement_invitation` à l'invitation (création, ou ajout après coup) ;
 * - `evenement_relance` à la relance, pour qui n'a pas répondu ;
 * - `evenement_annule` à l'annulation, pour qui avait répondu **ou vu** : prévenir qui n'a
 *   jamais ouvert l'annonce d'une annulation lui apprendrait l'existence d'un événement
 *   auquel il ne pensait pas aller — sa cloche n'a rien à y gagner.
 *
 * Le texte nomme l'organisateur et l'événement, **jamais un autre invité**.
 */
final readonly class ServiceDesEvenements
{
    /**
     * Le refus d'une personne désignée qu'on n'invite pas — inconnue **ou** vivante hors
     * cercle : un seul texte pour les deux, sans quoi la réponse sert d'oracle d'existence.
     */
    private const string PERSONNE_INCONNUE = 'Une personne désignée n’existe pas dans l’arbre.';

    public function __construct(
        private EntityManagerInterface $em,
        private EvenementRepository $evenements,
        private PortArbrePourEvenements $arbre,
        private AnnuaireDesMembresInterface $annuaire,
        private NotificationService $notifications,
        private CercleDeVisibiliteInterface $cercle,
    ) {
    }

    /* ───────────────── Lire ───────────────── */

    /** @return list<Evenement> */
    public function visibles(LecteurDEvenement $lecteur): array
    {
        return array_values(array_filter(
            $this->evenements->tous(),
            fn (Evenement $evenement): bool => $this->estVisible($evenement, $lecteur),
        ));
    }

    public function trouver(int $id, LecteurDEvenement $lecteur): Evenement
    {
        $evenement = $this->evenements->find($id);

        if (null === $evenement || !$this->estVisible($evenement, $lecteur)) {
            throw ErreurDEvenement::introuvable();
        }

        return $evenement;
    }

    public function estVisible(Evenement $evenement, LecteurDEvenement $lecteur): bool
    {
        return $this->peutGerer($evenement, $lecteur) || null !== $this->invitationDuLecteur($evenement, $lecteur);
    }

    public function peutGerer(Evenement $evenement, LecteurDEvenement $lecteur): bool
    {
        return $lecteur->administrateur || $evenement->getOrganisateurUserId() === $lecteur->userId;
    }

    public function invitationDuLecteur(Evenement $evenement, LecteurDEvenement $lecteur): ?Invitation
    {
        return null === $lecteur->personId ? null : $evenement->invitation($lecteur->personId);
    }

    /**
     * On répond tant que l'événement tient et n'est pas fini — le dernier jour compris :
     * « je viens finalement dimanche » se dit encore le dimanche matin.
     */
    public function peutRepondre(Evenement $evenement, \DateTimeImmutable $aujourdhui): bool
    {
        return !$evenement->estAnnule() && !$evenement->estPasse($aujourdhui);
    }

    /**
     * La personne du lecteur, nommée : l'écran de création la coche d'office.
     *
     * @return array{personId: int, libelle: string}|null
     */
    public function personneDuLecteur(LecteurDEvenement $lecteur): ?array
    {
        if (null === $lecteur->personId) {
            return null;
        }

        $libelle = $this->arbre->libelles([$lecteur->personId])[$lecteur->personId] ?? null;

        return null === $libelle ? null : ['personId' => $lecteur->personId, 'libelle' => $libelle];
    }

    /**
     * Le compte de chaque personne demandée : `aUnCompte` (un rattachement, même en
     * attente — c'est la règle des liens de tirage), et le `userId` à notifier quand il
     * existe. Une seule passe par réponse : le suivi en a besoin pour chaque invité.
     *
     * @param list<int> $personIds
     *
     * @return array<int, array{aUnCompte: bool, userId: int|null}>
     */
    public function comptes(array $personIds): array
    {
        $comptes = [];

        foreach (array_unique($personIds) as $personId) {
            $membreId = $this->annuaire->rattachementsDeLaPersonne($personId)['membreId'];
            $comptes[$personId] = [
                'aUnCompte' => null !== $membreId,
                'userId' => null === $membreId ? null : $this->annuaire->userIdDuMembre($membreId),
            ];
        }

        return $comptes;
    }

    /* ───────────────── Créer, modifier, annuler ───────────────── */

    /** @param list<int> $personIds */
    public function creer(LecteurDEvenement $lecteur, AnnonceDeLEvenement $annonce, array $personIds, \DateTimeImmutable $maintenant): Evenement
    {
        $evenement = new Evenement($annonce->titre, $annonce->debut, $annonce->fin, $lecteur->userId, $lecteur->libelle);
        $this->appliquer($evenement, $annonce);

        $libelles = $this->libellesDeVivants($this->dansLeCercle($personIds, 'invites'), 'invites');

        foreach ($libelles as $personId => $libelle) {
            $invitation = new Invitation($evenement, $personId, $libelle, $lecteur->userId);

            // L'organisateur qui s'invite lui-même a vu son annonce : il vient de l'écrire.
            // Sans cela, il ouvrirait son propre suivi en se trouvant « pas encore vu ».
            if ($personId === $lecteur->personId && !$lecteur->vueEmpruntee) {
                $invitation->marquerVue($maintenant);
            }

            $evenement->ajouterInvitation($invitation);
        }

        $this->em->persist($evenement);
        $this->em->flush();

        $this->prevenirDesInvitations($evenement, $evenement->getInvitations(), $lecteur);

        return $evenement;
    }

    public function modifier(Evenement $evenement, LecteurDEvenement $lecteur, AnnonceDeLEvenement $annonce, \DateTimeImmutable $aujourdhui): Evenement
    {
        $this->exigerLaGestion($evenement, $lecteur);
        $this->exigerModifiable($evenement, $aujourdhui);

        $this->appliquer($evenement, $annonce);
        $evenement->toucher();
        $this->em->flush();

        return $evenement;
    }

    /**
     * Annule l'événement et prévient qui avait répondu ou vu. Refusé sur un événement déjà
     * annulé (la notification partirait deux fois) ou terminé (il n'y a plus rien à annuler).
     */
    public function annuler(Evenement $evenement, LecteurDEvenement $lecteur, \DateTimeImmutable $maintenant): Evenement
    {
        $this->exigerLaGestion($evenement, $lecteur);
        $this->exigerModifiable($evenement, $maintenant);

        $evenement->annuler($maintenant);
        $evenement->toucher();
        $this->em->flush();

        $concernees = array_values(array_filter(
            $evenement->getInvitations(),
            static fn (Invitation $i): bool => null !== $i->getReponse() || null !== $i->getVuLe(),
        ));

        $this->notifier(
            $concernees,
            $lecteur,
            NotificationKind::EvenementAnnule,
            'Annulé : '.$evenement->getTitre(),
            \sprintf(
                '%s a annulé l’événement prévu %s.',
                $evenement->getOrganisateurLibelle(),
                PeriodeEnFrancais::de($evenement->getDebut(), $evenement->getFin()),
            ),
            $evenement,
        );

        return $evenement;
    }

    /* ───────────────── Les invités ───────────────── */

    /**
     * Invite après coup. Une personne déjà invitée est **ignorée**, sans erreur : l'écran
     * envoie une sélection, et deux organisateurs (l'organisateur et un administrateur) qui
     * invitent la même cousine à la même minute n'ont rien fait de contradictoire. Chaque
     * nouvel invité qui a un compte est prévenu — les anciens ne le sont pas une seconde fois.
     *
     * @param list<int> $personIds
     */
    public function inviter(Evenement $evenement, LecteurDEvenement $lecteur, array $personIds, \DateTimeImmutable $aujourdhui): Evenement
    {
        $this->exigerLaGestion($evenement, $lecteur);
        $this->exigerModifiable($evenement, $aujourdhui);

        $nouvelles = array_values(array_filter(
            array_unique($personIds),
            static fn (int $personId): bool => null === $evenement->invitation($personId),
        ));

        if ([] === $nouvelles) {
            return $evenement;
        }

        $libelles = $this->libellesDeVivants($this->dansLeCercle($nouvelles, 'personIds'), 'personIds');
        $ajoutees = [];

        foreach ($libelles as $personId => $libelle) {
            $invitation = new Invitation($evenement, $personId, $libelle, $lecteur->userId);
            $evenement->ajouterInvitation($invitation);
            $ajoutees[] = $invitation;
        }

        $evenement->toucher();
        $this->em->flush();

        $this->prevenirDesInvitations($evenement, $ajoutees, $lecteur);

        return $evenement;
    }

    /**
     * Retire un invité. Son lien personnel meurt avec la ligne. Il n'est **pas** prévenu :
     * « vous n'êtes plus invité » est un message qu'un organisateur préfère dire lui-même,
     * et une notification automatique le dirait plus mal que lui.
     */
    public function retirer(Evenement $evenement, LecteurDEvenement $lecteur, int $personId, \DateTimeImmutable $aujourdhui): Evenement
    {
        $this->exigerLaGestion($evenement, $lecteur);
        $this->exigerModifiable($evenement, $aujourdhui);

        $invitation = $evenement->invitation($personId) ?? throw ErreurDEvenement::introuvable();
        $evenement->retirerInvitation($invitation);
        $evenement->toucher();
        $this->em->flush();

        return $evenement;
    }

    /* ───────────────── Répondre, voir ───────────────── */

    public function repondre(Evenement $evenement, LecteurDEvenement $lecteur, Reponse $reponse, \DateTimeImmutable $maintenant): Evenement
    {
        $invitation = $this->invitationDuLecteur($evenement, $lecteur) ?? throw ErreurDEvenement::nonInvite();

        $this->enregistrerLaReponse($invitation, $reponse, $maintenant);

        return $evenement;
    }

    /** La même chose, pour une invitation désignée par son lien personnel. */
    public function enregistrerLaReponse(Invitation $invitation, Reponse $reponse, \DateTimeImmutable $maintenant): void
    {
        if (!$this->peutRepondre($invitation->getEvenement(), $maintenant)) {
            throw ErreurDEvenement::reponseFigee();
        }

        $invitation->repondre($reponse, $maintenant);
        $this->em->flush();
    }

    /**
     * L'annonce a été vue par ce lecteur — **sauf** sous la bascule « voir comme un membre » :
     * l'administrateur qui vérifie un écran ne voit pas à la place du membre. Idempotent,
     * et sans écriture quand la vue était déjà posée.
     */
    public function marquerVu(Evenement $evenement, LecteurDEvenement $lecteur, \DateTimeImmutable $maintenant): void
    {
        if ($lecteur->vueEmpruntee) {
            return;
        }

        $invitation = $this->invitationDuLecteur($evenement, $lecteur);

        if (null !== $invitation && $invitation->marquerVue($maintenant)) {
            $this->em->flush();
        }
    }

    /* ───────────────── Relancer ───────────────── */

    /**
     * Renvoie une notification à chaque invité **qui a un compte et n'a pas répondu** — qu'il
     * ait vu l'annonce (le fantôme) ou non. Les invités sans compte ne reçoivent rien d'ici :
     * c'est à l'organisateur de leur renvoyer leur lien, et l'écran le lui rappelle.
     *
     * Une relance par {@see Evenement::DELAI_ENTRE_RELANCES_HEURES} heures au plus, pour tout
     * l'événement : le plafond protège les invités, pas l'organisateur, et il ne se contourne
     * pas en relançant les uns puis les autres.
     *
     * @return int le nombre d'invités relancés
     */
    public function relancer(Evenement $evenement, LecteurDEvenement $lecteur, \DateTimeImmutable $maintenant): int
    {
        $this->exigerLaGestion($evenement, $lecteur);
        $this->exigerModifiable($evenement, $maintenant);

        $prochaine = $evenement->prochaineRelancePossibleLe();

        if (null !== $prochaine && $prochaine > $maintenant) {
            throw ErreurDEvenement::relanceTropRecente($prochaine);
        }

        $cibles = $this->aRelancer($evenement, $lecteur);

        if ([] === $cibles) {
            throw ErreurDEvenement::personneARelancer();
        }

        foreach ($cibles as $invitation) {
            $invitation->marquerRelance($maintenant);
        }

        $evenement->marquerRelance($maintenant);
        $this->em->flush();

        $this->notifier(
            $cibles,
            $lecteur,
            NotificationKind::EvenementRelance,
            'Rappel : '.$evenement->getTitre(),
            \sprintf(
                '%s attend votre réponse pour l’événement prévu %s. Dites si vous venez.',
                $evenement->getOrganisateurLibelle(),
                PeriodeEnFrancais::de($evenement->getDebut(), $evenement->getFin()),
            ),
            $evenement,
        );

        return \count($cibles);
    }

    /**
     * Les invités qu'une relance toucherait : sans réponse, avec un compte, et pas l'auteur
     * de la relance lui-même.
     *
     * @return list<Invitation>
     */
    public function aRelancer(Evenement $evenement, LecteurDEvenement $lecteur): array
    {
        $sansReponse = array_values(array_filter(
            $evenement->getInvitations(),
            static fn (Invitation $i): bool => null === $i->getReponse(),
        ));
        $comptes = $this->comptes(array_map(static fn (Invitation $i): int => $i->getPersonId(), $sansReponse));

        return array_values(array_filter(
            $sansReponse,
            static fn (Invitation $i): bool => null !== $comptes[$i->getPersonId()]['userId']
                && $comptes[$i->getPersonId()]['userId'] !== $lecteur->userId,
        ));
    }

    /* ───────────────── Règles ───────────────── */

    private function exigerLaGestion(Evenement $evenement, LecteurDEvenement $lecteur): void
    {
        if (!$this->peutGerer($evenement, $lecteur)) {
            throw ErreurDEvenement::reserveALOrganisateur();
        }
    }

    /** Un événement annulé ou terminé ne change plus : il est devenu un souvenir. */
    private function exigerModifiable(Evenement $evenement, \DateTimeImmutable $aujourdhui): void
    {
        if ($evenement->estAnnule()) {
            throw ErreurDEvenement::annule();
        }

        if ($evenement->estPasse($aujourdhui)) {
            throw ErreurDEvenement::passe();
        }
    }

    private function appliquer(Evenement $evenement, AnnonceDeLEvenement $annonce): void
    {
        $evenement
            ->setTitre($annonce->titre)
            ->setDates($annonce->debut, $annonce->fin)
            ->setLieu($annonce->lieu)
            ->setDescription($annonce->description);
    }

    /**
     * Les personnes désignées par le lecteur, **toutes dans son cercle** — ou le refus en
     * bloc. La règle et sa raison sont celles de `ServiceDesTirages::dansLeCercle()` : une
     * invitation **enregistre** le nom de la personne et le rend ensuite ; sans ce filtre,
     * `POST /api/evenements` avec un identifiant pris au hasard rendrait le vrai nom de
     * n'importe quel vivant de l'arbre. Le message est celui d'une personne inconnue, mot pour
     * mot.
     *
     * @param list<int> $personIds
     *
     * @return list<int>
     */
    private function dansLeCercle(array $personIds, string $champ): array
    {
        $personIds = array_values(array_unique($personIds));
        $visibles = $this->cercle->filtrerPersonnes($personIds);

        if (\count($visibles) !== \count($personIds)) {
            throw ErreurDEvenement::donneesInvalides([$champ => self::PERSONNE_INCONNUE]);
        }

        return $visibles;
    }

    /**
     * Les noms des personnes désignées, qui doivent **exister et vivre** : on n'invite pas une
     * personne disparue. L'écran ne les propose déjà pas (il ne coche que les `vivant` du
     * graphe) ; ce refus-ci tient la règle pour un appel qui ne passerait pas par lui.
     *
     * @param list<int> $personIds
     *
     * @return array<int, string>
     */
    private function libellesDeVivants(array $personIds, string $champ): array
    {
        if ([] === $personIds) {
            return [];
        }

        $libelles = $this->arbre->libelles($personIds);

        if (\count($libelles) !== \count($personIds)) {
            throw ErreurDEvenement::donneesInvalides([$champ => self::PERSONNE_INCONNUE]);
        }

        $disparues = $this->arbre->nonVivantes(array_keys($libelles));

        if ([] !== $disparues) {
            $noms = array_map(static fn (int $personId): string => $libelles[$personId], $disparues);

            throw ErreurDEvenement::inviteDecede($champ, array_values($noms));
        }

        return $libelles;
    }

    /** @param list<Invitation> $invitations */
    private function prevenirDesInvitations(Evenement $evenement, array $invitations, LecteurDEvenement $lecteur): void
    {
        $periode = PeriodeEnFrancais::de($evenement->getDebut(), $evenement->getFin());
        $lieu = $evenement->getLieu();

        $this->notifier(
            $invitations,
            $lecteur,
            NotificationKind::EvenementInvitation,
            'Invitation : '.$evenement->getTitre(),
            \sprintf(
                '%s vous invite %s%s. Dites si vous venez.',
                $evenement->getOrganisateurLibelle(),
                $periode,
                null === $lieu ? '' : ', '.$lieu,
            ),
            $evenement,
        );
    }

    /**
     * Une notification par compte concerné, jamais à l'auteur du geste. Les invités sans
     * compte ne reçoivent rien d'ici — c'est leur lien personnel qui les tient au courant.
     *
     * @param list<Invitation> $invitations
     */
    private function notifier(
        array $invitations,
        LecteurDEvenement $lecteur,
        NotificationKind $nature,
        string $titre,
        string $corps,
        Evenement $evenement,
    ): void {
        $comptes = $this->comptes(array_map(static fn (Invitation $i): int => $i->getPersonId(), $invitations));
        $destinataires = [];

        foreach ($comptes as $compte) {
            if (null !== $compte['userId'] && $compte['userId'] !== $lecteur->userId) {
                $destinataires[$compte['userId']] = true;
            }
        }

        foreach (array_keys($destinataires) as $userId) {
            $this->notifications->notifier(
                $userId,
                $nature,
                mb_substr($titre, 0, 160),
                $corps,
                'evenement.detail',
                ['id' => $evenement->getId()],
            );
        }
    }
}
