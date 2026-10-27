<?php

declare(strict_types=1);

namespace App\Module\Noel\Entity;

use App\Module\Core\Entity\User;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** Le résultat du tirage : `donneur` offre un cadeau à `receveur`. */
#[ORM\Entity]
#[ORM\Table(name: 'noel_attribution')]
#[ORM\UniqueConstraint(name: 'uniq_noel_attribution_donneur', columns: ['tirage_id', 'donneur_user_id'])]
#[ORM\UniqueConstraint(name: 'uniq_noel_attribution_receveur', columns: ['tirage_id', 'receveur_user_id'])]
class AttributionNoel
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: TirageNoel::class, inversedBy: 'attributions')]
    #[ORM\JoinColumn(name: 'tirage_id', nullable: false, onDelete: 'CASCADE')]
    private TirageNoel $tirage;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'donneur_user_id', nullable: false, onDelete: 'CASCADE')]
    private User $donneur;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'receveur_user_id', nullable: false, onDelete: 'CASCADE')]
    private User $receveur;

    /** La première fois que le donneur a ouvert sa carte : l'organisateur voit qui l'a vue, jamais le nom tiré. */
    #[ORM\Column(name: 'vue_le', type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $vueLe = null;

    public function __construct(TirageNoel $tirage, User $donneur, User $receveur)
    {
        $this->tirage = $tirage;
        $this->donneur = $donneur;
        $this->receveur = $receveur;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTirage(): TirageNoel
    {
        return $this->tirage;
    }

    public function getDonneur(): User
    {
        return $this->donneur;
    }

    public function getReceveur(): User
    {
        return $this->receveur;
    }

    public function getVueLe(): ?\DateTimeImmutable
    {
        return $this->vueLe;
    }

    public function marquerVue(\DateTimeImmutable $le): self
    {
        $this->vueLe ??= $le;

        return $this;
    }
}
