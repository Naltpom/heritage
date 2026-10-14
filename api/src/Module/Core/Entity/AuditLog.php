<?php

declare(strict_types=1);

namespace App\Module\Core\Entity;

use App\Module\Core\Enum\AuditAction;
use App\Module\Core\Repository\AuditLogRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Journal inaltérable **côté applicatif** (§ 2.4 du modèle) : en insertion seule,
 * alimenté par un `EventSubscriber` Doctrine.
 *
 * Aucun service n'écrit ici à la main, aucun code n'émet d'`UPDATE` ni de `DELETE` sur
 * cette table — c'est la convention du dépôt de référence, et c'est ce qui donne sa
 * valeur au journal.
 *
 * Les champs sensibles (`password`, `totp_secret`, `token_hash`) sont remplacés par
 * `"***"` par le souscripteur, **jamais écrits**.
 */
#[ORM\Entity(repositoryClass: AuditLogRepository::class)]
#[ORM\Table(name: 'core_audit_log')]
// `occurred_at DESC` : l'écran « journal d'audit », du plus récent au plus ancien. Le sens
// de tri est posé à la main dans la migration, Doctrine ne sait pas l'exprimer.
#[ORM\Index(name: 'idx_core_audit_occurred_at', columns: ['occurred_at'])]
#[ORM\Index(name: 'idx_core_audit_subject', columns: ['subject_type', 'subject_id', 'occurred_at'])]
#[ORM\Index(name: 'idx_core_audit_actor', columns: ['actor_user_id', 'occurred_at'])]
// Index partiel : `connexion_echouee` est la seule action qu'on interroge isolément
// (détection de force brute), et elle est minoritaire.
#[ORM\Index(
    name: 'idx_core_audit_echecs',
    columns: ['action', 'occurred_at'],
    options: ['where' => "((action)::text = 'connexion_echouee'::text)"],
)]
class AuditLog
{
    /** `BIGINT` ici et nulle part ailleurs : c'est la seule table qui croît sans borne. */
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $occurredAt;

    /** ⇢ inter-module (`core_user`). `null` pour les actions système (commande en console). */
    #[ORM\Column(nullable: true)]
    private ?int $actorUserId = null;

    /** Libellé dénormalisé. Survit à la suppression du compte : c'est tout l'intérêt d'un journal. */
    #[ORM\Column(length: 180, nullable: true)]
    private ?string $actorEmail = null;

    #[ORM\Column(length: 40, enumType: AuditAction::class)]
    private AuditAction $action;

    /** Nom court de l'entité visée : `Tree\Person`, `Proposal\ChangeRequest`. */
    #[ORM\Column(length: 120)]
    private string $subjectType;

    /** En **texte** : le journal traverse les modules et ne présume pas du type de clé. */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $subjectId = null;

    /**
     * Libellé lisible au moment des faits. À remplir avec le `display_name` et
     * **jamais** avec une ligne de dates : le § 8 du dossier de mission étend la règle
     * de confidentialité aux journaux.
     */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $subjectLabel = null;

    /**
     * Diff champ par champ : `{"surname": {"avant": "Bourrache", "apres": "BOURRACHE"}}`.
     *
     * @var array<string, mixed>|null
     */
    #[ORM\Column(type: Types::JSONB, nullable: true)]
    private ?array $changes = null;

    /** 45 signes : une IPv6 mappée IPv4 (`::ffff:192.168.0.1`) les occupe tous. */
    #[ORM\Column(length: 45, nullable: true)]
    private ?string $ip = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $userAgent = null;

    /**
     * L'administrateur qui **incarnait** l'acteur au moment de l'action, ou `null`.
     *
     * Sous une incarnation, `actor_user_id` est le compte incarné — c'est lui qui a agi,
     * aux yeux du produit — et cette colonne dit qui tenait réellement le clavier. Sans
     * elle, une action faite par l'administrateur au nom d'un membre serait
     * indistinguable d'une action du membre lui-même (ADR 0010).
     */
    #[ORM\Column(nullable: true)]
    private ?int $impersonatorUserId = null;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $impersonatorEmail = null;

    /** @param array<string, mixed>|null $changes */
    public function __construct(
        AuditAction $action,
        string $subjectType,
        ?string $subjectId = null,
        ?string $subjectLabel = null,
        ?array $changes = null,
        ?int $actorUserId = null,
        ?string $actorEmail = null,
        ?string $ip = null,
        ?string $userAgent = null,
        ?int $impersonatorUserId = null,
        ?string $impersonatorEmail = null,
    ) {
        $this->occurredAt = new \DateTimeImmutable();
        $this->action = $action;
        $this->subjectType = $subjectType;
        $this->subjectId = $subjectId;
        $this->subjectLabel = $subjectLabel;
        $this->changes = $changes;
        $this->actorUserId = $actorUserId;
        $this->actorEmail = $actorEmail;
        $this->ip = $ip;
        $this->userAgent = $userAgent;
        $this->impersonatorUserId = $impersonatorUserId;
        $this->impersonatorEmail = $impersonatorEmail;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOccurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function getActorUserId(): ?int
    {
        return $this->actorUserId;
    }

    public function getActorEmail(): ?string
    {
        return $this->actorEmail;
    }

    public function getAction(): AuditAction
    {
        return $this->action;
    }

    public function getSubjectType(): string
    {
        return $this->subjectType;
    }

    public function getSubjectId(): ?string
    {
        return $this->subjectId;
    }

    public function getSubjectLabel(): ?string
    {
        return $this->subjectLabel;
    }

    /** @return array<string, mixed>|null */
    public function getChanges(): ?array
    {
        return $this->changes;
    }

    public function getIp(): ?string
    {
        return $this->ip;
    }

    public function getUserAgent(): ?string
    {
        return $this->userAgent;
    }

    public function getImpersonatorUserId(): ?int
    {
        return $this->impersonatorUserId;
    }

    public function getImpersonatorEmail(): ?string
    {
        return $this->impersonatorEmail;
    }
}
