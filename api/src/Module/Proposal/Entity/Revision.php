<?php

declare(strict_types=1);

namespace App\Module\Proposal\Entity;

use App\Module\Core\Enum\SubjectKind;
use App\Module\Proposal\Enum\RevisionOperation;
use App\Module\Proposal\Repository\RevisionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * L'historique des changements **effectivement appliqués** à l'arbre (§ 5.5).
 *
 * Distincte du journal d'audit de `Core` : `core_audit_log` enregistre « qui a fait
 * quoi » pour la sécurité, `prp_revision` enregistre « ce que l'arbre est devenu » pour
 * la généalogie. Un administrateur qui édite directement produit une révision —
 * l'écriture est auto-approuvée mais **tracée, pas passée sous silence** (§ 6.3 du
 * dossier de mission).
 */
#[ORM\Entity(repositoryClass: RevisionRepository::class)]
#[ORM\Table(name: 'prp_revision')]
// « L'historique de cette fiche » — encart d'une fiche, la requête principale de la table.
#[ORM\Index(name: 'idx_prp_revision_sujet', columns: ['subject_kind', 'subject_id', 'applied_at'])]
// « Qu'est-ce que cette validation a réellement produit ? » — une demande de création
// produit plusieurs révisions : la personne, la famille, la filiation.
#[ORM\Index(name: 'idx_prp_revision_request', columns: ['request_id'])]
#[ORM\Index(name: 'idx_prp_revision_date', columns: ['applied_at'])]
class Revision
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * FK **intra-module**. `null` **pour le seul import GEDCOM**.
     *
     * Une édition directe d'administrateur porte bien un `request_id` : elle crée une
     * `ChangeRequest` auto-validée dans la même transaction (ADR 0004). Une révision sans
     * demande est donc le signe d'un import, **jamais d'un raccourci**.
     */
    #[ORM\ManyToOne(targetEntity: ChangeRequest::class)]
    #[ORM\JoinColumn(name: 'request_id', nullable: true, onDelete: 'SET NULL')]
    private ?ChangeRequest $request = null;

    #[ORM\Column(length: 12, enumType: SubjectKind::class)]
    private SubjectKind $subjectKind;

    /** ⇢ inter-module. La ligne touchée. */
    #[ORM\Column]
    private int $subjectId;

    /** Libellé au moment des faits. Filtré comme la personne (§ 0.4). */
    #[ORM\Column(length: 255)]
    private string $subjectLabel;

    /**
     * 24 et non 12, alors que la plus longue valeur d'aujourd'hui — `modification` — en
     * fait exactement 12.
     *
     * Une marge nulle n'est pas une erreur en soi ; elle l'est sur **l'énumération la plus
     * susceptible de grandir du projet**. `fusion`, `lien_modifie`, `lien_supprime` sont
     * des évolutions naturelles dès qu'on traitera les doublons ou les liens entre
     * personnes existantes, et chacune dépasserait la largeur. Ce serait le troisième
     * armement du même piège : une colonne trop courte ne se voit ni à l'analyse statique,
     * ni à `schema:validate`, ni à la migration — seulement au premier `INSERT` réel.
     *
     * Contre-exemple utile, qui dit pourquoi on n'élargit pas tout : `tree_person.branch`
     * reste en `VARCHAR(2)`. Sa marge est nulle aussi, mais un quart d'ascendance tient en
     * deux signes **par construction**. Ce n'est pas une marge faible, c'est une taille
     * exacte — toutes les marges nulles ne se valent pas.
     */
    #[ORM\Column(length: 24, enumType: RevisionOperation::class)]
    private RevisionOperation $operation;

    /**
     * Le diff appliqué, même forme que `core_audit_log.changes`. Pour une création,
     * `avant` vaut `null` partout.
     *
     * @var array<string, mixed>
     */
    #[ORM\Column(type: Types::JSONB)]
    private array $changes;

    #[ORM\Column(nullable: true)]
    private ?int $versionBefore = null;

    /** Avec `versionBefore`, permet de rejouer l'historique d'une fiche sans ambiguïté et d'y repérer un trou. */
    #[ORM\Column(nullable: true)]
    private ?int $versionAfter = null;

    /**
     * ⇢ inter-module (`Core\User`). Celui qui a **validé**, pas celui qui a proposé —
     * l'auteur de la proposition est dans `prp_change_request.author_user_id`.
     */
    #[ORM\Column]
    private int $actorUserId;

    #[ORM\Column(length: 120)]
    private string $actorLabel;

    /**
     * L'**empreinte de l'arbre** (§ 2.3) telle que recalculée après application, dans la
     * même transaction.
     *
     * Elle répond à « l'`ETag` que ce navigateur me renvoie correspond à quel état de
     * l'arbre ? », donc situe un rendu dans l'historique. Une empreinte ne s'ordonne pas,
     * elle s'identifie — c'est pourquoi ce n'est pas un compteur (arbitrage A). Une
     * empreinte servie qui ne correspond à aucune révision connue est un diagnostic, pas
     * une panne.
     */
    #[ORM\Column(length: 64, options: ['fixed' => true])]
    private string $treeFingerprintAfter;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $appliedAt;

    /** @param array<string, mixed> $changes */
    public function __construct(
        SubjectKind $subjectKind,
        int $subjectId,
        string $subjectLabel,
        RevisionOperation $operation,
        array $changes,
        int $actorUserId,
        string $actorLabel,
        string $treeFingerprintAfter,
    ) {
        $this->subjectKind = $subjectKind;
        $this->subjectId = $subjectId;
        $this->subjectLabel = $subjectLabel;
        $this->operation = $operation;
        $this->changes = $changes;
        $this->actorUserId = $actorUserId;
        $this->actorLabel = $actorLabel;
        $this->treeFingerprintAfter = $treeFingerprintAfter;
        $this->appliedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRequest(): ?ChangeRequest
    {
        return $this->request;
    }

    public function setRequest(?ChangeRequest $request): self
    {
        $this->request = $request;

        return $this;
    }

    public function getSubjectKind(): SubjectKind
    {
        return $this->subjectKind;
    }

    public function getSubjectId(): int
    {
        return $this->subjectId;
    }

    public function getSubjectLabel(): string
    {
        return $this->subjectLabel;
    }

    public function getOperation(): RevisionOperation
    {
        return $this->operation;
    }

    /** @return array<string, mixed> */
    public function getChanges(): array
    {
        return $this->changes;
    }

    public function getVersionBefore(): ?int
    {
        return $this->versionBefore;
    }

    public function getVersionAfter(): ?int
    {
        return $this->versionAfter;
    }

    public function setVersions(?int $before, ?int $after): self
    {
        $this->versionBefore = $before;
        $this->versionAfter = $after;

        return $this;
    }

    public function getActorUserId(): int
    {
        return $this->actorUserId;
    }

    public function getActorLabel(): string
    {
        return $this->actorLabel;
    }

    public function getTreeFingerprintAfter(): string
    {
        return $this->treeFingerprintAfter;
    }

    public function getAppliedAt(): \DateTimeImmutable
    {
        return $this->appliedAt;
    }
}
