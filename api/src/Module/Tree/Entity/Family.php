<?php

declare(strict_types=1);

namespace App\Module\Tree\Entity;

use App\Module\Core\ValueObject\UncertainDate;
use App\Module\Tree\Repository\FamilyRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Une union — 103 lignes après import (§ 3.2 du modèle de données).
 *
 * C'est le **nœud de jonction** du § 7.1 du dossier de mission : le petit cercle entre
 * un couple et ses enfants. Une famille peut n'avoir qu'un seul époux (3 cas : `WIFE`
 * sans `HUSB`) et n'avoir aucun enfant (7 cas) — mais pas zéro époux, ce que la
 * contrainte `ck_tree_family_au_moins_un_epoux` refuse en base.
 */
#[ORM\Entity(repositoryClass: FamilyRepository::class)]
#[ORM\Table(name: 'tree_family')]
#[ORM\UniqueConstraint(name: 'uniq_tree_family_xref', columns: ['gedcom_xref'])]
// C'est la contrainte qui rend **déterministe** la création de famille implicite du
// § 6.4 du dossier : quand une demande dit « conjoint de X », le service cherche la
// famille du couple, et s'il peut y en avoir deux il ne sait pas laquelle. La migration
// la crée en `NULLS NOT DISTINCT` (PostgreSQL 15+) — Doctrine ne sait pas l'exprimer —
// pour qu'elle interdise aussi deux familles « mère seule » pour la même mère.
#[ORM\UniqueConstraint(name: 'uniq_tree_family_couple', columns: ['husband_id', 'wife_id'])]
#[ORM\Index(name: 'idx_tree_family_husband', columns: ['husband_id'])]
#[ORM\Index(name: 'idx_tree_family_wife', columns: ['wife_id'])]
#[ORM\Index(name: 'idx_tree_family_marriage', columns: ['marriage_min'])]
class Family
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** `@F500028@`, ou `@F9xxxxx@` pour une union créée dans l'application. Identifiant stable (§ 9.8). */
    #[ORM\Column(length: 20, nullable: true)]
    private ?string $gedcomXref = null;

    #[ORM\Column(length: 32, nullable: true, options: ['fixed' => true])]
    private ?string $gedcomUid = null;

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $gedcomRin = null;

    /** Clé étrangère **intra-module** : `Tree` est le seul endroit où elles sont denses. */
    #[ORM\ManyToOne(targetEntity: Person::class)]
    #[ORM\JoinColumn(name: 'husband_id', nullable: true, onDelete: 'SET NULL')]
    private ?Person $husband = null;

    #[ORM\ManyToOne(targetEntity: Person::class)]
    #[ORM\JoinColumn(name: 'wife_id', nullable: true, onDelete: 'SET NULL')]
    private ?Person $wife = null;

    /**
     * 39 familles sur 103 n'ont pas de date de mariage : **une union sans date reste une
     * union** (§ 6.4 du dossier de mission).
     */
    #[ORM\Embedded(class: UncertainDate::class, columnPrefix: 'marriage_')]
    private UncertainDate $marriage;

    /** Le `DIV` du GEDCOM, 2 cas. Même raisonnement que `deceased` : le fait et sa date sont deux choses. */
    #[ORM\Column(options: ['default' => false])]
    private bool $divorced = false;

    /** Couvre aussi la « date de rupture » du § 6.4 du dossier — séparation comme divorce. */
    #[ORM\Embedded(class: UncertainDate::class, columnPrefix: 'divorce_')]
    private UncertainDate $divorce;

    /** Rang générationnel de la famille. Dérivé, écrit par un service de `Tree`. */
    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $generation = null;

    /**
     * Rang vertical du nœud de jonction dans le rendu : dans les données existantes
     * `rank = 2 × generation + 1`, la famille se posant sur une demi-ligne entre les
     * deux générations.
     *
     * Dérivé et persisté par un **service de `Tree`**, comme `branch` et `generation` —
     * le moteur de placement n'écrit rien en base (ADR 0005). La colonne existe pour que
     * le test de non-régression contre `graphe.json` puisse comparer.
     */
    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $rank = null;

    /** Verrou optimiste n° 1 (§ 9.6) : `tree_family` est une cible de `ChangeRequest`. */
    #[ORM\Version]
    #[ORM\Column(type: Types::INTEGER, options: ['default' => 1])]
    private int $version = 1;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(?Person $husband = null, ?Person $wife = null)
    {
        $this->husband = $husband;
        $this->wife = $wife;
        $this->marriage = UncertainDate::inconnue();
        $this->divorce = UncertainDate::inconnue();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getGedcomXref(): ?string
    {
        return $this->gedcomXref;
    }

    public function setGedcomXref(?string $gedcomXref): self
    {
        $this->gedcomXref = $gedcomXref;

        return $this;
    }

    public function getGedcomUid(): ?string
    {
        return $this->gedcomUid;
    }

    public function setGedcomUid(?string $gedcomUid): self
    {
        $this->gedcomUid = $gedcomUid;

        return $this;
    }

    public function getGedcomRin(): ?string
    {
        return $this->gedcomRin;
    }

    public function setGedcomRin(?string $gedcomRin): self
    {
        $this->gedcomRin = $gedcomRin;

        return $this;
    }

    public function getHusband(): ?Person
    {
        return $this->husband;
    }

    public function setHusband(?Person $husband): self
    {
        $this->husband = $husband;

        return $this;
    }

    public function getWife(): ?Person
    {
        return $this->wife;
    }

    public function setWife(?Person $wife): self
    {
        $this->wife = $wife;

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

    public function getGeneration(): ?int
    {
        return $this->generation;
    }

    public function setGeneration(?int $generation): self
    {
        $this->generation = $generation;

        return $this;
    }

    public function getRank(): ?int
    {
        return $this->rank;
    }

    public function setRank(?int $rank): self
    {
        $this->rank = $rank;

        return $this;
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function toucher(): self
    {
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }
}
