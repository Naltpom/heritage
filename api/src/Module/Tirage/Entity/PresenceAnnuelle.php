<?php

declare(strict_types=1);

namespace App\Module\Tirage\Entity;

use App\Module\Tirage\Enum\Presence;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * La présence d'un participant à l'édition d'une année, quand elle n'est pas le défaut
 * « sur place » (voir {@see Edition}).
 */
#[ORM\Entity]
#[ORM\Table(name: 'trg_presence')]
#[ORM\UniqueConstraint(name: 'uniq_trg_presence_personne', columns: ['edition_id', 'person_id'])]
class PresenceAnnuelle
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Edition::class, inversedBy: 'presences')]
    #[ORM\JoinColumn(name: 'edition_id', nullable: false, onDelete: 'CASCADE')]
    private Edition $edition;

    /** ⇢ inter-module (`Tree\Person`). */
    #[ORM\Column]
    private int $personId;

    #[ORM\Column(length: 12, enumType: Presence::class)]
    private Presence $presence;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(Edition $edition, int $personId, Presence $presence)
    {
        $this->edition = $edition;
        $this->personId = $personId;
        $this->presence = $presence;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEdition(): Edition
    {
        return $this->edition;
    }

    public function getPersonId(): int
    {
        return $this->personId;
    }

    public function getPresence(): Presence
    {
        return $this->presence;
    }

    public function setPresence(Presence $presence): self
    {
        $this->presence = $presence;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
