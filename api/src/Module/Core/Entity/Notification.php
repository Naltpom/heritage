<?php

declare(strict_types=1);

namespace App\Module\Core\Entity;

use App\Module\Core\Enum\NotificationKind;
use App\Module\Core\Repository\NotificationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Notification en base, lue par une cloche dans l'interface (§ 2.5 du modèle).
 *
 * Pas de file d'attente, pas de Redis (§ 3 du dossier de mission) : l'envoi de courriel
 * est synchrone, la notification en base est la source de vérité.
 *
 * C'est ici qu'atterrit le **message obligatoire de refus** du § 6.3 du dossier :
 * l'auteur d'une demande refusée le lit dans sa notification, pas ailleurs.
 */
#[ORM\Entity(repositoryClass: NotificationRepository::class)]
#[ORM\Table(name: 'core_notification')]
#[ORM\Index(name: 'idx_core_notif_destinataire', columns: ['recipient_user_id', 'created_at'])]
// Index partiel : le compteur de la cloche est appelé à **chaque** chargement de page, et
// seules les non-lues comptent — elles sont une poignée.
#[ORM\Index(
    name: 'idx_core_notif_non_lues',
    columns: ['recipient_user_id'],
    options: ['where' => '(read_at IS NULL)'],
)]
class Notification
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** ⇢ inter-module (`core_user`). */
    #[ORM\Column]
    private int $recipientUserId;

    #[ORM\Column(length: 32, enumType: NotificationKind::class)]
    private NotificationKind $kind;

    /**
     * Titre en français, **déjà rendu** : la notification ne se recompose pas à la
     * lecture, sinon un changement de libellé réécrirait l'histoire.
     */
    #[ORM\Column(length: 160)]
    private string $title;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $body = null;

    /**
     * Nom de route front visée (`demande.detail`) plutôt qu'une URL construite : une
     * refonte du routage front ne casse pas les notifications d'hier.
     */
    #[ORM\Column(length: 120, nullable: true)]
    private ?string $linkRoute = null;

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: Types::JSONB, nullable: true)]
    private ?array $linkParams = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $readAt = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /** @param array<string, mixed>|null $linkParams */
    public function __construct(
        int $recipientUserId,
        NotificationKind $kind,
        string $title,
        ?string $body = null,
        ?string $linkRoute = null,
        ?array $linkParams = null,
    ) {
        $this->recipientUserId = $recipientUserId;
        $this->kind = $kind;
        $this->title = $title;
        $this->body = $body;
        $this->linkRoute = $linkRoute;
        $this->linkParams = $linkParams;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRecipientUserId(): int
    {
        return $this->recipientUserId;
    }

    public function getKind(): NotificationKind
    {
        return $this->kind;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getBody(): ?string
    {
        return $this->body;
    }

    public function getLinkRoute(): ?string
    {
        return $this->linkRoute;
    }

    /** @return array<string, mixed>|null */
    public function getLinkParams(): ?array
    {
        return $this->linkParams;
    }

    public function getReadAt(): ?\DateTimeImmutable
    {
        return $this->readAt;
    }

    public function marquerLue(?\DateTimeImmutable $le = null): self
    {
        $this->readAt ??= $le ?? new \DateTimeImmutable();

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
