<?php

declare(strict_types=1);

namespace App\Module\Core\Entity;

use App\Module\Core\Repository\PushSubscriptionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Un appareil qui accepte les notifications **application fermée** (Web Push).
 *
 * Le navigateur du téléphone s'abonne auprès de SON service push (Google, Mozilla,
 * Apple) et nous rend trois choses : l'`endpoint`, une URL à laquelle on POSTe pour
 * réveiller le téléphone, et deux clés (`p256dh`, `auth`) avec lesquelles on chiffre la
 * charge pour que le service push lui-même ne la lise pas (RFC 8291).
 *
 * ## L'endpoint est l'identité de l'abonnement, pas le compte
 *
 * Un même téléphone peut servir à deux comptes l'un après l'autre (le téléphone de la
 * maison). Quand le second s'abonne, le navigateur rend **le même endpoint** : la ligne
 * est alors **réattribuée** au compte courant, et le premier cesse de recevoir des
 * notifications qui s'afficheraient chez quelqu'un d'autre. D'où l'unicité sur
 * `endpoint`, et non sur le couple (compte, endpoint).
 *
 * ## Une vraie clé étrangère vers `core_user`, en `ON DELETE CASCADE`
 *
 * Contrairement à `core_notification` (identifiant nu), la référence au compte est ici
 * une association : les deux tables sont du même module, l'ADR 0002 ne s'y oppose pas,
 * et un compte supprimé ne doit laisser **aucun** appareil derrière lui — un endpoint
 * orphelin est une adresse où l'on pourrait encore pousser un titre de notification.
 *
 * `p256dh` et `auth` sont des secrets de chiffrement : ils sont masqués dans le journal
 * d'audit (`AuditDoctrineSubscriber::CHAMPS_SENSIBLES`).
 */
#[ORM\Entity(repositoryClass: PushSubscriptionRepository::class)]
#[ORM\Table(name: 'core_push_subscription')]
#[ORM\UniqueConstraint(name: 'uniq_core_push_subscription_endpoint', columns: ['endpoint'])]
// « Les appareils de ce compte » — la seule question posée à chaque notification.
#[ORM\Index(name: 'idx_core_push_subscription_user', columns: ['user_id'])]
class PushSubscription
{
    /**
     * Longueur maximale acceptée d'un endpoint. Les services connus rendent entre 150 et
     * 500 signes ; 2048 laisse de la marge sans ouvrir la porte à un corps démesuré.
     */
    public const int ENDPOINT_MAX = 2048;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: self::ENDPOINT_MAX)]
    private string $endpoint;

    /** Clé publique P-256 du navigateur, base64url (65 octets décodés). */
    #[ORM\Column(length: 255)]
    private string $p256dh;

    /** Secret d'authentification du navigateur, base64url (16 octets décodés). */
    #[ORM\Column(length: 255)]
    private string $auth;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /**
     * Dernière remise acceptée par le service push. Écrite par une requête directe, hors
     * unité de travail : une notification n'a pas à laisser une ligne d'audit par envoi.
     */
    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastUsedAt = null;

    public function __construct(User $user, string $endpoint, string $p256dh, string $auth)
    {
        $this->user = $user;
        $this->endpoint = $endpoint;
        $this->p256dh = $p256dh;
        $this->auth = $auth;
        $this->createdAt = new \DateTimeImmutable();
    }

    /**
     * Le navigateur a renouvelé ses clés, ou un autre compte s'est connecté sur le même
     * appareil : on reprend la ligne telle que le navigateur la décrit maintenant.
     */
    public function reattribuer(User $user, string $p256dh, string $auth): self
    {
        $this->user = $user;
        $this->p256dh = $p256dh;
        $this->auth = $auth;

        return $this;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getEndpoint(): string
    {
        return $this->endpoint;
    }

    public function getP256dh(): string
    {
        return $this->p256dh;
    }

    public function getAuth(): string
    {
        return $this->auth;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getLastUsedAt(): ?\DateTimeImmutable
    {
        return $this->lastUsedAt;
    }
}
