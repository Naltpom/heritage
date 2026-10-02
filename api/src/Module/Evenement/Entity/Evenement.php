<?php

declare(strict_types=1);

namespace App\Module\Evenement\Entity;

use App\Module\Evenement\Repository\EvenementRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Un événement de famille : « Cousinade été 2027, du 17 au 18 juillet, chez Tante Ardoise ».
 *
 * Contrairement à un tirage, il n'est **pas récurrent** : une date de début, une date de fin,
 * et c'est fini. Un événement d'un jour a ses deux dates égales — une seule colonne
 * facultative aurait fait de « fin » un cas particulier dans chaque requête et chaque écran.
 *
 * ## Les invités sont des personnes de l'arbre
 *
 * {@see Invitation::$personId} désigne une `tree_person`, pas un compte : la grand-mère sans
 * compte est invitée comme les autres, et répond par son lien personnel. Le compte, quand il
 * existe, se retrouve par le rattachement de `Membership` **au moment où l'on en a besoin**
 * — jamais figé ici : une personne invitée aujourd'hui peut ouvrir son compte demain.
 *
 * ## On annule, on ne supprime pas
 *
 * Un événement annulé reste en base, marqué ({@see self::$annuleLe}) : des invités ont été
 * prévenus, certains avaient répondu, et la notification « événement annulé » mène à une page
 * qui doit encore exister pour dire **qu'il est annulé**. Une suppression ferait tomber ces
 * liens sur « introuvable », ce qui se lit comme une panne.
 *
 * ## Aucune donnée de l'arbre n'est écrite ici
 *
 * Un événement n'est pas une donnée généalogique : il ne passe pas par la file des
 * propositions (ADR 0004), comme les tirages (ADR 0009).
 */
#[ORM\Entity(repositoryClass: EvenementRepository::class)]
#[ORM\Table(name: 'evt_evenement')]
class Evenement
{
    /**
     * Le délai entre deux relances, en heures : une relance par jour, au plus. Au-delà, les
     * notifications deviennent du bruit, et l'invité qui en reçoit trois apprend à les
     * ignorer toutes.
     */
    public const int DELAI_ENTRE_RELANCES_HEURES = 24;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 120)]
    private string $titre;

    /** Le premier jour, sans heure : une cousinade commence « le samedi », pas à 14 h 03. */
    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $debut;

    /** Le dernier jour, inclus. Égal au début pour un événement d'un jour. */
    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $fin;

    #[ORM\Column(length: 160, nullable: true)]
    private ?string $lieu = null;

    /** Texte libre, sur plusieurs lignes : le programme, ce qu'on apporte, où dormir. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    /** ⇢ inter-module (`Core\User`). */
    #[ORM\Column]
    private int $organisateurUserId;

    /** Dénormalisé : le nom affiché du compte organisateur, au moment de la création. */
    #[ORM\Column(length: 180)]
    private string $organisateurLibelle;

    /** `null` : l'événement tient. Sinon, quand l'organisateur l'a annulé. */
    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $annuleLe = null;

    /** La dernière relance envoyée, pour en tenir une par {@see self::DELAI_ENTRE_RELANCES_HEURES}. */
    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $derniereRelanceLe = null;

    /** @var Collection<int, Invitation> */
    #[ORM\OneToMany(targetEntity: Invitation::class, mappedBy: 'evenement', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['personLabel' => 'ASC'])]
    private Collection $invitations;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        string $titre,
        \DateTimeImmutable $debut,
        \DateTimeImmutable $fin,
        int $organisateurUserId,
        string $organisateurLibelle,
    ) {
        $this->titre = $titre;
        $this->debut = $debut;
        $this->fin = $fin;
        $this->organisateurUserId = $organisateurUserId;
        $this->organisateurLibelle = $organisateurLibelle;
        $this->invitations = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function getDebut(): \DateTimeImmutable
    {
        return $this->debut;
    }

    public function getFin(): \DateTimeImmutable
    {
        return $this->fin;
    }

    /** Les deux ensemble : une fin se règle toujours au regard de son début. */
    public function setDates(\DateTimeImmutable $debut, \DateTimeImmutable $fin): self
    {
        $this->debut = $debut;
        $this->fin = $fin;

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

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): self
    {
        $this->description = $description;

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

    public function getAnnuleLe(): ?\DateTimeImmutable
    {
        return $this->annuleLe;
    }

    public function estAnnule(): bool
    {
        return null !== $this->annuleLe;
    }

    public function annuler(\DateTimeImmutable $le): self
    {
        $this->annuleLe = $le;

        return $this;
    }

    /**
     * Le dernier jour est passé. « Aujourd'hui » est un paramètre et non l'horloge : c'est
     * ce qui rend la règle testable, et identique dans toute une requête.
     */
    public function estPasse(\DateTimeImmutable $aujourdhui): bool
    {
        return $this->fin < $aujourdhui->setTime(0, 0);
    }

    public function getDerniereRelanceLe(): ?\DateTimeImmutable
    {
        return $this->derniereRelanceLe;
    }

    /** Le moment à partir duquel une nouvelle relance est permise ; `null` : dès maintenant. */
    public function prochaineRelancePossibleLe(): ?\DateTimeImmutable
    {
        return $this->derniereRelanceLe?->modify(\sprintf('+%d hours', self::DELAI_ENTRE_RELANCES_HEURES));
    }

    public function marquerRelance(\DateTimeImmutable $le): self
    {
        $this->derniereRelanceLe = $le;

        return $this;
    }

    /** @return list<Invitation> */
    public function getInvitations(): array
    {
        return array_values($this->invitations->toArray());
    }

    public function invitation(int $personId): ?Invitation
    {
        foreach ($this->invitations as $invitation) {
            if ($invitation->getPersonId() === $personId) {
                return $invitation;
            }
        }

        return null;
    }

    public function ajouterInvitation(Invitation $invitation): self
    {
        $this->invitations->add($invitation);

        return $this;
    }

    public function retirerInvitation(Invitation $invitation): self
    {
        $this->invitations->removeElement($invitation);

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
