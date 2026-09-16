<?php

declare(strict_types=1);

namespace App\Module\Membership\Entity;

use App\Module\Membership\Enum\ClaimResult;
use App\Module\Membership\Repository\ClaimAttemptRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * La traçabilité des tentatives de rattachement (§ 4.3 du modèle de données).
 *
 * **C'est la seule table de limitation de débit du projet**, et son existence se
 * justifie : le § 6.1 du dossier décrit l'énumération des personnes vivantes comme le
 * principal risque du produit. Le compteur du `RateLimiter` de Symfony sait dire « non »
 * mais ne sait rien raconter après coup. Ici on veut pouvoir répondre, trois semaines
 * plus tard, à « qu'est-ce que ce jeton a essayé de chercher ».
 *
 * Le comptage lui-même reste au `RateLimiter` (atomique et cadencé) ; cette table est le
 * journal, écrit en insertion seule.
 */
#[ORM\Entity(repositoryClass: ClaimAttemptRepository::class)]
#[ORM\Table(name: 'mbr_claim_attempt')]
#[ORM\Index(name: 'idx_mbr_claim_invitation', columns: ['invitation_id', 'occurred_at'])]
// Détection d'un balayage distribué sur plusieurs jetons depuis une même adresse.
#[ORM\Index(name: 'idx_mbr_claim_ip', columns: ['ip', 'occurred_at'])]
class ClaimAttempt
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Invitation::class)]
    #[ORM\JoinColumn(name: 'invitation_id', nullable: false, onDelete: 'CASCADE')]
    private Invitation $invitation;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $occurredAt;

    /** IP cliente restaurée par le proxy de confiance, pas `REMOTE_ADDR`. */
    #[ORM\Column(length: 45, nullable: true)]
    private ?string $ip = null;

    /**
     * `sha256` des critères soumis (nom, prénom, date de naissance), normalisés.
     *
     * **On ne stocke pas les critères en clair** : ils désignent une personne vivante,
     * souvent réelle, et ce journal serait sinon exactement la fuite qu'il est censé
     * documenter. L'empreinte suffit à détecter un balayage — mille tentatives avec
     * mille empreintes différentes.
     */
    #[ORM\Column(length: 64, options: ['fixed' => true])]
    private string $queryHash;

    #[ORM\Column(length: 24, enumType: ClaimResult::class)]
    private ClaimResult $result;

    /** Nombre de candidats renvoyés — **plafonné à 3** par `invitation.candidats_max`. */
    #[ORM\Column(type: Types::SMALLINT, options: ['default' => 0])]
    private int $resultCount = 0;

    public function __construct(
        Invitation $invitation,
        string $queryHash,
        ClaimResult $result,
        int $resultCount = 0,
        ?string $ip = null,
    ) {
        $this->invitation = $invitation;
        $this->queryHash = $queryHash;
        $this->result = $result;
        $this->resultCount = $resultCount;
        $this->ip = $ip;
        $this->occurredAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getInvitation(): Invitation
    {
        return $this->invitation;
    }

    public function getOccurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function getIp(): ?string
    {
        return $this->ip;
    }

    public function getQueryHash(): string
    {
        return $this->queryHash;
    }

    public function getResult(): ClaimResult
    {
        return $this->result;
    }

    public function getResultCount(): int
    {
        return $this->resultCount;
    }
}
