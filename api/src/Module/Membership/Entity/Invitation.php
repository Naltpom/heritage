<?php

declare(strict_types=1);

namespace App\Module\Membership\Entity;

use App\Module\Membership\Enum\InvitationStatus;
use App\Module\Membership\Repository\InvitationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Un lien d'invitation (§ 4.1 du modèle de données).
 *
 * C'est l'objet le plus exposé du produit : le § 6.1 du dossier de mission désigne le
 * lien d'invitation comme « le principal risque », parce qu'un lien mal gardé devient un
 * oracle permettant d'énumérer les personnes **vivantes** de l'arbre.
 *
 * **Le jeton en clair n'est jamais stocké.** Il n'existe qu'en mémoire à la création, le
 * temps d'être affiché à l'administrateur et envoyé par courriel. Un administrateur qui
 * perd le lien révoque et recrée ; il ne peut pas le relire, et c'est voulu.
 *
 * Le § 6.1 impose de répondre **404** sur un jeton invalide, expiré ou révoqué, et non
 * 403 : un 403 confirmerait que la route existe. Ce n'est pas une contrainte de schéma,
 * mais c'est la raison d'être de `token_hash` et il faut la lire ici pour ne pas la
 * perdre en route.
 */
#[ORM\Entity(repositoryClass: InvitationRepository::class)]
#[ORM\Table(name: 'mbr_invitation')]
// **L'index critique.** Chaque arrivée sur le lien fait exactement une requête
// `WHERE token_hash = ?`. Un défaut d'index se paierait en temps de réponse mesurable,
// donc en canal auxiliaire : un jeton valide répondrait plus lentement qu'un invalide.
#[ORM\UniqueConstraint(name: 'uniq_mbr_invitation_token', columns: ['token_hash'])]
#[ORM\Index(name: 'idx_mbr_invitation_status', columns: ['status', 'created_at'])]
// « Une invitation est-elle déjà en cours pour cette personne ? » — posée avant d'en
// créer une, pour éviter deux liens concurrents vers la même fiche.
#[ORM\Index(
    name: 'idx_mbr_invitation_person',
    columns: ['person_id'],
    options: ['where' => '(person_id IS NOT NULL)'],
)]
class Invitation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** `hash('sha256', $jeton)` en hexadécimal. Jamais le jeton lui-même. */
    #[ORM\Column(length: 64, options: ['fixed' => true])]
    private string $tokenHash;

    /**
     * Les 8 premiers caractères du jeton en clair. Sert **uniquement** à distinguer deux
     * invitations dans la liste d'administration (« invitation `a3f91c2d…` ») : 8
     * caractères sur 64, c'est trop peu pour reconstruire quoi que ce soit.
     */
    #[ORM\Column(length: 8, options: ['fixed' => true])]
    private string $tokenPrefix;

    /**
     * ⇢ inter-module (`Tree\Person`). Le **pré-lien** du § 6.1 du dossier :
     * l'administrateur désigne à l'avance la personne de l'arbre. C'est le cas nominal,
     * et c'est ce qui permet au candidat de simplement confirmer sa date de naissance au
     * lieu de déclencher une recherche floue.
     */
    #[ORM\Column(nullable: true)]
    private ?int $personId = null;

    /**
     * Libellé dénormalisé.
     *
     * **C'est une sortie de données personnelles** : cette colonne ne traverse pas le
     * normaliseur de `Tree\Person`, c'est-à-dire l'endroit unique où le cercle de
     * visibilité est écrit. Règle (§ 0.4) : toute colonne `*_label` désignant une
     * personne est **filtrée comme la personne elle-même**.
     */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $personLabel = null;

    /** `null` quand l'administrateur copie le lien à la main — les deux chemins doivent exister. */
    #[ORM\Column(length: 180, nullable: true)]
    private ?string $email = null;

    #[ORM\Column(length: 12, enumType: InvitationStatus::class, options: ['default' => 'active'])]
    private InvitationStatus $status = InvitationStatus::Active;

    /** Échéance, calculée à la création depuis `invitation.ttl_jours` (7 par défaut). */
    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $expiresAt;

    /** Usage **unique** : dès qu'un compte naît du jeton, la colonne est posée. */
    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $usedAt = null;

    /** ⇢ inter-module (`Core\User`). */
    #[ORM\Column(nullable: true)]
    private ?int $usedByUserId = null;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $usedByEmail = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    /** ⇢ inter-module (`Core\User`). */
    #[ORM\Column(nullable: true)]
    private ?int $revokedByUserId = null;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $revokedByEmail = null;

    /** ⇢ inter-module (`Core\User`). Traçabilité exigée au § 6.1 du dossier. */
    #[ORM\Column]
    private int $createdByUserId;

    #[ORM\Column(length: 180)]
    private string $createdByEmail;

    /** Aide-mémoire de l'administrateur (« pour la cousine de Saule »). */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $note = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        string $tokenHash,
        string $tokenPrefix,
        \DateTimeImmutable $expiresAt,
        int $createdByUserId,
        string $createdByEmail,
    ) {
        $this->tokenHash = $tokenHash;
        $this->tokenPrefix = $tokenPrefix;
        $this->expiresAt = $expiresAt;
        $this->createdByUserId = $createdByUserId;
        $this->createdByEmail = $createdByEmail;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTokenHash(): string
    {
        return $this->tokenHash;
    }

    public function getTokenPrefix(): string
    {
        return $this->tokenPrefix;
    }

    public function getPersonId(): ?int
    {
        return $this->personId;
    }

    public function getPersonLabel(): ?string
    {
        return $this->personLabel;
    }

    public function setPerson(?int $personId, ?string $personLabel): self
    {
        $this->personId = $personId;
        $this->personLabel = $personLabel;

        return $this;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): self
    {
        $this->email = $email;

        return $this;
    }

    public function getStatus(): InvitationStatus
    {
        return $this->status;
    }

    public function setStatus(InvitationStatus $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function getUsedAt(): ?\DateTimeImmutable
    {
        return $this->usedAt;
    }

    public function getUsedByUserId(): ?int
    {
        return $this->usedByUserId;
    }

    public function getUsedByEmail(): ?string
    {
        return $this->usedByEmail;
    }

    /**
     * L'horodatage et l'auteur se posent ensemble : `ck_mbr_invitation_usage` refuse en
     * base un `used_at` sans `used_by_user_id`, et réciproquement.
     */
    public function marquerUtilisee(int $userId, string $email, ?\DateTimeImmutable $le = null): self
    {
        $this->usedAt = $le ?? new \DateTimeImmutable();
        $this->usedByUserId = $userId;
        $this->usedByEmail = $email;
        $this->status = InvitationStatus::Utilisee;

        return $this;
    }

    public function getRevokedAt(): ?\DateTimeImmutable
    {
        return $this->revokedAt;
    }

    public function getRevokedByUserId(): ?int
    {
        return $this->revokedByUserId;
    }

    public function getRevokedByEmail(): ?string
    {
        return $this->revokedByEmail;
    }

    public function revoquer(int $userId, string $email, ?\DateTimeImmutable $le = null): self
    {
        $this->revokedAt = $le ?? new \DateTimeImmutable();
        $this->revokedByUserId = $userId;
        $this->revokedByEmail = $email;
        $this->status = InvitationStatus::Revoquee;

        return $this;
    }

    public function getCreatedByUserId(): int
    {
        return $this->createdByUserId;
    }

    public function getCreatedByEmail(): string
    {
        return $this->createdByEmail;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function setNote(?string $note): self
    {
        $this->note = $note;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
