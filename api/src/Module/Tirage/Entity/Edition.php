<?php

declare(strict_types=1);

namespace App\Module\Tirage\Entity;

use App\Module\Tirage\Enum\Presence;
use App\Module\Tirage\Enum\StatutDEdition;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * L'édition d'une année : sa date, qui vient, et — une fois tirée — qui offre à qui.
 *
 * ## Les présences ne sont écrites que lorsqu'elles diffèrent du défaut
 *
 * Un participant sans ligne {@see Presence} est **sur place**. On n'écrit pas une ligne
 * par participant à l'ouverture de l'édition : un participant ajouté ensuite n'en aurait
 * pas, et il faudrait de toute façon savoir lire l'absence de ligne.
 *
 * ## Le tirage est tout ou rien
 *
 * Les attributions se posent ensemble, dans la même transaction que le passage à
 * {@see StatutDEdition::Tiree}, et s'effacent ensemble quand l'organisateur annule. Une
 * édition tirée à moitié n'a pas de sens : chacun reçoit exactement un cadeau.
 */
#[ORM\Entity]
#[ORM\Table(name: 'trg_edition')]
#[ORM\UniqueConstraint(name: 'uniq_trg_edition_annee', columns: ['tirage_id', 'annee'])]
class Edition
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Tirage::class, inversedBy: 'editions')]
    #[ORM\JoinColumn(name: 'tirage_id', nullable: false, onDelete: 'CASCADE')]
    private Tirage $tirage;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $annee;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $date;

    #[ORM\Column(length: 12, enumType: StatutDEdition::class, options: ['default' => 'ouverte'])]
    private StatutDEdition $statut = StatutDEdition::Ouverte;

    /**
     * La mémoire **réellement appliquée** au tirage, qui peut être inférieure à celle du
     * tirage quand celle-ci le rendait impossible. `null` tant que l'édition n'est pas tirée.
     */
    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $memoireAppliquee = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $tireeLe = null;

    /** @var Collection<int, PresenceAnnuelle> */
    #[ORM\OneToMany(targetEntity: PresenceAnnuelle::class, mappedBy: 'edition', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $presences;

    /** @var Collection<int, Attribution> */
    #[ORM\OneToMany(targetEntity: Attribution::class, mappedBy: 'edition', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $attributions;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(Tirage $tirage, int $annee, \DateTimeImmutable $date)
    {
        $this->tirage = $tirage;
        $this->annee = $annee;
        $this->date = $date;
        $this->presences = new ArrayCollection();
        $this->attributions = new ArrayCollection();
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

    public function getAnnee(): int
    {
        return $this->annee;
    }

    public function getDate(): \DateTimeImmutable
    {
        return $this->date;
    }

    public function setDate(\DateTimeImmutable $date): self
    {
        $this->date = $date;

        return $this;
    }

    public function getStatut(): StatutDEdition
    {
        return $this->statut;
    }

    public function estTiree(): bool
    {
        return StatutDEdition::Tiree === $this->statut;
    }

    public function getMemoireAppliquee(): ?int
    {
        return $this->memoireAppliquee;
    }

    public function getTireeLe(): ?\DateTimeImmutable
    {
        return $this->tireeLe;
    }

    /** Le défaut est « sur place » : voir l'en-tête. */
    public function presenceDe(int $personId): Presence
    {
        foreach ($this->presences as $presence) {
            if ($presence->getPersonId() === $personId) {
                return $presence->getPresence();
            }
        }

        return Presence::SurPlace;
    }

    public function definirPresence(int $personId, Presence $presence): self
    {
        foreach ($this->presences as $ligne) {
            if ($ligne->getPersonId() === $personId) {
                $ligne->setPresence($presence);

                return $this;
            }
        }

        $this->presences->add(new PresenceAnnuelle($this, $personId, $presence));

        return $this;
    }

    /** @return list<Attribution> */
    public function getAttributions(): array
    {
        return array_values($this->attributions->toArray());
    }

    public function attributionDe(int $donneurPersonId): ?Attribution
    {
        foreach ($this->attributions as $attribution) {
            if ($attribution->getDonneurPersonId() === $donneurPersonId) {
                return $attribution;
            }
        }

        return null;
    }

    /** @param list<Attribution> $attributions */
    public function marquerTiree(array $attributions, int $memoireAppliquee, \DateTimeImmutable $le): self
    {
        $this->attributions->clear();

        foreach ($attributions as $attribution) {
            $this->attributions->add($attribution);
        }

        $this->statut = StatutDEdition::Tiree;
        $this->memoireAppliquee = $memoireAppliquee;
        $this->tireeLe = $le;

        return $this;
    }

    public function annulerLeTirage(): self
    {
        $this->attributions->clear();
        $this->statut = StatutDEdition::Ouverte;
        $this->memoireAppliquee = null;
        $this->tireeLe = null;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
