<?php

declare(strict_types=1);

namespace App\Module\Noel\Entity;

use App\Module\Core\Entity\User;
use App\Module\Noel\Enum\Presence;
use App\Module\Noel\Enum\Reponse;
use App\Module\Noel\Enum\Revelation;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * La réponse d'un compte à un tirage de Noël : participe-t-il, sera-t-il présent, que
 * vote-t-il pour la révélation, et qui est son conjoint — qu'il ne tirera pas.
 */
#[ORM\Entity]
#[ORM\Table(name: 'noel_participation')]
#[ORM\UniqueConstraint(name: 'uniq_noel_participation_compte', columns: ['tirage_id', 'user_id'])]
#[ORM\Index(name: 'idx_noel_participation_compte', columns: ['user_id'])]
class Participation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: TirageNoel::class, inversedBy: 'participations')]
    #[ORM\JoinColumn(name: 'tirage_id', nullable: false, onDelete: 'CASCADE')]
    private TirageNoel $tirage;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 8, enumType: Reponse::class)]
    private Reponse $reponse;

    #[ORM\Column(length: 16, nullable: true, enumType: Presence::class)]
    private ?Presence $presence = null;

    #[ORM\Column(length: 16, nullable: true, enumType: Revelation::class)]
    private ?Revelation $vote = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'conjoint_user_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $conjoint = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(TirageNoel $tirage, User $user, Reponse $reponse)
    {
        $this->tirage = $tirage;
        $this->user = $user;
        $this->reponse = $reponse;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTirage(): TirageNoel
    {
        return $this->tirage;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getReponse(): Reponse
    {
        return $this->reponse;
    }

    public function participe(): bool
    {
        return Reponse::Oui === $this->reponse;
    }

    public function getPresence(): ?Presence
    {
        return $this->presence;
    }

    public function getVote(): ?Revelation
    {
        return $this->vote;
    }

    public function getConjoint(): ?User
    {
        return $this->conjoint;
    }

    public function repondre(Reponse $reponse, ?Presence $presence, ?Revelation $vote, ?User $conjoint): self
    {
        $this->reponse = $reponse;
        // Un « non » n'a ni présence, ni vote, ni conjoint : il ne compte nulle part.
        $this->presence = Reponse::Oui === $reponse ? $presence : null;
        $this->vote = Reponse::Oui === $reponse ? $vote : null;
        $this->conjoint = Reponse::Oui === $reponse ? $conjoint : null;
        $this->updatedAt = new \DateTimeImmutable();

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
}
