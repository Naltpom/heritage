<?php

declare(strict_types=1);

namespace App\Module\Tree\Entity;

use App\Module\Tree\Enum\ChildRelation;
use App\Module\Tree\Repository\ChildRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * La filiation, c'est-à-dire le `CHIL` du GEDCOM — 174 lignes après import (§ 3.3).
 *
 * Table d'association **avec attributs** : l'ordre de fratrie et la nature du lien ne
 * rentrent pas dans un `ManyToMany` nu.
 *
 * **Pas d'unicité sur `person_id` seul**, volontairement : dans les données actuelles
 * chaque personne n'est enfant que d'une famille (174 pour 174), mais une adoption crée
 * légitimement un second rattachement. En revanche l'import **signale** (dans
 * `ged_issue`, sévérité `avertissement`) toute personne qui recevrait deux liens de
 * relation `naissance` : c'est un défaut de données, pas une adoption.
 */
#[ORM\Entity(repositoryClass: ChildRepository::class)]
#[ORM\Table(name: 'tree_child')]
// Interdit d'inscrire deux fois le même enfant dans la même famille — ce qu'un rejeu de
// validation pourrait faire.
#[ORM\UniqueConstraint(name: 'uniq_tree_child', columns: ['family_id', 'person_id'])]
// « Les enfants de cette famille, dans l'ordre » : appelée une fois par famille à chaque
// calcul de placement, soit 103 fois. C'est l'index le plus chaud du module.
#[ORM\Index(name: 'idx_tree_child_fratrie', columns: ['family_id', 'sibling_order'])]
#[ORM\Index(name: 'idx_tree_child_person', columns: ['person_id'])]
class Child
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Family::class)]
    #[ORM\JoinColumn(name: 'family_id', nullable: false, onDelete: 'CASCADE')]
    private Family $family;

    #[ORM\ManyToOne(targetEntity: Person::class)]
    #[ORM\JoinColumn(name: 'person_id', nullable: false, onDelete: 'CASCADE')]
    private Person $person;

    /**
     * Rang dans la fratrie, **dans l'ordre des lignes `CHIL` du GEDCOM**. Le § 7.1 du
     * dossier impose que l'ordre des fratries soit préservé par le moteur de placement :
     * c'est cette colonne qui le porte.
     *
     * Ce n'est **pas** l'ordre des naissances — 50 personnes n'ont aucune date.
     */
    #[ORM\Column(type: Types::SMALLINT, options: ['default' => 0])]
    private int $siblingOrder = 0;

    #[ORM\Column(length: 16, enumType: ChildRelation::class, options: ['default' => 'naissance'])]
    private ChildRelation $relation = ChildRelation::Naissance;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        Family $family,
        Person $person,
        int $siblingOrder = 0,
        ChildRelation $relation = ChildRelation::Naissance,
    ) {
        $this->family = $family;
        $this->person = $person;
        $this->siblingOrder = $siblingOrder;
        $this->relation = $relation;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getFamily(): Family
    {
        return $this->family;
    }

    public function getPerson(): Person
    {
        return $this->person;
    }

    public function getSiblingOrder(): int
    {
        return $this->siblingOrder;
    }

    public function setSiblingOrder(int $siblingOrder): self
    {
        $this->siblingOrder = $siblingOrder;

        return $this;
    }

    public function getRelation(): ChildRelation
    {
        return $this->relation;
    }

    public function setRelation(ChildRelation $relation): self
    {
        $this->relation = $relation;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
