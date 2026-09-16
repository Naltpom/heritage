<?php

declare(strict_types=1);

namespace App\Module\Membership\Entity;

use App\Module\Membership\Enum\MatchKind;
use App\Module\Membership\Enum\MemberStatus;
use App\Module\Membership\Repository\MemberRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Le rattachement compte ↔ personne (§ 4.2 du modèle de données).
 *
 * Le § 2 du dossier de mission appelle cette entité `Membership\Account` ; elle s'appelle
 * `Member` parce que **`Core\User` est déjà le compte** — il porte le courriel, le mot
 * de passe, les rôles et implémente `UserInterface`. Deux « comptes » dans deux modules
 * garantiraient qu'un agent aille chercher le mot de passe dans `mbr_account`. Ce que
 * cette table apporte est le *rattachement*. L'écart est argumenté dans
 * `docs/arbitrages.md` (point H).
 *
 * C'est le point d'ancrage du cercle de visibilité (§ 9.3) : `person_id` est la
 * personne `u` de la formule.
 */
#[ORM\Entity(repositoryClass: MemberRepository::class)]
#[ORM\Table(name: 'mbr_member')]
// « Un compte est rattaché à **une** personne » (§ 6.1 du dossier). La base le garantit,
// pas le code.
#[ORM\UniqueConstraint(name: 'uniq_mbr_member_user', columns: ['user_id'])]
// « Une personne ne peut être réclamée que par **un** compte ». Sans cette contrainte,
// deux invitations envoyées par erreur à la même personne créent deux comptes qui se
// voient l'un l'autre comme soi-même.
#[ORM\UniqueConstraint(name: 'uniq_mbr_member_person', columns: ['person_id'])]
#[ORM\Index(
    name: 'idx_mbr_member_status',
    columns: ['status'],
    options: ['where' => "((status)::text = 'en_attente'::text)"],
)]
class Member
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** ⇢ inter-module (`Core\User`). */
    #[ORM\Column]
    private int $userId;

    /** Libellé dénormalisé : affiche la liste des membres sans charger `Core`. */
    #[ORM\Column(length: 180)]
    private string $userEmail;

    /** ⇢ inter-module (`Tree\Person`). La personne de l'arbre que ce compte **est**. */
    #[ORM\Column]
    private int $personId;

    /**
     * Libellé dénormalisé, rafraîchi par le service `Tree` quand le nom d'affichage
     * change. Sortie de données personnelles : filtrée comme la personne (§ 0.4).
     */
    #[ORM\Column(length: 255)]
    private string $personLabel;

    #[ORM\Column(length: 12, enumType: MemberStatus::class, options: ['default' => 'en_attente'])]
    private MemberStatus $status = MemberStatus::EnAttente;

    /** FK **intra-module**. `null` pour le compte d'amorçage créé en console. */
    #[ORM\ManyToOne(targetEntity: Invitation::class)]
    #[ORM\JoinColumn(name: 'invitation_id', nullable: true, onDelete: 'SET NULL')]
    private ?Invitation $invitation = null;

    // 24 et non 16 : `recherche_confirmee` fait dix-neuf signes. C'est le cas du
    // rattachement sans pré-lien — celui qui laisse le compte en attente d'activation,
    // donc précisément celui qu'on n'exerce pas en développement.
    #[ORM\Column(length: 24, enumType: MatchKind::class)]
    private MatchKind $matchKind;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $linkedAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $activatedAt = null;

    /** ⇢ inter-module (`Core\User`). `null` si l'activation était automatique. */
    #[ORM\Column(nullable: true)]
    private ?int $activatedByUserId = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        int $userId,
        string $userEmail,
        int $personId,
        string $personLabel,
        MatchKind $matchKind,
    ) {
        $this->userId = $userId;
        $this->userEmail = $userEmail;
        $this->personId = $personId;
        $this->personLabel = $personLabel;
        $this->matchKind = $matchKind;
        $this->linkedAt = new \DateTimeImmutable();
        $this->createdAt = $this->linkedAt;
        $this->updatedAt = $this->linkedAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUserId(): int
    {
        return $this->userId;
    }

    public function getUserEmail(): string
    {
        return $this->userEmail;
    }

    public function setUserEmail(string $userEmail): self
    {
        $this->userEmail = $userEmail;

        return $this;
    }

    public function getPersonId(): int
    {
        return $this->personId;
    }

    public function getPersonLabel(): string
    {
        return $this->personLabel;
    }

    public function setPersonLabel(string $personLabel): self
    {
        $this->personLabel = $personLabel;

        return $this;
    }

    public function getStatus(): MemberStatus
    {
        return $this->status;
    }

    public function setStatus(MemberStatus $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function getInvitation(): ?Invitation
    {
        return $this->invitation;
    }

    public function setInvitation(?Invitation $invitation): self
    {
        $this->invitation = $invitation;

        return $this;
    }

    public function getMatchKind(): MatchKind
    {
        return $this->matchKind;
    }

    public function setMatchKind(MatchKind $matchKind): self
    {
        $this->matchKind = $matchKind;

        return $this;
    }

    public function getLinkedAt(): \DateTimeImmutable
    {
        return $this->linkedAt;
    }

    public function getActivatedAt(): ?\DateTimeImmutable
    {
        return $this->activatedAt;
    }

    public function getActivatedByUserId(): ?int
    {
        return $this->activatedByUserId;
    }

    /** `$parUserId` reste nul quand l'activation est automatique (§ 4.5, `MatchKind`). */
    public function activer(?int $parUserId = null, ?\DateTimeImmutable $le = null): self
    {
        $this->status = MemberStatus::Actif;
        $this->activatedAt = $le ?? new \DateTimeImmutable();
        $this->activatedByUserId = $parUserId;
        $this->updatedAt = $this->activatedAt;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function toucher(): self
    {
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }
}
