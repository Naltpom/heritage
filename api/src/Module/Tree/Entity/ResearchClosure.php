<?php

declare(strict_types=1);

namespace App\Module\Tree\Entity;

use App\Module\Core\Enum\CoteDeRecherche;
use App\Module\Core\Enum\MotifDeFinDeRecherche;
use App\Module\Core\ValueObject\FinDeRecherche;
use App\Module\Tree\Repository\ResearchClosureRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Une fin de recherche : « on a cherché les parents de ce côté, et les archives s'arrêtent
 * là » (ADR 0023, § 3.5 bis du modèle de données).
 *
 * Une ligne par **côté clos** : pas de ligne, pas de fin de recherche. Rouvrir la recherche
 * supprime la ligne. Pas de `#[ORM\Version]` : l'écriture touche la personne, dont le verrou
 * optimiste est celui de la demande.
 *
 * L'auteur est le **déposant** de la demande et `closedAt` la date de son **dépôt**, jamais
 * ceux de l'administrateur qui valide (même règle que {@see Note}).
 */
#[ORM\Entity(repositoryClass: ResearchClosureRepository::class)]
#[ORM\Table(name: 'tree_research_closure')]
#[ORM\UniqueConstraint(name: 'uniq_tree_research_closure_side', columns: ['person_id', 'side'])]
class ResearchClosure
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Person::class)]
    #[ORM\JoinColumn(name: 'person_id', nullable: false, onDelete: 'CASCADE')]
    private Person $person;

    #[ORM\Column(length: 4, enumType: CoteDeRecherche::class)]
    private CoteDeRecherche $side;

    #[ORM\Column(length: 24, enumType: MotifDeFinDeRecherche::class)]
    private MotifDeFinDeRecherche $reason;

    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $beforeYear = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $body = null;

    /** ⇢ inter-module (`core_user`) : le déposant de la dernière demande qui a écrit la ligne. */
    #[ORM\Column(nullable: true)]
    private ?int $authorUserId = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $authorLabel = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $closedAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(Person $person, CoteDeRecherche $side, FinDeRecherche $valeur, \DateTimeImmutable $closedAt)
    {
        $this->person = $person;
        $this->side = $side;
        $this->reason = $valeur->reason;
        $this->beforeYear = $valeur->beforeYear;
        $this->body = $valeur->body;
        $this->closedAt = $closedAt;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPerson(): Person
    {
        return $this->person;
    }

    public function getSide(): CoteDeRecherche
    {
        return $this->side;
    }

    public function getReason(): MotifDeFinDeRecherche
    {
        return $this->reason;
    }

    public function getBeforeYear(): ?int
    {
        return $this->beforeYear;
    }

    public function getBody(): ?string
    {
        return $this->body;
    }

    public function getAuthorUserId(): ?int
    {
        return $this->authorUserId;
    }

    public function getAuthorLabel(): ?string
    {
        return $this->authorLabel;
    }

    public function getClosedAt(): \DateTimeImmutable
    {
        return $this->closedAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /** La valeur, sans la signature — celle qui circule dans une op. */
    public function valeur(): FinDeRecherche
    {
        return new FinDeRecherche($this->reason, $this->beforeYear, $this->body);
    }

    /** Réécrit la ligne et la re-signe : auteur et date sont ceux de la dernière demande validée. */
    public function reecrire(FinDeRecherche $valeur, ?int $auteurUserId, ?string $auteurLabel, \DateTimeImmutable $closedAt): self
    {
        $this->reason = $valeur->reason;
        $this->beforeYear = $valeur->beforeYear;
        $this->body = $valeur->body;
        $this->authorUserId = $auteurUserId;
        $this->authorLabel = $auteurLabel;
        $this->closedAt = $closedAt;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function signer(?int $auteurUserId, ?string $auteurLabel): self
    {
        $this->authorUserId = $auteurUserId;
        $this->authorLabel = $auteurLabel;

        return $this;
    }
}
