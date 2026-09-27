<?php

declare(strict_types=1);

namespace App\Module\Tirage\Entity;

use App\Module\Tirage\Repository\TirageRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Un tirage au sort **récurrent** : « Noël côté papa, le 24 décembre ».
 *
 * La ligne porte ce qui ne change pas d'une année à l'autre : le nom, le jour, le lieu
 * habituel, les participants et les deux règles du tirage. Chaque année est une
 * {@see Edition}, qui porte sa date réelle, les présences et les attributions.
 *
 * ## Les participants sont des personnes de l'arbre
 *
 * {@see Participant::$personId} désigne une `tree_person`, pas un compte : un enfant ou
 * une grand-mère sans compte participe comme les autres. Le compte sert à **lire** son
 * attribution, et il se retrouve par le rattachement de `Membership`.
 *
 * ## Aucune donnée de l'arbre n'est écrite ici
 *
 * Un tirage n'est pas une donnée généalogique : il ne passe donc pas par la file des
 * propositions (ADR 0004 ; voir l'ADR 0009, qui le dit).
 */
#[ORM\Entity(repositoryClass: TirageRepository::class)]
#[ORM\Table(name: 'trg_tirage')]
class Tirage
{
    public const int MEMOIRE_MAX = 10;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 120)]
    private string $nom;

    /** Le jour habituel de l'événement, sans année : 24 pour le 24 décembre. */
    #[ORM\Column(type: Types::SMALLINT)]
    private int $jour;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $mois;

    #[ORM\Column(length: 160, nullable: true)]
    private ?string $lieu = null;

    /**
     * `true` : tout membre rattaché à l'arbre voit ce tirage et peut le rejoindre.
     * `false` : seul l'organisateur ajoute des participants.
     */
    #[ORM\Column(options: ['default' => false])]
    private bool $ouvert = false;

    /**
     * « Ne pas retomber sur la même personne pendant N tirages. » Relâchée par le tirage
     * quand elle le rend impossible — et l'édition garde ce qui a été appliqué.
     */
    #[ORM\Column(type: Types::SMALLINT, options: ['default' => 2])]
    private int $memoire = 2;

    /** On ne tire pas son conjoint : les couples se lisent dans l'arbre. */
    #[ORM\Column(options: ['default' => true])]
    private bool $exclureConjoints = true;

    /** ⇢ inter-module (`Core\User`). */
    #[ORM\Column]
    private int $organisateurUserId;

    /** Dénormalisé : le nom affiché du compte organisateur, au moment de la création. */
    #[ORM\Column(length: 180)]
    private string $organisateurLibelle;

    /** @var Collection<int, Participant> */
    #[ORM\OneToMany(targetEntity: Participant::class, mappedBy: 'tirage', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['personLabel' => 'ASC'])]
    private Collection $participants;

    /** @var Collection<int, Edition> */
    #[ORM\OneToMany(targetEntity: Edition::class, mappedBy: 'tirage', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['annee' => 'DESC'])]
    private Collection $editions;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $nom, int $jour, int $mois, int $organisateurUserId, string $organisateurLibelle)
    {
        $this->nom = $nom;
        $this->jour = $jour;
        $this->mois = $mois;
        $this->organisateurUserId = $organisateurUserId;
        $this->organisateurLibelle = $organisateurLibelle;
        $this->participants = new ArrayCollection();
        $this->editions = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNom(): string
    {
        return $this->nom;
    }

    public function setNom(string $nom): self
    {
        $this->nom = $nom;

        return $this;
    }

    public function getJour(): int
    {
        return $this->jour;
    }

    public function getMois(): int
    {
        return $this->mois;
    }

    public function setJourEtMois(int $jour, int $mois): self
    {
        $this->jour = $jour;
        $this->mois = $mois;

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

    public function isOuvert(): bool
    {
        return $this->ouvert;
    }

    public function setOuvert(bool $ouvert): self
    {
        $this->ouvert = $ouvert;

        return $this;
    }

    public function getMemoire(): int
    {
        return $this->memoire;
    }

    public function setMemoire(int $memoire): self
    {
        $this->memoire = $memoire;

        return $this;
    }

    public function isExclureConjoints(): bool
    {
        return $this->exclureConjoints;
    }

    public function setExclureConjoints(bool $exclureConjoints): self
    {
        $this->exclureConjoints = $exclureConjoints;

        return $this;
    }

    public function getOrganisateurUserId(): int
    {
        return $this->organisateurUserId;
    }

    public function getOrganisateurLibelle(): string
    {
        return $this->organisateurLibelle;
    }

    /** @return list<Participant> */
    public function getParticipants(): array
    {
        return array_values($this->participants->toArray());
    }

    public function participant(int $personId): ?Participant
    {
        foreach ($this->participants as $participant) {
            if ($participant->getPersonId() === $personId) {
                return $participant;
            }
        }

        return null;
    }

    public function ajouterParticipant(Participant $participant): self
    {
        $this->participants->add($participant);

        return $this;
    }

    public function retirerParticipant(Participant $participant): self
    {
        $this->participants->removeElement($participant);

        // Celui qu'il accompagnait n'a plus de référent : on ne laisse pas pointer un
        // référent vers quelqu'un qui n'est plus du tirage.
        foreach ($this->participants as $autre) {
            if ($autre->getReferentPersonId() === $participant->getPersonId()) {
                $autre->setReferent(null, null);
            }
        }

        return $this;
    }

    /** @return list<Edition> la plus récente d'abord */
    public function getEditions(): array
    {
        $editions = array_values($this->editions->toArray());
        usort($editions, static fn (Edition $a, Edition $b): int => $b->getAnnee() <=> $a->getAnnee());

        return $editions;
    }

    public function edition(int $annee): ?Edition
    {
        foreach ($this->editions as $edition) {
            if ($edition->getAnnee() === $annee) {
                return $edition;
            }
        }

        return null;
    }

    public function ajouterEdition(Edition $edition): self
    {
        $this->editions->add($edition);

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
