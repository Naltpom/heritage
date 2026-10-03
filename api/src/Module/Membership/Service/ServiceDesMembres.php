<?php

declare(strict_types=1);

namespace App\Module\Membership\Service;

use App\Module\Core\Entity\User;
use App\Module\Core\Enum\AuditAction;
use App\Module\Core\Enum\UserStatus;
use App\Module\Core\Exception\ConflictException;
use App\Module\Core\Exception\NotFoundException;
use App\Module\Core\Exception\ValidationException;
use App\Module\Core\Repository\UserRepository;
use App\Module\Core\Security\CercleDeVisibiliteInterface;
use App\Module\Core\Service\AuditService;
use App\Module\Membership\Contract\PortraitsDesComptes;
use App\Module\Membership\Entity\Member;
use App\Module\Membership\Enum\MatchKind;
use App\Module\Membership\Enum\MemberStatus;
use App\Module\Membership\Repository\MemberRepository;
use App\Module\Membership\Service\Port\PersonneDeLArbre;
use App\Module\Membership\Service\Port\PortArbre;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/**
 * L'activation d'un membre par un administrateur —
 * `POST /api/administration/membres/{id}/activer` (§ 10.1, § 12.3 du contrat) — et, depuis
 * l'inscription sans fiche (ADR 0013), son refus : `POST .../{id}/refuser`.
 *
 * ## C'est le seul endroit où le régime change
 *
 * `core_user.status` **et** `mbr_member.status` passent `actif` **dans la même
 * transaction**. Les deux, et ensemble : `Core\Security\RegimeVoter` ne lit que le premier
 * — « interroger `Membership` depuis `Core` violerait l'ADR 0002 pour n'apprendre rien de
 * plus » — et c'est cette simultanéité qui rend l'inférence correcte. Les désynchroniser
 * donnerait un compte qui lit comme un membre sans rattachement validé, ou l'inverse.
 *
 * ## Il n'y a aucun cache à invalider ici
 *
 * Point explicite du § 12.3, et il vaut la peine d'être lu deux fois : « le nouveau membre
 * a une clé de cercle qui lui est propre ; il n'existait aucune entrée sous cette clé. Un
 * agent qui ajouterait une invalidation ici ajouterait un déclencheur à une liste qui
 * n'existe pas — c'est exactement la dette que l'empreinte recalculée supprime. »
 *
 * ## `personId` facultatif corrige un rattachement erroné — et devient obligatoire sans fiche
 *
 * Il fait alors passer `match_kind` à `manuel_admin` : la trace de *comment* le
 * rattachement a été établi ne doit pas continuer d'annoncer une recherche que
 * l'administrateur vient précisément de corriger. Pour un compte `a_rattacher`, il n'y a
 * rien à corriger — il n'y a rien du tout : `personId` est exigé, et la fiche doit être
 * celle d'une personne **vivante** que personne ne réclame encore.
 *
 * ## La photo de l'inscription suit le compte
 *
 * Un compte en attente peut avoir déposé sa photo avant d'avoir une fiche validée
 * ({@see PortraitsDesComptes}). À l'activation — **toute** activation, recherche confirmée
 * comprise — elle passe sur la fiche et en devient le portrait. Au refus, elle est
 * supprimée avec le reste de la déclaration. L'administrateur la voit avant de décider :
 * sur la carte « À rattacher », et sur la ligne de l'écran Utilisateurs — la file des
 * membres (`GET /api/administration/membres`) porte `portraitEnAttente` pour cela.
 *
 * ## Une décision ne se prend qu'une fois — relue sous verrou
 *
 * Activer, refuser, « créer la fiche et rattacher » : trois décisions sur un compte
 * `en_attente`, que deux administrateurs (ou deux onglets du même) peuvent prendre en
 * même temps. Le `Member` chargé en début de requête peut être **périmé** au moment
 * d'écrire : un refus arrivé entre-temps serait défait sans bruit — le compte refusé,
 * rattaché et réactivé, sa purge comprise. D'où {@see relireEnAttenteSousVerrou()} : dans
 * la transaction, la ligne est relue de la base sous `PESSIMISTIC_WRITE` (PostgreSQL,
 * `SELECT … FOR UPDATE`) — la seconde décision attend la première, puis la voit, et rend
 * `409 compte_deja_decide`.
 */
