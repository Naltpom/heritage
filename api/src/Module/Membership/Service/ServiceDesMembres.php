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
use App\Module\Membership\Entity\Member;
use App\Module\Membership\Enum\MatchKind;
use App\Module\Membership\Enum\MemberStatus;
use App\Module\Membership\Repository\MemberRepository;
use App\Module\Membership\Service\Port\PortArbre;
use Doctrine\ORM\EntityManagerInterface;

/**
 * L'activation d'un membre par un administrateur —
 * `POST /api/administration/membres/{id}/activer` (§ 10.1, § 12.3 du contrat).
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
 * ## `personId` facultatif corrige un rattachement erroné
 *
 * Et fait alors passer `match_kind` à `manuel_admin` : la trace de *comment* le
 * rattachement a été établi ne doit pas continuer d'annoncer une recherche que
 * l'administrateur vient précisément de corriger.
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
    ) {
    }

    /**
     * @param array<string, mixed> $corps
     *
     * @throws NotFoundException   membre inconnu, ou compte disparu
     * @throws ConflictException   `personne_deja_rattachee`
     * @throws ValidationException `personId` inexistant dans l'arbre
     */
    public function activer(int $memberId, array $corps, User $administrateur): Member
    {
        $membre = $this->membres->find($memberId);

        if (null === $membre) {
            throw new NotFoundException();
        }

        $utilisateur = $this->utilisateurs->find($membre->getUserId());

        if (null === $utilisateur) {
            // Référence pendante vers `Core\User` — la contrepartie assumée de l'absence
            // de clé étrangère inter-module (ADR 0002). On refuse plutôt que d'activer un
            // rattachement vers un compte qui n'existe plus.
            throw new NotFoundException();
        }

        $personId = $corps['personId'] ?? null;

        if (null !== $personId) {
            if (!\is_int($personId)) {
                throw new ValidationException(['personId' => 'Un entier est attendu.']);
            }

            $this->rattacherAutrement($membre, $personId);
        }

        $administrateurId = $administrateur->getId();
        \assert(null !== $administrateurId);

        $membre->activer($administrateurId);
        $utilisateur->setStatus(UserStatus::Actif)->toucher();

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

        $this->notifications->compteActive($membre);

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

        if ($this->cercle->voitPersonne($membre->getPersonId())) {
            $charge['personId'] = $membre->getPersonId();
            $charge['personLabel'] = $membre->getPersonLabel();
        }

        return $charge;
    }

    /**
     * Corrige le rattachement au passage. Les deux contrôles de l'étape 2 sont rejoués :
     * la personne doit exister dans l'arbre (mesure 1 de la compensation de l'ADR 0002) et
     * ne pas être déjà réclamée (`uniq_mbr_member_person`).
     */
    private function rattacherAutrement(Member $membre, int $personId): void
    {
        if ($personId === $membre->getPersonId()) {
            return;
        }

        $personne = $this->arbre->vue()->personne($personId);

        if (null === $personne) {
            throw new ValidationException(['personId' => 'Cette personne n\'existe pas dans l\'arbre.']);
        }

        $occupant = $this->membres->pourPersonne($personId);

        if (null !== $occupant && $occupant->getId() !== $membre->getId()) {
            throw new ConflictException('personne_deja_rattachee', 'Cette personne est déjà rattachée à un compte.');
        }

        // L'entité n'expose pas de mutateur pour `person_id` : le rattachement est censé
        // se poser une fois. On passe donc par la réflexion plutôt que d'ouvrir un
        // `setPersonId()` public que n'importe quel service pourrait appeler — le § 4.2 du
        // modèle fait de cette colonne le point d'ancrage du cercle, et elle ne doit
        // changer que sur décision explicite d'un administrateur, ici.
        $champ = new \ReflectionProperty(Member::class, 'personId');
        $champ->setValue($membre, $personId);

        $membre->setPersonLabel($personne->libelle)->setMatchKind(MatchKind::ManuelAdmin)->toucher();
    }
}
