<?php

declare(strict_types=1);

namespace App\Module\Tree\Entity;

use App\Module\Tree\Enum\NoteScope;
use App\Module\Tree\Repository\NoteRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Une note de recherche — 175 lignes après import (§ 3.5 du modèle de données).
 *
 * **Ce sont les lignes les plus précieuses du fichier** et les plus faciles à perdre :
 * elles contiennent le raisonnement derrière les corrections (« MyHeritage avait recopié
 * l'année de décès dans le champ naissance »), les décisions de caviardage (« CONSIGNÉE
 * POUR NE PAS ÊTRE REDÉFAITE ») et les faisceaux d'indices non conclus. 116 d'entre
 * elles avaient déjà été perdues une fois par un exporteur négligent.
 *
 * Les 14 `NOTE` portées par des enregistrements `SOUR` ne viennent **pas** ici : elles
 * vont dans {@see Source::$note}.
 *
 * Piège d'import à ne pas inverser : `CONC` = suite du mot, recollée sans séparateur ;
 * `CONT` = ligne suivante, qui devient un `\n`.
 */
#[ORM\Entity(repositoryClass: NoteRepository::class)]
#[ORM\Table(name: 'tree_note')]
#[ORM\Index(name: 'idx_tree_note_person', columns: ['person_id', 'scope'])]
#[ORM\Index(name: 'idx_tree_note_family', columns: ['family_id'])]
#[ORM\Index(name: 'idx_tree_note_fact', columns: ['fact_id'])]
class Note
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

    /** Exactement un des trois sujets est renseigné (`ck_tree_note_sujet_unique`). */
    #[ORM\ManyToOne(targetEntity: Fact::class)]
    #[ORM\JoinColumn(name: 'fact_id', nullable: true, onDelete: 'CASCADE')]
    private ?Fact $fact = null;

    /**
     * Résout le problème posé par le découpage du § 3.1 : `1 BIRT / 2 NOTE` devient une
     * note de portée `Naissance` rattachée à la personne, puisque la naissance n'a pas
     * de ligne à elle.
     */
    #[ORM\Column(length: 16, enumType: NoteScope::class, options: ['default' => 'generale'])]
    private NoteScope $scope = NoteScope::Generale;

    #[ORM\Column(type: Types::TEXT)]
    private string $body;

    /** ⇢ inter-module (`core_user`). `null` pour les notes importées du GEDCOM. */
    #[ORM\Column(nullable: true)]
    private ?int $authorUserId = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $authorLabel = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $body, NoteScope $scope = NoteScope::Generale)
    {
        $this->body = $body;
        $this->scope = $scope;
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

    public function getFact(): ?Fact
    {
        return $this->fact;
    }

    public function setFact(?Fact $fact): self
    {
        $this->fact = $fact;

        return $this;
    }

    public function getScope(): NoteScope
    {
        return $this->scope;
    }

    public function setScope(NoteScope $scope): self
    {
        $this->scope = $scope;

        return $this;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function setBody(string $body): self
    {
        $this->body = $body;

        return $this;
    }

    public function getAuthorUserId(): ?int
    {
        return $this->authorUserId;
    }

    public function getAuthorLabel(): ?string
    {
        return $this->authorLabel;
    }

    public function setAuthor(?int $userId, ?string $label): self
    {
        $this->authorUserId = $userId;
        $this->authorLabel = $label;

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
