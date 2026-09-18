<?php

declare(strict_types=1);

namespace App\Module\Core\Entity;

use App\Module\Core\Repository\EmailChangeRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Une demande de changement de courriel, en attente de la preuve que la nouvelle
 * adresse répond.
 *
 * Le courriel est l'identifiant du compte. Le remplacer sans cette preuve permettrait à
 * une session volée d'enfermer le titulaire dehors ; on garde donc l'adresse demandée
 * **ici**, à part, jusqu'à ce que quelqu'un clique sur le lien reçu à cette adresse.
 * La migration `Version20260918150000` dit pourquoi une table et pas trois colonnes.
 *
 * Même discipline que `Membership\Entity\Invitation` : l'empreinte du jeton et jamais le
 * jeton, usage unique, expiration, et **404** sur tout refus — la raison de chacune de
 * ces règles est écrite là-bas, une fois.
 */
#[ORM\Entity(repositoryClass: EmailChangeRepository::class)]
#[ORM\Table(name: 'core_email_change')]
// Chaque clic sur le lien fait exactement une requête `WHERE token_hash = ?`. Sans cet
// index, un jeton valide répondrait plus lentement qu'un jeton inventé.
#[ORM\UniqueConstraint(name: 'uniq_core_email_change_token', columns: ['token_hash'])]
// « Ce compte a-t-il une demande en cours ? » — la seule question posée par `user_id`.
#[ORM\Index(
    name: 'idx_core_email_change_en_cours',
    columns: ['user_id'],
    options: ['where' => '(used_at IS NULL)'],
)]
class EmailChange
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Le compte, par son identifiant nu — comme partout où `core_user` est référencé. */
    #[ORM\Column]
    private int $userId;

    /** L'adresse demandée. Elle ne devient l'identifiant qu'à la confirmation. */
    #[ORM\Column(length: 180)]
    private string $newEmail;

    /** `hash('sha256', $jeton)` en hexadécimal. Jamais le jeton lui-même. */
    #[ORM\Column(length: 64, options: ['fixed' => true])]
    private string $tokenHash;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $expiresAt;

    /** Usage unique : posée à la confirmation, et plus rien ne relit la ligne ensuite. */
    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $usedAt = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(int $userId, string $newEmail, string $tokenHash, \DateTimeImmutable $expiresAt)
    {
        $this->userId = $userId;
        $this->newEmail = $newEmail;
        $this->tokenHash = $tokenHash;
        $this->expiresAt = $expiresAt;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUserId(): int
    {
        return $this->userId;
    }

    public function getNewEmail(): string
    {
        return $this->newEmail;
    }

    public function getTokenHash(): string
    {
        return $this->tokenHash;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function getUsedAt(): ?\DateTimeImmutable
    {
        return $this->usedAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** Non consommée et non échue : la seule forme sous laquelle un lien est accepté. */
    public function estUtilisable(\DateTimeImmutable $maintenant): bool
    {
        return null === $this->usedAt && $this->expiresAt > $maintenant;
    }

    public function marquerUtilisee(\DateTimeImmutable $le): self
    {
        $this->usedAt = $le;

        return $this;
    }

    /**
     * Périme la demande sans la consommer : c'est ce qui arrive à l'ancienne quand le
     * titulaire en dépose une nouvelle. Un lien périmé et un lien inconnu répondent
     * pareil — 404 —, donc l'ancien courriel ne révèle rien s'il est ouvert trop tard.
     */
    public function perimer(\DateTimeImmutable $le): self
    {
        if ($this->expiresAt > $le) {
            $this->expiresAt = $le;
        }

        return $this;
    }
}
