<?php

declare(strict_types=1);

namespace App\Module\Noel\Entity;

use App\Module\Core\Entity\User;
use App\Module\Noel\Enum\Revelation;
use App\Module\Noel\Enum\StatutDuTirage;
use App\Module\Noel\Repository\TirageNoelRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Un tirage de Noël du site noel2026 : « Noël 2026 chez mamie », un code à partager, une
 * date de tirage, une date de remise des cadeaux et un budget.
 *
 * Contrairement au module `Tirage` (des fiches de l'arbre, des éditions annuelles), ici
 * chacun participe avec **son compte** et le tirage n'a lieu qu'une fois. Tant qu'il est
 * `ouvert`, chacun rejoint, part ou change d'avis ; une fois `tire`, plus rien ne bouge
 * et rien ne l'annule.
 */
#[ORM\Entity(repositoryClass: TirageNoelRepository::class)]
#[ORM\Table(name: 'noel_tirage')]
#[ORM\UniqueConstraint(name: 'uniq_noel_tirage_code', columns: ['code'])]
#[ORM\Index(name: 'idx_noel_tirage_echeance', columns: ['statut', 'date_tirage'])]
class TirageNoel
{
    public const int TITRE_MAX = 120;

    public const int LIEU_MAX = 160;

    public const int MESSAGE_MAX = 2000;

    public const int PRESENTATION_MAX = 5000;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 12)]
    private string $code;

    #[ORM\Column(length: self::TITRE_MAX)]
    private string $titre;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $dateTirage;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $dateEvenement;

    #[ORM\Column]
    private int $budget;

    #[ORM\Column(length: self::LIEU_MAX, nullable: true)]
    private ?string $lieu = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $message = null;

    /**
     * Le texte de présentation, écrit par l'organisateur pour **son** public — avec les
     * balises `{organisateur}`, `{date_tirage}`, `{date_remise}`, `{budget}` et `{lieu}`,
     * que le site remplit. `null` : le texte par défaut du site.
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $presentation = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'createur_user_id', nullable: false, onDelete: 'CASCADE')]
    private User $createur;

    #[ORM\Column(length: 16, enumType: StatutDuTirage::class, options: ['default' => 'ouvert'])]
    private StatutDuTirage $statut = StatutDuTirage::Ouvert;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $tireLe = null;

    /** Le résultat du vote, figé au moment du tirage. */
    #[ORM\Column(length: 16, nullable: true, enumType: Revelation::class)]
    private ?Revelation $revelation = null;

    /** @var Collection<int, Participation> */
    #[ORM\OneToMany(targetEntity: Participation::class, mappedBy: 'tirage', cascade: ['persist'])]
    #[ORM\OrderBy(['id' => \SortDirection::Ascending])]
    private Collection $participations;

    /** @var Collection<int, AttributionNoel> */
    #[ORM\OneToMany(targetEntity: AttributionNoel::class, mappedBy: 'tirage', cascade: ['persist'])]
    private Collection $attributions;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        string $code,
        User $createur,
        string $titre,
        \DateTimeImmutable $dateTirage,
        \DateTimeImmutable $dateEvenement,
        int $budget,
    ) {
        $this->code = $code;
        $this->createur = $createur;
        $this->titre = $titre;
        $this->dateTirage = $dateTirage;
        $this->dateEvenement = $dateEvenement;
        $this->budget = $budget;
        $this->participations = new ArrayCollection();
        $this->attributions = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getTitre(): string
    {
        return $this->titre;
    }

    public function setTitre(string $titre): self
    {
        $this->titre = $titre;

        return $this;
    }

    public function getDateTirage(): \DateTimeImmutable
    {
        return $this->dateTirage;
    }

    public function setDateTirage(\DateTimeImmutable $dateTirage): self
    {
        $this->dateTirage = $dateTirage;

        return $this;
    }

    public function getDateEvenement(): \DateTimeImmutable
    {
        return $this->dateEvenement;
    }

    public function setDateEvenement(\DateTimeImmutable $dateEvenement): self
    {
        $this->dateEvenement = $dateEvenement;

        return $this;
    }

    public function getBudget(): int
    {
        return $this->budget;
    }

    public function setBudget(int $budget): self
    {
        $this->budget = $budget;

        return $this;
    }

    public function getLieu(): ?string
    {
        return $this->lieu;
    }

    public function setLieu(?string $lieu): self
    {
        $this->lieu = $lieu;

        return $this;
    }

    public function getMessage(): ?string
    {
        return $this->message;
    }

    public function setMessage(?string $message): self
    {
        $this->message = $message;

        return $this;
    }

    public function getPresentation(): ?string
    {
        return $this->presentation;
    }

    public function setPresentation(?string $presentation): self
    {
        $this->presentation = $presentation;

        return $this;
    }

    public function getCreateur(): User
    {
        return $this->createur;
    }

    public function estCreePar(User $compte): bool
    {
        return $this->createur->getId() === $compte->getId();
    }

    public function getStatut(): StatutDuTirage
    {
        return $this->statut;
    }

    public function estOuvert(): bool
    {
        return StatutDuTirage::Ouvert === $this->statut;
    }

    public function getTireLe(): ?\DateTimeImmutable
    {
        return $this->tireLe;
    }

    public function getRevelation(): ?Revelation
    {
        return $this->revelation;
    }

    /** La révélation « après l'ouverture » n'ouvre qu'une fois la date de remise passée. */
    public function revelationOuverte(\DateTimeImmutable $maintenant): bool
    {
        return Revelation::ApresOuverture === $this->revelation && $maintenant >= $this->dateEvenement;
    }

    /**
     * @param array<int, int>  $attributions donneur ⇒ receveur, par identifiant de compte
     * @param array<int, User> $comptes      les comptes, par identifiant
     */
    public function marquerTire(array $attributions, array $comptes, Revelation $revelation, \DateTimeImmutable $le): self
    {
        foreach ($attributions as $donneur => $receveur) {
            $this->attributions->add(new AttributionNoel($this, $comptes[$donneur], $comptes[$receveur]));
        }

        $this->statut = StatutDuTirage::Tire;
        $this->revelation = $revelation;
        $this->tireLe = $le;

        return $this->toucher();
    }

    /** @return Collection<int, Participation> */
    public function getParticipations(): Collection
    {
        return $this->participations;
    }

    /** @return list<Participation> les « oui », dans l'ordre d'arrivée */
    public function participants(): array
    {
        return array_values(array_filter(
            $this->participations->toArray(),
            static fn (Participation $p): bool => $p->participe(),
        ));
    }

    public function participationDe(User $compte): ?Participation
    {
        foreach ($this->participations as $participation) {
            if ($participation->getUser()->getId() === $compte->getId()) {
                return $participation;
            }
        }

        return null;
    }

    /** @return Collection<int, AttributionNoel> */
    public function getAttributions(): Collection
    {
        return $this->attributions;
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
