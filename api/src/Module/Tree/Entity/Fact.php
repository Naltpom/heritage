<?php

declare(strict_types=1);

namespace App\Module\Tree\Entity;

use App\Module\Core\ValueObject\UncertainDate;
use App\Module\Tree\Enum\FactType;
use App\Module\Tree\Repository\FactRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Tous les événements datés **autres que** naissance, décès, mariage et divorce —
 * environ 90 lignes après import (§ 3.4 du modèle de données).
 *
 * Exactement **un** des deux sujets (`person`, `family`) est renseigné : la contrainte
 * `ck_tree_fact_sujet_unique` l'impose avec `num_nonnulls(...) = 1`, qui dit ce qu'on
 * veut dire en une expression là où une conjonction de `IS NULL` devient illisible.
 */
#[ORM\Entity(repositoryClass: FactRepository::class)]
#[ORM\Table(name: 'tree_fact')]
// « Les faits de cette personne, en ordre chronologique » — la frise d'une fiche.
// `happened_min NULLS LAST` est posé à la main dans la migration : les faits non datés
// vont en fin de liste, ce qui est l'ordre attendu à l'écran, et Doctrine ne sait pas
// exprimer le placement des valeurs nulles dans un index.
#[ORM\Index(name: 'idx_tree_fact_person', columns: ['person_id', 'happened_min', 'sort_order'])]
#[ORM\Index(name: 'idx_tree_fact_family', columns: ['family_id'])]
#[ORM\Index(name: 'idx_tree_fact_date', columns: ['happened_min'])]
class Fact
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Person::class)]
    #[ORM\JoinColumn(name: 'person_id', nullable: true, onDelete: 'CASCADE')]
    private ?Person $person = null;

    #[ORM\ManyToOne(targetEntity: Family::class)]
    #[ORM\JoinColumn(name: 'family_id', nullable: true, onDelete: 'CASCADE')]
    private ?Family $family = null;

    #[ORM\Column(length: 24, enumType: FactType::class)]
    private FactType $type;

    /** La valeur textuelle du fait : « Tisserand », « Journalier, briquetier à sa mort ». */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $label = null;

    #[ORM\Embedded(class: UncertainDate::class, columnPrefix: 'happened_')]
    private UncertainDate $happened;

    /** L'`ADDR`/`ADR1` détaillé, distinct du `PLAC` porté par le bloc date. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $address = null;

    /**
     * L'`AGE` du GEDCOM, conservé **en texte** : il vaut parfois « 0y 3m », parfois
     * « INFANT ». En faire un entier inventerait de la précision.
     */
    #[ORM\Column(length: 20, nullable: true)]
    private ?string $age = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $cause = null;

    /** Ordre d'affichage quand deux faits partagent la même date, ou n'en ont pas. */
    #[ORM\Column(type: Types::SMALLINT, options: ['default' => 0])]
    private int $sortOrder = 0;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(FactType $type, ?Person $person = null, ?Family $family = null)
    {
        $this->type = $type;
        $this->person = $person;
        $this->family = $family;
        $this->happened = UncertainDate::inconnue();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPerson(): ?Person
    {
        return $this->person;
    }

    public function setPerson(?Person $person): self
    {
        $this->person = $person;

        return $this;
    }

    public function getFamily(): ?Family
    {
        return $this->family;
    }

    public function setFamily(?Family $family): self
    {
        $this->family = $family;

        return $this;
    }

    public function getType(): FactType
    {
        return $this->type;
    }

    public function setType(FactType $type): self
    {
        $this->type = $type;

        return $this;
    }

    public function getLabel(): ?string
    {
        return $this->label;
    }

    public function setLabel(?string $label): self
    {
        $this->label = $label;

        return $this;
    }

    public function getHappened(): UncertainDate
    {
        return $this->happened;
    }

    public function setHappened(UncertainDate $happened): self
    {
        $this->happened = $happened;

        return $this;
    }

    public function getAddress(): ?string
    {
        return $this->address;
    }

    public function setAddress(?string $address): self
    {
        $this->address = $address;

        return $this;
    }

    public function getAge(): ?string
    {
        return $this->age;
    }

    public function setAge(?string $age): self
    {
        $this->age = $age;

        return $this;
    }

    public function getCause(): ?string
    {
        return $this->cause;
    }

    public function setCause(?string $cause): self
    {
        $this->cause = $cause;

        return $this;
    }

    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }

    public function setSortOrder(int $sortOrder): self
    {
        $this->sortOrder = $sortOrder;

        return $this;
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
