<?php

declare(strict_types=1);

namespace App\Module\Membership\Entity;

use App\Module\Core\ValueObject\ComposantesDeDate;
use App\Module\Membership\Repository\DemandeRgpdRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Une **demande RGPD** d'un membre (ADR 0016) — le formulaire de l'application, et non un
 * courriel : retirer une information partagée avant la fin de son verrou de 30 jours,
 * faire rectifier une donnée, ou toute autre demande sur ses données personnelles.
 *
 * Un administrateur la traite : **appliquée** (pour un retrait, les composantes visées sont
 * retirées aussitôt, sans verrou) ou **refusée**, avec une réponse que le membre lit dans
 * « Mon compte ». Rien ne s'efface : la demande et sa décision sont la trace.
 */
#[ORM\Entity(repositoryClass: DemandeRgpdRepository::class)]
#[ORM\Table(name: 'mbr_demande_rgpd')]
#[ORM\Index(name: 'idx_mbr_demande_rgpd_membre', columns: ['member_id'])]
#[ORM\Index(name: 'idx_mbr_demande_rgpd_attente', columns: ['created_at'], options: ['where' => '(traitee_at IS NULL)'])]
class DemandeRgpd
{
    public const string RETRAIT_PARTAGE = 'retrait_partage';
    public const string RECTIFICATION = 'rectification';
    public const string AUTRE = 'autre';
    public const array NATURES = [self::RETRAIT_PARTAGE, self::RECTIFICATION, self::AUTRE];

    public const string APPLIQUEE = 'appliquee';
    public const string REFUSEE = 'refusee';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** FK **intra-module** par identifiant : la demande survit à la ligne du membre. */
    #[ORM\Column]
    private int $memberId;

    /** ⇢ inter-module (`Core\User`). */
    #[ORM\Column]
    private int $userId;

    /** Libellé dénormalisé, pour la file d'administration. */
    #[ORM\Column(length: 180)]
    private string $userEmail;

    #[ORM\Column(length: 24)]
    private string $nature;

    #[ORM\Column]
    private bool $retirerAnnee = false;

    #[ORM\Column]
    private bool $retirerMois = false;

    #[ORM\Column]
    private bool $retirerJour = false;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $message;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $traiteeAt = null;

    /** ⇢ inter-module (`Core\User`). */
    #[ORM\Column(nullable: true)]
    private ?int $traiteeParUserId = null;

    #[ORM\Column(length: 12, nullable: true)]
    private ?string $decision = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $reponse = null;

    public function __construct(Member $membre, string $nature, ComposantesDeDate $aRetirer, ?string $message)
    {
        $this->memberId = (int) $membre->getId();
        $this->userId = $membre->getUserId();
        $this->userEmail = $membre->getUserEmail();
        $this->nature = $nature;
        $this->retirerAnnee = $aRetirer->annee;
        $this->retirerMois = $aRetirer->mois;
        $this->retirerJour = $aRetirer->jour;
        $this->message = $message;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getMemberId(): int
    {
        return $this->memberId;
    }

    public function getUserId(): int
    {
        return $this->userId;
    }

    public function getUserEmail(): string
    {
        return $this->userEmail;
    }

    public function getNature(): string
    {
        return $this->nature;
    }

    /** Les composantes dont le retrait est demandé (nature `retrait_partage`). */
    public function getARetirer(): ComposantesDeDate
    {
        return new ComposantesDeDate($this->retirerAnnee, $this->retirerMois, $this->retirerJour);
    }

    public function getMessage(): ?string
    {
        return $this->message;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getTraiteeAt(): ?\DateTimeImmutable
    {
        return $this->traiteeAt;
    }

    public function getTraiteeParUserId(): ?int
    {
        return $this->traiteeParUserId;
    }

    public function getDecision(): ?string
    {
        return $this->decision;
    }

    public function getReponse(): ?string
    {
        return $this->reponse;
    }

    public function estTraitee(): bool
    {
        return null !== $this->traiteeAt;
    }

    /** @param self::APPLIQUEE|self::REFUSEE $decision */
    public function traiter(string $decision, ?string $reponse, int $parUserId): self
    {
        $this->decision = $decision;
        $this->reponse = $reponse;
        $this->traiteeParUserId = $parUserId;
        $this->traiteeAt = new \DateTimeImmutable();

        return $this;
    }
}
