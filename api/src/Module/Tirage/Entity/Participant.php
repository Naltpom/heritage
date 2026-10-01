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
 *
 * ## Le lien personnel
 *
 * Une personne sans compte suit **son** tirage par un lien `/t/<clé>` que son référent — ou,
 * sans référent, l'organisateur — lui transmet (qui peut le créer : `ServiceDesLiens`). La clé est portée **ici**, sur la ligne du participant, et non
 * dans une table à part, parce que ses trois règles de vie sont celles de cette ligne :
 *
 * - **un seul lien actif** par participant : régénérer écrase l'empreinte, révoquer l'efface ;
 * - il vaut pour **toutes les éditions** : il désigne une personne dans un tirage, pas une année ;
 * - il **meurt avec le participant** — retiré du tirage ou tirage supprimé — sans qu'aucun
 *   code n'ait à y penser : la ligne part, l'empreinte avec.
 *
 * Seule l'**empreinte** est stockée ({@see \App\Module\Tirage\Service\GenerateurDeCle}).
 * La propriété s'appelle `tokenHash` comme celle de `mbr_invitation` **à dessein** : c'est le
 * nom que le journal d'audit masque (`AuditDoctrineSubscriber::CHAMPS_SENSIBLES`), et une
 * empreinte n'a rien à faire en clair dans `core_audit_log`, même si elle ne rend pas la clé.
 */
#[ORM\Entity]
#[ORM\Table(name: 'trg_participant')]
#[ORM\UniqueConstraint(name: 'uniq_trg_participant_personne', columns: ['tirage_id', 'person_id'])]
#[ORM\UniqueConstraint(name: 'uniq_trg_participant_lien', columns: ['lien_token_hash'])]
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

    /**
     * `sha256` hexadécimal de la clé du lien personnel ; `null` : aucun lien actif. L'index
     * unique porte la recherche par clé — voir l'en-tête de `GenerateurDeCle` sur
     * `hash_equals`. PostgreSQL et SQLite y admettent plusieurs `NULL`.
     */
    #[ORM\Column(name: 'lien_token_hash', length: 64, nullable: true)]
    private ?string $tokenHash = null;

    /** Quand le lien actif a été créé ou régénéré — `null` sans lien. */
    #[ORM\Column(name: 'lien_cree_le', type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lienCreeLe = null;

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

    public function aUnLienActif(): bool
    {
        return null !== $this->tokenHash;
    }

    public function getLienCreeLe(): ?\DateTimeImmutable
    {
        return $this->lienCreeLe;
    }

    /** Pose l'empreinte d'une clé neuve : l'ancienne, s'il y en avait une, cesse de valoir. */
    public function poserLien(string $empreinte, \DateTimeImmutable $le): self
    {
        $this->tokenHash = $empreinte;
        $this->lienCreeLe = $le;

        return $this;
    }

    public function revoquerLien(): self
    {
        $this->tokenHash = null;
        $this->lienCreeLe = null;

        return $this;
    }
}
