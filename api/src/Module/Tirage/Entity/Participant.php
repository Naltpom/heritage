<?php

declare(strict_types=1);

namespace App\Module\Tirage\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Une personne de l'arbre qui prend part à un tirage, d'une année sur l'autre.
 *
 * ## Le référent
 *
 * Un enfant n'a pas de compte ; il tire pourtant quelqu'un, et il faut bien que quelqu'un
 * le lise. Le référent est un **autre participant** — un parent, en pratique — qui voit
 * l'attribution de celui qu'il accompagne en plus de la sienne. Il est désigné par sa
 * personne, pas par son compte, pour la même raison que le participant lui-même.
 */
#[ORM\Entity]
#[ORM\Table(name: 'trg_participant')]
#[ORM\UniqueConstraint(name: 'uniq_trg_participant_personne', columns: ['tirage_id', 'person_id'])]
class Participant
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Tirage::class, inversedBy: 'participants')]
    #[ORM\JoinColumn(name: 'tirage_id', nullable: false, onDelete: 'CASCADE')]
    private Tirage $tirage;

    /** ⇢ inter-module (`Tree\Person`). */
    #[ORM\Column]
    private int $personId;

    /** Dénormalisé : le nom affiché de la fiche, au moment de l'ajout. */
    #[ORM\Column(length: 255)]
    private string $personLabel;

    /** ⇢ inter-module (`Tree\Person`), et participant du même tirage. */
    #[ORM\Column(nullable: true)]
    private ?int $referentPersonId = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $referentLabel = null;

    /** ⇢ inter-module (`Core\User`) : qui l'a ajouté — l'organisateur, ou lui-même. */
    #[ORM\Column]
    private int $ajouteParUserId;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(Tirage $tirage, int $personId, string $personLabel, int $ajouteParUserId)
    {
        $this->tirage = $tirage;
        $this->personId = $personId;
        $this->personLabel = $personLabel;
        $this->ajouteParUserId = $ajouteParUserId;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTirage(): Tirage
    {
        return $this->tirage;
    }

    public function getPersonId(): int
    {
        return $this->personId;
    }

    public function getPersonLabel(): string
    {
        return $this->personLabel;
    }

    public function getReferentPersonId(): ?int
    {
        return $this->referentPersonId;
    }

    public function getReferentLabel(): ?string
    {
        return $this->referentLabel;
    }

    public function setReferent(?int $personId, ?string $libelle): self
    {
        $this->referentPersonId = $personId;
        $this->referentLabel = null === $personId ? null : $libelle;

        return $this;
    }

    public function getAjouteParUserId(): int
    {
        return $this->ajouteParUserId;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
