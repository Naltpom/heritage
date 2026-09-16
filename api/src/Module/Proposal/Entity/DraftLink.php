<?php

declare(strict_types=1);

namespace App\Module\Proposal\Entity;

use App\Module\Core\ValueObject\UncertainDate;
use App\Module\Proposal\Enum\LinkKind;
use App\Module\Proposal\Repository\DraftLinkRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Les liens de la personne proposée — ou, pour une demande `ajout_lien`, le lien entre
 * deux personnes qui existent déjà (§ 5.4 du modèle de données).
 *
 * Le § 6.4 du dossier exige **au moins un** lien, « sinon la personne flotterait hors de
 * l'arbre » ; c'est une règle de service et non de schéma, parce qu'elle porte sur le
 * nombre de lignes filles.
 *
 * Chaque extrémité est soit une personne existante (`*PersonId`), soit un brouillon de
 * la même demande (`*DraftRef`) — jamais les deux, et les contraintes `CHECK` de la
 * migration l'imposent. Pour une filiation, on désigne **soit** un couple existant
 * (`familyId`), **soit** un ou deux parents : jamais les deux, parce que le service ne
 * saurait pas laquelle des deux intentions respecter.
 */
#[ORM\Entity(repositoryClass: DraftLinkRepository::class)]
#[ORM\Table(name: 'prp_draft_link')]
#[ORM\UniqueConstraint(name: 'uniq_prp_draft_link_seq', columns: ['request_id', 'seq'])]
// Le § 5.4 du modèle demande aussi un index simple sur les **mêmes** colonnes, dans le
// même ordre, pour charger les liens d'une demande. Doctrine ne l'émet pas : un index
// unique le rend inutile, et il l'absorbe. La déclaration reste, parce qu'elle dit
// l'intention et qu'elle reprendra vie si l'unicité disparaît un jour — mais ne pas la
// chercher dans un `\d prp_draft_link`, elle n'y sera pas.
#[ORM\Index(name: 'idx_prp_draft_link_request', columns: ['request_id', 'seq'])]
// « Y a-t-il une demande en attente qui propose de me rattacher quelqu'un ? » — le graphe
// d'un lecteur doit montrer le nœud proposé **relié à sa parenté**, donc il faut savoir,
// pour une personne donnée, quels brouillons la citent.
#[ORM\Index(
    name: 'idx_prp_draft_link_other',
    columns: ['other_person_id'],
    options: ['where' => '(other_person_id IS NOT NULL)'],
)]
class DraftLink
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ChangeRequest::class)]
    #[ORM\JoinColumn(name: 'request_id', nullable: false, onDelete: 'CASCADE')]
    private ChangeRequest $request;

    /** Ordre de traitement, comme pour les ops. */
    #[ORM\Column(type: Types::SMALLINT)]
    private int $seq;

    #[ORM\Column(length: 16, enumType: LinkKind::class)]
    private LinkKind $kind;

    /** ⇢ inter-module (`Tree\Person`). Le sujet du lien, quand c'est une personne existante. */
    #[ORM\Column(nullable: true)]
    private ?int $subjectPersonId = null;

    /** Le sujet, quand c'est un brouillon de cette demande. Exactement un des deux. */
    #[ORM\Column(length: 8, nullable: true)]
    private ?string $subjectDraftRef = null;

    /** Libellé dénormalisé, pour afficher le lien sans rien charger. Filtré comme la personne (§ 0.4). */
    #[ORM\Column(length: 255)]
    private string $subjectLabel;

    /** ⇢ inter-module (`Tree\Person`). L'autre extrémité. */
    #[ORM\Column(nullable: true)]
    private ?int $otherPersonId = null;

    #[ORM\Column(length: 8, nullable: true)]
    private ?string $otherDraftRef = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $otherLabel = null;

    /**
     * ⇢ inter-module. Le **second parent**, quand `kind = enfant_de` et que le demandeur
     * a désigné deux parents plutôt qu'une famille.
     */
    #[ORM\Column(nullable: true)]
    private ?int $other2PersonId = null;

    #[ORM\Column(length: 8, nullable: true)]
    private ?string $other2DraftRef = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $other2Label = null;

    /**
     * ⇢ inter-module (`Tree\Family`). Renseigné quand le demandeur a désigné **un couple
     * existant** au lieu d'un ou deux parents. Exclusif avec les colonnes `other*`.
     */
    #[ORM\Column(nullable: true)]
    private ?int $familyId = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $familyLabel = null;

    /** Ne concerne que `conjoint_de`. « Toutes facultatives : une union sans date reste une union. » */
    #[ORM\Embedded(class: UncertainDate::class, columnPrefix: 'marriage_')]
    private UncertainDate $marriage;

    #[ORM\Column(options: ['default' => false])]
    private bool $divorced = false;

    /** La « date de rupture » du § 6.4 du dossier — divorce ou séparation. */
    #[ORM\Embedded(class: UncertainDate::class, columnPrefix: 'divorce_')]
    private UncertainDate $divorce;

    /** Rang souhaité dans la fratrie, quand le demandeur le connaît (« c'était l'aîné »). `null` = à la fin. */
    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $siblingOrder = null;

    /** ⇢ inter-module (`Tree\Family`). Rempli à la validation : la famille trouvée ou créée. */
    #[ORM\Column(nullable: true)]
    private ?int $resolvedFamilyId = null;

    /** Justification du lien par le demandeur. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $note = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(ChangeRequest $request, int $seq, LinkKind $kind, string $subjectLabel)
    {
        $this->request = $request;
        $this->seq = $seq;
        $this->kind = $kind;
        $this->subjectLabel = $subjectLabel;
        $this->marriage = UncertainDate::inconnue();
        $this->divorce = UncertainDate::inconnue();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRequest(): ChangeRequest
    {
        return $this->request;
    }

    public function getSeq(): int
    {
        return $this->seq;
    }

    public function setSeq(int $seq): self
    {
        $this->seq = $seq;

        return $this;
    }

    public function getKind(): LinkKind
    {
        return $this->kind;
    }

    public function getSubjectPersonId(): ?int
    {
        return $this->subjectPersonId;
    }

    public function getSubjectDraftRef(): ?string
    {
        return $this->subjectDraftRef;
    }

    public function getSubjectLabel(): string
    {
        return $this->subjectLabel;
    }

    public function setSubjectLabel(string $subjectLabel): self
    {
        $this->subjectLabel = $subjectLabel;

        return $this;
    }

    public function viserSujetExistant(int $personId): self
    {
        $this->subjectPersonId = $personId;
        $this->subjectDraftRef = null;

        return $this;
    }

    public function viserSujetBrouillon(string $draftRef): self
    {
        $this->subjectDraftRef = $draftRef;
        $this->subjectPersonId = null;

        return $this;
    }

    public function getOtherPersonId(): ?int
    {
        return $this->otherPersonId;
    }

    public function getOtherDraftRef(): ?string
    {
        return $this->otherDraftRef;
    }

    public function getOtherLabel(): ?string
    {
        return $this->otherLabel;
    }

    /**
     * Désigne l'autre extrémité — personne existante **ou** brouillon, jamais les deux.
     * Vide du même geste la désignation d'un couple existant, avec laquelle elle est
     * exclusive (`ck_prp_link_cible`).
     */
    public function designerAutre(?int $personId, ?string $draftRef, ?string $label): self
    {
        $this->otherPersonId = $personId;
        $this->otherDraftRef = $draftRef;
        $this->otherLabel = $label;
        $this->familyId = null;
        $this->familyLabel = null;

        return $this;
    }

    public function getOther2PersonId(): ?int
    {
        return $this->other2PersonId;
    }

    public function getOther2DraftRef(): ?string
    {
        return $this->other2DraftRef;
    }

    public function getOther2Label(): ?string
    {
        return $this->other2Label;
    }

    /** Le second parent n'a de sens que pour une filiation (`ck_prp_link_second_parent`). */
    public function designerSecondParent(?int $personId, ?string $draftRef, ?string $label): self
    {
        $this->other2PersonId = $personId;
        $this->other2DraftRef = $draftRef;
        $this->other2Label = $label;

        return $this;
    }

    public function getFamilyId(): ?int
    {
        return $this->familyId;
    }

    public function getFamilyLabel(): ?string
    {
        return $this->familyLabel;
    }

    /** Désigner un couple existant exclut toute désignation de parent (`ck_prp_link_cible`). */
    public function designerCoupleExistant(int $familyId, ?string $familyLabel): self
    {
        $this->familyId = $familyId;
        $this->familyLabel = $familyLabel;
        $this->otherPersonId = null;
        $this->otherDraftRef = null;
        $this->otherLabel = null;
        $this->other2PersonId = null;
        $this->other2DraftRef = null;
        $this->other2Label = null;

        return $this;
    }

    public function getMarriage(): UncertainDate
    {
        return $this->marriage;
    }

    public function setMarriage(UncertainDate $marriage): self
    {
        $this->marriage = $marriage;

        return $this;
    }

    public function isDivorced(): bool
    {
        return $this->divorced;
    }

    public function setDivorced(bool $divorced): self
    {
        $this->divorced = $divorced;

        return $this;
    }

    public function getDivorce(): UncertainDate
    {
        return $this->divorce;
    }

    public function setDivorce(UncertainDate $divorce): self
    {
        $this->divorce = $divorce;

        return $this;
    }

    public function getSiblingOrder(): ?int
    {
        return $this->siblingOrder;
    }

    public function setSiblingOrder(?int $siblingOrder): self
    {
        $this->siblingOrder = $siblingOrder;

        return $this;
    }

    public function getResolvedFamilyId(): ?int
    {
        return $this->resolvedFamilyId;
    }

    public function setResolvedFamilyId(?int $resolvedFamilyId): self
    {
        $this->resolvedFamilyId = $resolvedFamilyId;

        return $this;
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
