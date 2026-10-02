<?php

declare(strict_types=1);

namespace App\Module\Evenement\Entity;

use App\Module\Evenement\Enum\Reponse;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Une personne de l'arbre invitée à un événement — et ce qu'on sait d'elle depuis : a-t-elle
 * vu l'annonce, a-t-elle répondu.
 *
 * ## Trois horodatages, trois questions
 *
 * - {@see self::$vuLe} — **la première vue**, posée sans que l'invité ait rien à cliquer :
 *   l'ouverture de la page de l'événement, de son lien personnel ou de la notification (qui
 *   mène à la page). Idempotente : une seconde vue ne la déplace pas, sans quoi « vu il y a
 *   deux jours » deviendrait « vu à l'instant » à chaque passage, et l'organisateur ne
 *   saurait plus depuis quand l'annonce attend une réponse.
 * - {@see self::$reponduLe} — la **dernière** réponse : on peut changer d'avis jusqu'à la fin
 *   de l'événement, et c'est l'avis courant qui compte.
 * - {@see self::$relanceLe} — la dernière relance reçue par cette personne.
 *
 * ## Une réponse notée par l'organisateur
 *
 * « Grand-mère a répondu par téléphone » : l'organisateur (ou un administrateur) peut noter
 * la réponse **à la place** de l'invité ({@see self::noterLaReponse()}). Elle est tracée —
 * qui l'a notée ({@see self::$reponseNoteeParUserId}, et son nom dénormalisé pour
 * l'affichage discret « noté par Nathan ») — et elle **ne pose pas la vue** : l'invité n'a
 * rien ouvert, et le suivi ne doit pas dire le contraire. Dès que l'invité répond lui-même,
 * la trace s'efface : la réponse redevient la sienne.
 *
 * « Vu, sans réponse » — le fantôme 👻 — n'est pas une colonne : c'est `vuLe` posé et
 * `reponse` nulle. Le stocker, ce serait devoir le tenir à jour à chaque réponse.
 *
 * ## Le compte n'est pas figé ici
 *
 * Qu'une personne ait un compte se lit au rattachement de `Membership`, au moment où l'on en
 * a besoin. Le figer à l'invitation le rendrait faux le jour où elle ouvre son compte : elle
 * ne recevrait pas la relance, et son lien personnel continuerait de valoir alors que
 * l'organisateur en garde la clé.
 *
 * ## Le lien personnel
 *
 * Une personne **sans compte** répond par un lien `/e/<clé>` que l'organisateur lui
 * transmet. La clé est portée ici, comme celle d'un participant de tirage
 * (`Tirage\Entity\Participant`), et pour les mêmes raisons : un seul lien actif par invité,
 * qui **meurt avec l'invitation** — invité retiré, la ligne part, l'empreinte avec.
 *
 * Seule l'**empreinte** est stockée ({@see \App\Module\Core\Service\GenerateurDeCle}), sous
 * la propriété `tokenHash` — le nom que le journal d'audit masque
 * (`AuditDoctrineSubscriber::CHAMPS_SENSIBLES`).
 */
