<?php

declare(strict_types=1);

namespace App\Module\Core\Entity;

use App\Module\Core\Repository\PasskeyRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Une **clé d'accès** (passkey, WebAuthn) : Face ID, Touch ID, l'empreinte d'un
 * téléphone Android, Windows Hello. Voir `docs/securite-comptes.md` § « Clés d'accès ».
 *
 * ## Ce que cette table garde, et ce qu'elle ne garde pas
 *
 * **Uniquement la moitié publique.** La clé privée naît dans l'appareil et n'en sort
 * jamais : à la connexion, l'appareil signe un défi et nous rendons la signature à la
 * clé publique gardée ici. Une fuite de cette table ne permet donc **pas** de se
 * connecter — c'est toute la différence avec une table de mots de passe, et la raison
 * d'être de la fonctionnalité.
 *
 * - `credential_id` : l'identifiant que l'appareil donne à la clé, en base64url. C'est
 *   lui qui revient à chaque connexion et qui désigne la ligne — d'où son unicité.
 * - `public_key` : la clé publique au format COSE, en base64url.
 * - `sign_count` : le compteur de signatures de l'authentificateur. Un compteur qui
 *   **recule** trahit un clone de la clé ; la connexion est alors refusée. Beaucoup
 *   d'appareils (Apple, Google) le laissent à zéro : zéro ne recule pas, il n'est pas
 *   contrôlé.
 * - `aaguid` : le modèle d'authentificateur (« Trousseau iCloud », « Gestionnaire de
 *   mots de passe Google »…) — sert seulement à nommer la clé dans « Mon compte ».
 *
 * `BIGINT` pour le compteur : c'est un entier non signé de 32 bits, qui dépasse la
 * borne d'un `INT` PostgreSQL.
 *
 * ## Le journal d'audit
 *
 * `publicKey` et `credentialId` sont dans `AuditDoctrineSubscriber::CHAMPS_SENSIBLES` :
 * rien de secret, mais aucune raison de recopier du matériel de clé dans un journal
 * qu'on ne purge jamais. `signCount` et `lastUsedAt` sont des champs **techniques** :
 * chaque connexion les avance, et une ligne « modification » à côté de chaque ligne
 * « connexion » rendrait le journal illisible.
 *
 * ## Une vraie clé étrangère vers `core_user`, en `ON DELETE CASCADE`
 *
 * Même raisonnement que {@see PushSubscription} : même module, et un compte supprimé
 * ne doit laisser **aucune** clé qui ouvrirait encore une session.
 */
#[ORM\Entity(repositoryClass: PasskeyRepository::class)]
#[ORM\Table(name: 'core_passkey')]
#[ORM\UniqueConstraint(name: 'uniq_core_passkey_credential_id', columns: ['credential_id'])]
// « Les clés de ce compte » — Mon compte, et `excludeCredentials` à l'enregistrement.
#[ORM\Index(name: 'idx_core_passkey_user', columns: ['user_id'])]
class Passkey
{
    /**
     * Un identifiant de clé fait au plus 1023 octets (WebAuthn niveau 3, § 5.8.3), soit
     * 1364 signes en base64url. 1400 laisse la marge, sans ouvrir la porte au démesuré.
     */
    public const int CREDENTIAL_ID_MAX = 1400;

    /** Le nom qu'on lit dans « Mon compte » : court, c'est une étiquette. */
    public const int NOM_MAX = 80;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: self::CREDENTIAL_ID_MAX)]
    private string $credentialId;

    /** Clé publique COSE, base64url. */
    #[ORM\Column(type: Types::TEXT)]
    private string $publicKey;

    #[ORM\Column(type: Types::BIGINT)]
    private int $signCount;

    /**
     * Les canaux que l'appareil déclare (`internal`, `hybrid`, `usb`…), rendus au
     * navigateur dans `excludeCredentials`.
     *
     * @var list<string>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $transports;

    /** UUID du modèle d'authentificateur ; que des zéros quand l'appareil le tait. */
    #[ORM\Column(length: 36)]
    private string $aaguid;

    #[ORM\Column(length: self::NOM_MAX)]
    private string $nom;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastUsedAt = null;

    /** @param list<string> $transports */
    public function __construct(
        User $user,
        string $credentialId,
        string $publicKey,
        int $signCount,
        array $transports,
        string $aaguid,
        string $nom,
        ?\DateTimeImmutable $createdAt = null,
    ) {
        $this->user = $user;
        $this->credentialId = $credentialId;
        $this->publicKey = $publicKey;
        $this->signCount = $signCount;
        $this->transports = $transports;
        $this->aaguid = $aaguid;
        $this->nom = $nom;
        $this->createdAt = $createdAt ?? new \DateTimeImmutable();
    }

    /** Après une connexion vérifiée : le nouveau compteur, et l'heure. */
    public function utiliser(int $signCount, \DateTimeImmutable $le): self
    {
        $this->signCount = $signCount;
        $this->lastUsedAt = $le;

        return $this;
    }

    public function renommer(string $nom): self
    {
        $this->nom = $nom;

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

    public function getCredentialId(): string
    {
        return $this->credentialId;
    }

    public function getPublicKey(): string
    {
        return $this->publicKey;
    }

    public function getSignCount(): int
    {
        return $this->signCount;
    }

    /** @return list<string> */
    public function getTransports(): array
    {
        return $this->transports;
    }

    public function getAaguid(): string
    {
        return $this->aaguid;
    }

    public function getNom(): string
    {
        return $this->nom;
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
