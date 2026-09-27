<?php

declare(strict_types=1);

namespace App\Module\Tirage\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * « Untel offre à Unetelle », pour une édition.
 *
 * ## Le secret est la raison d'être de cette table
 *
 * Aucune route ne liste les attributions d'une édition : chacun ne lit que la sienne, et
 * celle des participants dont il est le référent. **L'organisateur ne les voit pas**, ni
 * un administrateur — ils participent souvent eux-mêmes, et un tirage que l'organisateur
 * connaît n'est plus un tirage pour lui. Seule exception, tracée : sous la bascule « voir
 * comme un membre », l'administrateur lit celle **de ce membre**, comme lui (ADR 0009).
 * La table sert aussi de **mémoire** : c'est elle
 * qu'on relit pour ne pas retomber sur la même personne d'une année sur l'autre.
 *
 * Les deux index uniques disent l'invariant : chacun offre une fois, chacun reçoit une
 * fois.
 */
#[ORM\Entity]
#[ORM\Table(name: 'trg_attribution')]
#[ORM\UniqueConstraint(name: 'uniq_trg_attribution_donneur', columns: ['edition_id', 'donneur_person_id'])]
#[ORM\UniqueConstraint(name: 'uniq_trg_attribution_receveur', columns: ['edition_id', 'receveur_person_id'])]
class Attribution
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Edition::class, inversedBy: 'attributions')]
    #[ORM\JoinColumn(name: 'edition_id', nullable: false, onDelete: 'CASCADE')]
    private Edition $edition;

    /** ⇢ inter-module (`Tree\Person`). */
    #[ORM\Column]
    private int $donneurPersonId;

    #[ORM\Column(length: 255)]
    private string $donneurLabel;

    /** ⇢ inter-module (`Tree\Person`). */
    #[ORM\Column]
    private int $receveurPersonId;

    #[ORM\Column(length: 255)]
    private string $receveurLabel;

    public function __construct(
        Edition $edition,
        int $donneurPersonId,
        string $donneurLabel,
        int $receveurPersonId,
        string $receveurLabel,
    ) {
        $this->edition = $edition;
        $this->donneurPersonId = $donneurPersonId;
        $this->donneurLabel = $donneurLabel;
        $this->receveurPersonId = $receveurPersonId;
        $this->receveurLabel = $receveurLabel;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEdition(): Edition
    {
        return $this->edition;
    }

    public function getDonneurPersonId(): int
    {
        return $this->donneurPersonId;
    }

    public function getDonneurLabel(): string
    {
        return $this->donneurLabel;
    }

    public function getReceveurPersonId(): int
    {
        return $this->receveurPersonId;
    }

    public function getReceveurLabel(): string
    {
        return $this->receveurLabel;
    }
}
