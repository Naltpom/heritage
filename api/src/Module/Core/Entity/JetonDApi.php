<?php

declare(strict_types=1);

namespace App\Module\Core\Entity;

use App\Module\Core\Enum\PorteeDeJeton;
use App\Module\Core\Repository\JetonDApiRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Un **jeton d'API personnel** : `Authorization: Bearer hrt_…`, pour les appels hors
 * navigateur (ADR 0021 « Jetons d'API », § 1.9 du contrat d'API). Le cookie de session
 * reste le porteur du front.
 *
 * ## Ce que cette table garde, et ce qu'elle ne garde pas
 *
 * **L'empreinte, jamais le jeton.** `token_hash` est `sha256(jeton)` ; le clair n'existe
 * qu'à la création, le temps d'être affiché une fois
 * ({@see \App\Module\Core\Service\GenerateurDeJetonDApi}). Une fuite de cette table ne
 * permet donc pas d'appeler l'API.
 *
 * - `token_prefix` : le début du jeton (`hrt_` et 8 caractères), pour reconnaître une ligne
 *   dans une liste. Jamais le secret entier.
 * - `scope` : la {@see PorteeDeJeton} — un jeton ne fait que ce que sa portée autorise, et
 *   jamais plus que son utilisateur.
 * - `expires_at` : **obligatoire**. Un jeton sans fin est un mot de passe qu'on oublie ;
 *   le défaut est de {@see self::VALIDITE_PAR_DEFAUT}.
 * - `revoked_at` : la révocation est une date, pas une suppression — la ligne reste pour
 *   que le journal d'audit sache encore de quoi il parle.
 * - `last_used_at`, `last_used_ip` : de quoi repérer un jeton qui sert d'où il ne devrait pas.
 *
 * ## Le journal d'audit
 *
 * `tokenHash` est dans `AuditDoctrineSubscriber::CHAMPS_SENSIBLES`. `lastUsedAt` et
 * `lastUsedIp` sont des champs **techniques** : chaque appel les avance, et une ligne
 * « modification » par requête rendrait le journal illisible.
 *
 * ## Une vraie clé étrangère vers `core_user`, en `ON DELETE CASCADE`
 *
 * Même raisonnement que {@see Passkey} : un compte supprimé ne doit laisser **aucun**
 * jeton qui ouvrirait encore l'API.
 */
#[ORM\Entity(repositoryClass: JetonDApiRepository::class)]
#[ORM\Table(name: 'core_api_token')]
#[ORM\UniqueConstraint(name: 'uniq_core_api_token_hash', columns: ['token_hash'])]
// « Les jetons de ce compte » — `heritage:jeton-api:lister`.
#[ORM\Index(name: 'idx_core_api_token_user', columns: ['user_id'])]
class JetonDApi
{
    /** L'étiquette qu'on lit dans les listes et le journal : courte. */
    public const int LIBELLE_MAX = 80;

    /** `sha256` en hexadécimal. */
    public const int EMPREINTE_LONGUEUR = 64;

    /** `hrt_` et 8 caractères. */
    public const int PREFIXE_MAX = 12;

    /** Durée de vie d'un jeton à qui l'on n'a pas fixé de fin. */
    public const string VALIDITE_PAR_DEFAUT = '+90 days';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: self::LIBELLE_MAX)]
    private string $libelle;

    #[ORM\Column(length: self::EMPREINTE_LONGUEUR)]
    private string $tokenHash;

    #[ORM\Column(length: self::PREFIXE_MAX)]
    private string $tokenPrefix;

    #[ORM\Column(name: 'scope', length: 20, enumType: PorteeDeJeton::class)]
    private PorteeDeJeton $portee;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastUsedAt = null;

    /** 45 signes : une IPv6 mappée IPv4 les occupe tous. */
    #[ORM\Column(length: 45, nullable: true)]
    private ?string $lastUsedIp = null;

    public function __construct(
        User $user,
        string $libelle,
        string $tokenHash,
        string $tokenPrefix,
        PorteeDeJeton $portee,
        ?\DateTimeImmutable $expiresAt = null,
        ?\DateTimeImmutable $createdAt = null,
    ) {
        $this->user = $user;
        $this->libelle = $libelle;
        $this->tokenHash = $tokenHash;
        $this->tokenPrefix = $tokenPrefix;
        $this->portee = $portee;
        $this->createdAt = $createdAt ?? new \DateTimeImmutable();
        $this->expiresAt = $expiresAt ?? $this->createdAt->modify(self::VALIDITE_PAR_DEFAUT);
    }

    public function estRevoque(): bool
    {
        return null !== $this->revokedAt;
    }

    public function estExpire(\DateTimeImmutable $le): bool
    {
        return $this->expiresAt <= $le;
    }

    /** Ni expiré ni révoqué à l'instant `$le` : la seule question que pose le pare-feu. */
    public function estUtilisable(\DateTimeImmutable $le): bool
    {
        return !$this->estRevoque() && !$this->estExpire($le);
    }

    /** Révoque à `$le`. Idempotent : une seconde révocation ne déplace pas la date. */
    public function revoquer(\DateTimeImmutable $le): self
    {
        $this->revokedAt ??= $le;

        return $this;
    }

    /** Note un usage : l'heure et l'adresse de l'appelant. */
    public function noterUsage(\DateTimeImmutable $le, ?string $ip): self
    {
        $this->lastUsedAt = $le;
        $this->lastUsedIp = $ip;

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

    public function getLibelle(): string
    {
        return $this->libelle;
    }

    public function getTokenHash(): string
    {
        return $this->tokenHash;
    }

    public function getTokenPrefix(): string
    {
        return $this->tokenPrefix;
    }

    public function getPortee(): PorteeDeJeton
    {
        return $this->portee;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function getRevokedAt(): ?\DateTimeImmutable
    {
        return $this->revokedAt;
    }

    public function getLastUsedAt(): ?\DateTimeImmutable
    {
        return $this->lastUsedAt;
    }

    public function getLastUsedIp(): ?string
    {
        return $this->lastUsedIp;
    }
}