#[ORM\Entity]
#[ORM\Table(name: 'evt_invitation')]
#[ORM\UniqueConstraint(name: 'uniq_evt_invitation_personne', columns: ['evenement_id', 'person_id'])]
#[ORM\UniqueConstraint(name: 'uniq_evt_invitation_lien', columns: ['lien_token_hash'])]
class Invitation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Evenement::class, inversedBy: 'invitations')]
    #[ORM\JoinColumn(name: 'evenement_id', nullable: false, onDelete: 'CASCADE')]
    private Evenement $evenement;

    /** ⇢ inter-module (`Tree\Person`). */
    #[ORM\Column]
    private int $personId;

    /** Dénormalisé : le nom affiché de la fiche, au moment de l'invitation. */
    #[ORM\Column(length: 255)]
    private string $personLabel;

    /** ⇢ inter-module (`Core\User`) : qui l'a invitée — l'organisateur, ou un administrateur. */
    #[ORM\Column]
    private int $inviteParUserId;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $vuLe = null;

    #[ORM\Column(length: 8, nullable: true, enumType: Reponse::class)]
    private ?Reponse $reponse = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $reponduLe = null;

    /**
     * ⇢ inter-module (`Core\User`) : le compte qui a **noté** la réponse à la place de
     * l'invité ; `null` quand l'invité a répondu lui-même (ou n'a pas répondu).
     */
    #[ORM\Column(nullable: true)]
    private ?int $reponseNoteeParUserId = null;

    /** Dénormalisé, comme l'organisateur : le nom affiché de ce compte, au moment de noter. */
    #[ORM\Column(length: 180, nullable: true)]
    private ?string $reponseNoteeParLibelle = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $relanceLe = null;

    /**
     * `sha256` hexadécimal de la clé du lien personnel ; `null` : aucun lien actif. L'index
     * unique porte la recherche par clé. PostgreSQL et SQLite y admettent plusieurs `NULL`.
     */
    #[ORM\Column(name: 'lien_token_hash', length: 64, nullable: true)]
    private ?string $tokenHash = null;

    /** Quand le lien actif a été créé ou régénéré — `null` sans lien. */
    #[ORM\Column(name: 'lien_cree_le', type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lienCreeLe = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(Evenement $evenement, int $personId, string $personLabel, int $inviteParUserId)
    {
        $this->evenement = $evenement;
        $this->personId = $personId;
        $this->personLabel = $personLabel;
        $this->inviteParUserId = $inviteParUserId;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEvenement(): Evenement
    {
        return $this->evenement;
    }

    public function getPersonId(): int
    {
        return $this->personId;
    }

    public function getPersonLabel(): string
    {
        return $this->personLabel;
    }

    public function getInviteParUserId(): int
    {
        return $this->inviteParUserId;
    }

    public function getVuLe(): ?\DateTimeImmutable
    {
        return $this->vuLe;
    }

    /**
     * La première vue, et elle seule. Rend `true` si elle vient d'être posée — l'appelant
     * sait ainsi s'il a quelque chose à écrire.
     */
    public function marquerVue(\DateTimeImmutable $le): bool
    {
        if (null !== $this->vuLe) {
            return false;
        }

        $this->vuLe = $le;

        return true;
    }

    public function getReponse(): ?Reponse
    {
        return $this->reponse;
    }

    public function getReponduLe(): ?\DateTimeImmutable
    {
        return $this->reponduLe;
    }

    /**
     * Répondre, c'est avoir vu : la vue se pose au passage si elle manquait. La réponse est
     * celle de l'invité : une trace « noté par » antérieure s'efface.
     */
    public function repondre(Reponse $reponse, \DateTimeImmutable $le): self
    {
        $this->marquerVue($le);
        $this->reponse = $reponse;
        $this->reponduLe = $le;
        $this->reponseNoteeParUserId = null;
        $this->reponseNoteeParLibelle = null;

        return $this;
    }

    /**
     * La réponse donnée de vive voix, **notée par l'organisateur** (ou un administrateur).
     *
     * La vue n'est **pas** posée : l'invité n'a pas ouvert l'annonce, et « vu » dans le suivi
     * doit rester ce que l'invité a fait lui-même. La date de réponse, elle, est posée — c'est
     * le moment où la réponse est connue, et `ck_evt_invitation_repondu` l'exige.
     */
    public function noterLaReponse(Reponse $reponse, \DateTimeImmutable $le, int $parUserId, string $parLibelle): self
    {
        $this->reponse = $reponse;
        $this->reponduLe = $le;
        $this->reponseNoteeParUserId = $parUserId;
        $this->reponseNoteeParLibelle = $parLibelle;

        return $this;
    }

    public function getReponseNoteeParUserId(): ?int
    {
        return $this->reponseNoteeParUserId;
    }

    /** « Nathan » : qui a noté la réponse ; `null` quand l'invité l'a donnée lui-même. */
    public function getReponseNoteeParLibelle(): ?string
    {
        return $this->reponseNoteeParLibelle;
    }

    /**
     * La réponse précède le dernier déplacement de l'événement : elle tient toujours, mais
     * l'invité est prié de la confirmer. Répondre de nouveau — même la même chose — l'efface.
     */
    public function estAConfirmer(): bool
    {
        $deplaceLe = $this->evenement->getDatesModifieesLe();

        return null !== $this->reponduLe && null !== $deplaceLe && $this->reponduLe < $deplaceLe;
    }

    public function getRelanceLe(): ?\DateTimeImmutable
    {
        return $this->relanceLe;
    }

    public function marquerRelance(\DateTimeImmutable $le): self
    {
        $this->relanceLe = $le;

        return $this;
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

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
