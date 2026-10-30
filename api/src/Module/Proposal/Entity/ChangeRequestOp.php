<?php

declare(strict_types=1);

namespace App\Module\Proposal\Entity;

use App\Module\Core\Enum\SubjectKind;
use App\Module\Proposal\Enum\OpKind;
use App\Module\Proposal\Enum\ValueKind;
use App\Module\Proposal\Repository\ChangeRequestOpRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * **Un op par champ**, comme l'exige le § 6.3 du dossier de mission (§ 5.2 du modèle).
 *
 * **Entité `ChangeRequestOp`, table `prp_change_op`** — les deux noms écrits noir sur
 * blanc, parce que l'ADR 0004 nommait l'entité et le modèle nommait la table, et que
 * personne n'avait posé la correspondance. Sans cette ligne, Doctrine laissé à lui-même
 * produirait `prp_change_request_op`.
 */
#[ORM\Entity(repositoryClass: ChangeRequestOpRepository::class)]
#[ORM\Table(name: 'prp_change_op')]
// Garantit un ordre total et interdit deux ops au même rang.
#[ORM\UniqueConstraint(name: 'uniq_prp_op_seq', columns: ['request_id', 'seq'])]
// **Le calque « en attente »** : « quels champs de la personne 17 sont en cours de
// modification, et par qui » — appelé à chaque affichage de fiche, pour l'auteur et les
// administrateurs.
#[ORM\Index(
    name: 'idx_prp_op_sujet',
    columns: ['subject_kind', 'subject_id'],
    options: ['where' => '(subject_id IS NOT NULL)'],
)]
class ChangeRequestOp
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ChangeRequest::class)]
    #[ORM\JoinColumn(name: 'request_id', nullable: false, onDelete: 'CASCADE')]
    private ChangeRequest $request;

    /**
     * Ordre d'application. Les ops se rejouent dans cet ordre, **toujours** : un diff
     * qui s'applique dans un ordre variable n'est pas reproductible, donc pas auditable.
     */
    #[ORM\Column(type: Types::SMALLINT)]
    private int $seq;

    #[ORM\Column(length: 20, enumType: OpKind::class)]
    private OpKind $opKind;

    #[ORM\Column(length: 12, enumType: SubjectKind::class)]
    private SubjectKind $subjectKind;

    /** ⇢ inter-module. La ligne visée, quand elle existe déjà. */
    #[ORM\Column(nullable: true)]
    private ?int $subjectId = null;

    /**
     * La référence provisoire (`#p1`) quand l'op porte sur une ligne que la demande crée.
     * Exactement un des deux est renseigné (`ck_prp_op_sujet_unique`).
     */
    #[ORM\Column(length: 8, nullable: true)]
    private ?string $subjectDraftRef = null;

    /**
     * Nom du champ modifié, **au nom de propriété Doctrine** (`surname`, `birthMin`,
     * `deceased`), pas au nom de colonne : c'est le nom que le service de rejeu utilise
     * pour appeler le bon `setter`.
     *
     * `null` pour les ops qui ne portent pas sur un champ (ajout de lien, de média).
     */
    #[ORM\Column(length: 60, nullable: true)]
    private ?string $field = null;

    #[ORM\Column(length: 16, enumType: ValueKind::class)]
    private ValueKind $valueKind;

    /**
     * La valeur **au moment de la soumission**. Elle sert trois choses : afficher le diff
     * (« Bourrache → BOURRACHE ») ; **décider, quand le verrou s'est déclenché, entre
     * rejeu automatique et caducité** (§ 9.6) — c'est elle, et non `baseVersion`, qui
     * tranche ; et rendre le refus intelligible après coup.
     */
    #[ORM\Column(type: Types::JSONB, nullable: true)]
    private mixed $oldValue = null;

    /**
     * La valeur proposée. `null` est une valeur **légitime** (« effacer le lieu de
     * décès ») — c'est pourquoi la colonne est `JSONB` nullable et pas une chaîne vide.
     */
    #[ORM\Column(type: Types::JSONB, nullable: true)]
    private mixed $newValue = null;

    /**
     * **Proposé à l'aveugle** : l'auteur ne voit pas la valeur courante (naissance d'un vivant
     * hors de son cercle, cible fermée au déposant), le serveur a donc posé lui-même `old_value`.
     * Elle peut alors égaler `new_value` — l'auteur a deviné juste — et `ck_prp_op_changement`
     * ne l'exige distincte que hors de ce cas : refuser l'égalité serait un oracle
     * (`201` ou `500` selon que la valeur devinée est la bonne). La demande vue de l'auteur
     * n'en dit rien ({@see \App\Module\Proposal\Service\SerialiseurDeDemande}).
     */
    #[ORM\Column(options: ['default' => false])]
    private bool $blind = false;

    /**
     * Verrou optimiste n° 2 : la `version` de la ligne visée au moment de la soumission.
     *
     * **C'est le verrou inter-requêtes** (§ 9.6), et il est indispensable : un
     * `#[ORM\Version]` seul ne voit rien passer entre une demande déposée lundi et
     * validée vendredi, l'entité étant rechargée fraîche entre les deux. Porté par l'op
     * et non par la demande, parce qu'une demande touche plusieurs lignes — une création
     * touche une personne, une famille et deux filiations — et qu'une colonne unique sur
     * l'en-tête ne saurait pas dire **laquelle** a bougé.
     *
     * `null` quand le sujet est un brouillon : il n'a pas encore de ligne à verrouiller.
     */
    #[ORM\Column(nullable: true)]
    private ?int $baseVersion = null;

    /**
     * Horodatage du rejeu effectif, `null` tant que la demande n'est pas validée.
     *
     * Permet de diagnostiquer une validation partiellement appliquée — qui ne devrait
     * jamais arriver, puisque tout est dans une transaction, mais un diagnostic qu'on
     * n'a pas est un diagnostic qu'on regrette.
     */
    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $appliedAt = null;

    public function __construct(
        ChangeRequest $request,
        int $seq,
        OpKind $opKind,
        SubjectKind $subjectKind,
        ValueKind $valueKind,
    ) {
        $this->request = $request;
        $this->seq = $seq;
        $this->opKind = $opKind;
        $this->subjectKind = $subjectKind;
        $this->valueKind = $valueKind;
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

    public function getOpKind(): OpKind
    {
        return $this->opKind;
    }

    public function getSubjectKind(): SubjectKind
    {
        return $this->subjectKind;
    }

    public function getSubjectId(): ?int
    {
        return $this->subjectId;
    }

    public function getSubjectDraftRef(): ?string
    {
        return $this->subjectDraftRef;
    }

    /** Une ligne existante : l'identifiant et la version vue au moment de la soumission. */
    public function viserLigneExistante(int $subjectId, ?int $baseVersion): self
    {
        $this->subjectId = $subjectId;
        $this->subjectDraftRef = null;
        $this->baseVersion = $baseVersion;

        return $this;
    }

    /** Une ligne que la demande crée : elle n'a qu'une référence provisoire, donc pas de version. */
    public function viserBrouillon(string $draftRef): self
    {
        $this->subjectDraftRef = $draftRef;
        $this->subjectId = null;
        $this->baseVersion = null;

        return $this;
    }

    public function getField(): ?string
    {
        return $this->field;
    }

    public function setField(?string $field): self
    {
        $this->field = $field;

        return $this;
    }

    public function getValueKind(): ValueKind
    {
        return $this->valueKind;
    }

    public function getOldValue(): mixed
    {
        return $this->oldValue;
    }

    public function getNewValue(): mixed
    {
        return $this->newValue;
    }

    /**
     * `ck_prp_op_changement` refuse en base un op de champ dont l'ancienne et la nouvelle
     * valeur sont identiques : une modification qui ne modifie rien n'a rien à faire dans
     * une demande. **Sauf à l'aveugle** ({@see proposerALAveugle()}), où `old_value` est celle
     * que le serveur a lue et où l'égalité est permise.
     */
    public function setValues(mixed $oldValue, mixed $newValue): self
    {
        $this->oldValue = $oldValue;
        $this->newValue = $newValue;

        return $this;
    }

    /** Marque l'op comme proposé à l'aveugle : `ck_prp_op_changement` n'y exige plus `old_value` distincte. */
    public function proposerALAveugle(): self
    {
        $this->blind = true;

        return $this;
    }

    public function estALAveugle(): bool
    {
        return $this->blind;
    }

    public function getBaseVersion(): ?int
    {
        return $this->baseVersion;
    }

    public function getAppliedAt(): ?\DateTimeImmutable
    {
        return $this->appliedAt;
    }

    public function marquerApplique(?\DateTimeImmutable $le = null): self
    {
        $this->appliedAt = $le ?? new \DateTimeImmutable();

        return $this;
    }
}