final readonly class ServiceDesMembres
{
    public function __construct(
        private EntityManagerInterface $em,
        private MemberRepository $membres,
        private UserRepository $utilisateurs,
        private PortArbre $arbre,
        private CercleDeVisibiliteInterface $cercle,
        private NotificationDesAdministrateurs $notifications,
        private AuditService $audit,
        private PortraitsDesComptes $portraits,
        private StockageDesMessagesVocaux $messagesVocaux,
        private ConsentementDeNaissance $consentements,
    ) {
    }

    /**
     * @param array<string, mixed> $corps
     *
     * @throws NotFoundException   membre inconnu, ou compte disparu
     * @throws ConflictException   `personne_deja_rattachee`, ou `compte_deja_decide` si le
     *                             membre n'est plus en attente (activé ou refusé entre-temps)
     * @throws ValidationException `personId` absent (compte sans fiche), inexistant ou décédé
     */
    public function activer(int $memberId, array $corps, User $administrateur): Member
    {
        $membre = $this->membres->find($memberId);

        if (null === $membre) {
            throw new NotFoundException();
        }

        // Première lecture, **hors** transaction : le cas ordinaire — le compte a été refusé
        // par une requête précédente — rend son `409` sans fermer le gestionnaire
        // d'entités. La course, elle, est rattrapée sous verrou plus bas.
        self::exigerEnAttente($membre);

        $utilisateur = $this->utilisateurs->find($membre->getUserId());

        if (null === $utilisateur) {
            // Référence pendante vers `Core\User` — la contrepartie assumée de l'absence
            // de clé étrangère inter-module (ADR 0002). On refuse plutôt que d'activer un
            // rattachement vers un compte qui n'existe plus.
            throw new NotFoundException();
        }

        $personId = $corps['personId'] ?? null;

        if (null !== $personId && !\is_int($personId)) {
            throw new ValidationException(['personId' => 'Un entier est attendu.']);
        }

        if (null === $personId && null === $membre->getPersonId()) {
            // Un compte sans fiche n'a rien à « valider tel quel » : c'est précisément la
            // fiche qui manque. Le bouton « Valider » de la liste des utilisateurs ne
            // s'affiche pas pour lui ; cette garde tient si un client l'appelle quand même.
            throw new ValidationException(['personId' => 'Choisissez la fiche à laquelle rattacher ce compte.']);
        }

        // Les contrôles **hors** transaction : ils ne font que lever, et une exception dans
        // `wrapInTransaction()` ferme le gestionnaire d'entités — un simple `422` n'a pas à
        // coûter ça. Ils ne **modifient** plus l'entité : la relecture sous verrou qui suit
        // l'écraserait (`refresh()` rend la ligne de la base, pas l'objet en mémoire).
        $nouvelleFiche = null !== $personId ? $this->ficheDeRemplacement($membre, $personId) : null;

        // Le message vocal de la déclaration, lu **sous verrou** et avant la purge : la ligne
        // l'oublie dans la transaction, le fichier part après le commit.
        $nomDuVocal = null;

        $this->em->wrapInTransaction(function () use ($membre, $nouvelleFiche, $utilisateur, $administrateur, &$nomDuVocal): void {
            $this->relireEnAttenteSousVerrou($membre);
            $nomDuVocal = $membre->getVoiceMessageName();

            if (null !== $nouvelleFiche) {
                // `rattacherA()` et non une réflexion sur `personId` : le mutateur existe
                // depuis l'inscription sans fiche, et il purge au passage la déclaration.
                $membre->rattacherA($nouvelleFiche->id, $nouvelleFiche->libelle, MatchKind::ManuelAdmin);
                // ADR 0016 : la fiche a désormais un compte, son choix seul compte.
                $this->consentements->archiverPourRattachement($nouvelleFiche->id, $administrateur->getId());
            }

            $this->finaliserLActivation($membre, $utilisateur, $administrateur);
        });

        $this->messagesVocaux->supprimer($nomDuVocal);
        $this->notifications->compteActive($membre);

        return $membre;
    }

    /**
     * Rattache un compte `a_rattacher` à une fiche **créée dans la même transaction** et
     * l'active — l'étape finale de « Créer la fiche et rattacher » (contrat
     * `Membership\Contract\ComptesARattacher::rattacherAFicheCreee()`).
     *
     * ## Pourquoi on ne relit pas l'arbre
     *
     * `PortArbre::vue()` est **mémoïsée par requête** : la fiche que `Proposal` vient de
     * créer, dans cette même requête, n'y est pas. La relire rendrait « personne
     * inexistante » à coup sûr. L'appelant tient la fiche de la validation qu'il vient de
     * faire ; l'unicité (`uniq_mbr_member_person`) protège encore contre la double
     * réclamation, et on la vérifie d'abord pour rendre un `409` lisible.
     *
     * **N'ouvre pas de transaction** : l'appelant en tient une qui englobe la création de la
     * fiche, pour qu'une erreur ici annule aussi la demande validée. L'appelant a déjà
     * relu le compte sous verrou **avant** de créer la fiche ({@see verrouillerARattacher()}) ;
     * la relecture d'ici est la même, sous le même verrou — elle ne coûte qu'une requête et
     * ne laisse pas cette méthode dépendre de l'ordre de son appelant.
     *
     * @throws NotFoundException compte ou utilisateur disparu
     * @throws ConflictException `personne_deja_rattachee`, ou `compte_deja_decide`
     */
    public function activerSurFicheCreee(int $memberId, int $personId, string $personLabel, User $administrateur): Member
    {
        $membre = $this->verrouillerARattacher($memberId);

        $utilisateur = $this->utilisateurs->find($membre->getUserId());

        if (null === $utilisateur) {
            throw new NotFoundException();
        }

        $this->refuserSiDejaReclamee($membre, $personId);

        $nomDuVocal = $membre->getVoiceMessageName();
        $membre->rattacherA($personId, $personLabel, MatchKind::ManuelAdmin);
        $this->consentements->archiverPourRattachement($personId, $administrateur->getId());
        $this->finaliserLActivation($membre, $utilisateur, $administrateur);
        // La transaction est celle de l'appelant, et aucun crochet ne dit ici quand elle
        // sera validée : le fichier part tout de suite. Si elle échouait ensuite, la ligne
        // reviendrait avec un nom sans fichier — la route d'écoute rend alors un `404`, et
        // rien d'autre ne le lit. L'inverse (un vocal survivant à un rattachement réussi)
        // garderait une voix de vivant sans raison : c'est le pire des deux.
        $this->messagesVocaux->supprimer($nomDuVocal);
        $this->notifications->compteActive($membre);

        return $membre;
    }

    /**
     * Relit le compte **sous verrou**, dans la transaction de l'appelant, et exige qu'il
     * soit encore `a_rattacher` **et** `en_attente` — le premier pas de « Créer la fiche
     * et rattacher », fait **avant** que `Proposal` crée la fiche : un compte refusé
     * entre-temps ne doit pas laisser derrière lui une fiche que personne n'a voulue.
     *
     * Le verrou tient jusqu'à la fin de la transaction englobante : un refus concurrent
     * attend que la fiche soit créée et le compte activé, puis rend son propre `409`.
     *
     * @throws NotFoundException compte inconnu
     * @throws ConflictException `compte_deja_decide`
     */
    public function verrouillerARattacher(int $memberId): Member
    {
        $membre = $this->membres->find($memberId) ?? throw new NotFoundException();

        $this->relireEnAttenteSousVerrou($membre);

        if (!$membre->estARattacher()) {
            // En attente, mais avec une fiche : un autre chemin l'a rattaché sans l'activer
            // — rien qui existe aujourd'hui, mais créer une seconde fiche ferait un doublon.
            throw new ConflictException('compte_deja_decide', 'Ce compte a déjà été rattaché à une fiche.');
        }

        return $membre;
    }

    /**
     * **Refuser une inscription** — `POST /api/administration/membres/{id}/refuser`.
     *
     * ## Suspendre, et non supprimer
     *
     * `Core` n'a aucun chemin de suppression de compte, et `core_user` est référencé par le
     * journal d'audit, les notifications et les sessions : supprimer ouvrirait un chantier
     * dans un module qui n'est pas celui-ci. La suspension, elle, existe déjà et fait tout
     * ce qu'il faut — `UserChecker` refuse la connexion (`401 compte_suspendu`), la
     * décision est tracée — et **les données personnelles partent quand même** :
     * déclaration, suggestion et photo en attente sont purgées. La contrepartie assumée :
     * le courriel reste réservé (ADR 0013).
     *
     * Vaut pour tout compte `en_attente`, `a_rattacher` compris : refuser une recherche
     * confirmée qui s'est trompée de personne est la même décision.
     *
     * @throws NotFoundException membre ou compte inconnu
     * @throws ConflictException `compte_deja_decide` si le membre n'est plus en attente
     */
    public function refuser(int $memberId, User $administrateur): Member
    {
        $membre = $this->membres->find($memberId);

        if (null === $membre) {
            throw new NotFoundException();
        }

        self::exigerEnAttente($membre);

        $utilisateur = $this->utilisateurs->find($membre->getUserId());

        if (null === $utilisateur) {
            throw new NotFoundException();
        }

        $nomDuVocal = null;

        $effacerLaPhoto = $this->em->wrapInTransaction(function () use ($membre, $utilisateur, $administrateur, &$nomDuVocal): \Closure {
            // Le même verrou que l'activation : deux décisions croisées se sérialisent, et
            // la seconde voit la première.
            $this->relireEnAttenteSousVerrou($membre);
            $nomDuVocal = $membre->getVoiceMessageName();

            $membre->setStatus(MemberStatus::Suspendu)->purgerLaDeclaration();
            $utilisateur->setStatus(UserStatus::Suspendu)->toucher();

            // La ligne de la photo part avec le refus ; ses fichiers, après le commit.
            $effacer = $this->portraits->ecarter($membre->getUserId());

            $this->audit->enregistrer(
                AuditAction::CompteSuspendu,
                Member::class,
                (string) $membre->getId(),
                // Ni libellé de personne ni identité déclarée : le refus ne garde rien de
                // ce que le candidat avait écrit.
                null,
                [
                    'memberId' => $membre->getId(),
                    'matchKind' => $membre->getMatchKind()->value,
                ],
                $administrateur,
            );

            $this->em->flush();

            return $effacer;
        });

        // Le refus est validé : les fichiers peuvent partir. S'il avait échoué, l'exception
        // serait déjà remontée, et la photo serait encore là — ligne **et** fichier.
        $effacerLaPhoto();
        // Le message vocal de la déclaration, de même : la ligne l'a oublié, le fichier suit.
        $this->messagesVocaux->supprimer($nomDuVocal);

        return $membre;
    }

    /**
     * La file d'activation — les rattachements que personne n'a encore confirmés.
     *
     * @return array<int, Member>
     */
    public function enAttente(): array
    {
        return $this->membres->enAttente();
    }

    /**
     * La charge JSON d'un membre, pour la file d'administration (§ 10.1).
     *
     * `personId` et `personLabel` traversent le cercle, comme partout ailleurs : ce sont
     * des colonnes `*_label` et `*_id` désignant une personne potentiellement vivante
     * (§ 11.3). Ici le lecteur est administrateur, donc il les voit — mais sous la bascule
     * « voir comme un membre », c'est le cercle emprunté qui décide, et c'est le jour où ce
     * filtre sert.
     *
     * Un compte `a_rattacher` n'a pas de fiche : les deux clés sont **absentes**, pas
     * nulles — la même forme que « le cercle ne la laisse pas voir », et c'est voulu : le
     * front n'a qu'un cas à traiter.
     *
     * @return array<string, mixed>
     */
    public function normaliser(Member $membre): array
    {
        $charge = [
            'id' => $membre->getId(),
            'status' => $membre->getStatus()->value,
            'matchKind' => $membre->getMatchKind()->value,
            'invitationId' => $membre->getInvitation()?->getId(),
            'linkedAt' => $membre->getLinkedAt()->format(\DateTimeInterface::ATOM),
            'activatedAt' => $membre->getActivatedAt()?->format(\DateTimeInterface::ATOM),
        ];

        $personId = $membre->getPersonId();

        if (null !== $personId && $this->cercle->voitPersonne($personId)) {
            $charge['personId'] = $personId;
            $charge['personLabel'] = $membre->getPersonLabel();
        }

        return $charge;
    }

    /**
     * Ce que fait **toute** activation, une fois la fiche posée : les deux statuts,
     * la photo de l'inscription, le journal. Sans transaction ni notification — les deux
     * appelants les placent chacun à leur façon.
     */
    private function finaliserLActivation(Member $membre, User $utilisateur, User $administrateur): void
    {
        $personId = $membre->getPersonId();
        $personLabel = $membre->getPersonLabel();
        \assert(null !== $personId && null !== $personLabel);

        $administrateurId = $administrateur->getId();
        \assert(null !== $administrateurId);

        $membre->activer($administrateurId);
        $utilisateur->setStatus(UserStatus::Actif)->toucher();

        // Vidé **avant** de déplacer la photo : `valider()` côté `Media` ouvre sa propre
        // transaction et vide l'unité de travail — autant que le rattachement y soit déjà.
        $this->em->flush();

        $this->portraits->rattacherALaFiche($membre->getUserId(), $personId, $personLabel);

        $this->audit->enregistrer(
            AuditAction::Modification,
            Member::class,
            (string) $membre->getId(),
            // `subject_label` reste nul : c'est un libellé de personne, soumis à la porte
            // de sortie n° 8 comme le reste (§ 10.3, § 11.3).
            null,
            [
                'status' => MemberStatus::Actif->value,
                'userId' => $membre->getUserId(),
                'matchKind' => $membre->getMatchKind()->value,
            ],
            $administrateur,
        );

        $this->em->flush();
    }

    /**
     * Relit la ligne de la base **sous `PESSIMISTIC_WRITE`** et exige qu'elle soit encore
     * `en_attente`. À appeler dans une transaction — sans elle, le verrou tomberait aussitôt
     * pris. Lever ici ferme le gestionnaire d'entités : c'est le prix, rare, d'une course
     * réelle ; le cas ordinaire est déjà tombé sur {@see exigerEnAttente()}, hors transaction.
     *
     * @throws ConflictException `compte_deja_decide`
     */
    private function relireEnAttenteSousVerrou(Member $membre): void
    {
        $this->em->refresh($membre, LockMode::PESSIMISTIC_WRITE);

        self::exigerEnAttente($membre);
    }

    /** @throws ConflictException `compte_deja_decide` si le membre a déjà été activé ou refusé */
    private static function exigerEnAttente(Member $membre): void
    {
        if (MemberStatus::EnAttente !== $membre->getStatus()) {
            throw new ConflictException('compte_deja_decide', 'Ce compte a déjà été activé ou refusé.');
        }
    }

    /**
     * La fiche qui remplacera le rattachement actuel — ou `null` si c'est la même. Les
     * contrôles de l'étape 2 sont rejoués : la personne doit exister dans l'arbre (mesure 1
     * de la compensation de l'ADR 0002), être **vivante** — un compte ancré sur un défunt
     * n'a pas de sens, et la recherche ne les propose plus (ADR 0013) — et ne pas être déjà
     * réclamée (`uniq_mbr_member_person`).
     *
     * Ne modifie rien : le rattachement se pose dans la transaction, après la relecture
     * sous verrou.
     */
    private function ficheDeRemplacement(Member $membre, int $personId): ?PersonneDeLArbre
    {
        if ($personId === $membre->getPersonId()) {
            return null;
        }

        $personne = $this->arbre->vue()->personne($personId);

        if (null === $personne) {
            throw new ValidationException(['personId' => 'Cette personne n\'existe pas dans l\'arbre.']);
        }

        if (!$personne->vivante) {
            throw new ValidationException(['personId' => 'Cette personne est décédée.']);
        }

        $this->refuserSiDejaReclamee($membre, $personId);

        return $personne;
    }

    private function refuserSiDejaReclamee(Member $membre, int $personId): void
    {
        $occupant = $this->membres->pourPersonne($personId);

        if (null !== $occupant && $occupant->getId() !== $membre->getId()) {
            throw new ConflictException('personne_deja_rattachee', 'Cette personne est déjà rattachée à un compte.');
        }
    }
}
