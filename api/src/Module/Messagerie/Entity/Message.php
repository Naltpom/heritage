<?php

declare(strict_types=1);

namespace App\Module\Messagerie\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Un message du fil, **et ce que l'autre côté en a vu**.
 *
 * `vuLe` est l'accusé de lecture : posé la première fois que **l'autre côté** ouvre le
 * fil — le membre pour un message de l'administration, un administrateur pour un message
 * du membre —, jamais modifié ensuite. C'est ce qui permet d'écrire « Vu à 14 h 32 » sous
 * un message, et non « vu depuis » une date qui bougerait à chaque ouverture.
 *
 * `vuParLibelle` nomme l'administrateur qui a lu le message d'un membre ; il reste vide
 * pour un message lu par le membre, qui est seul de son côté.
 */
#[ORM\Entity]
#[ORM\Table(name: 'msg_message')]
class Message
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Fil::class, inversedBy: 'messages')]
    #[ORM\JoinColumn(name: 'fil_id', nullable: false, onDelete: 'CASCADE')]
    private Fil $fil;

    /** Écrit au nom de l'administration (`true`) ou par le membre du fil (`false`). */
    #[ORM\Column(options: ['default' => false])]
    private bool $deLAdministration;

    #[ORM\Column]
    private int $auteurUserId;

    #[ORM\Column(length: 180)]
    private string $auteurLibelle;

    #[ORM\Column(type: Types::TEXT)]
    private string $texte;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $envoyeLe;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $vuLe = null;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $vuParLibelle = null;

    public function __construct(
        Fil $fil,
        bool $deLAdministration,
        int $auteurUserId,
        string $auteurLibelle,
        string $texte,
        ?\DateTimeImmutable $envoyeLe = null,
    ) {
        $this->fil = $fil;
        $this->deLAdministration = $deLAdministration;
        $this->auteurUserId = $auteurUserId;
        $this->auteurLibelle = $auteurLibelle;
        $this->texte = $texte;
        $this->envoyeLe = $envoyeLe ?? new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getFil(): Fil
    {
        return $this->fil;
    }

    public function estDeLAdministration(): bool
    {
        return $this->deLAdministration;
    }

    public function getAuteurUserId(): int
    {
        return $this->auteurUserId;
    }

    public function getAuteurLibelle(): string
    {
        return $this->auteurLibelle;
    }

    public function getTexte(): string
    {
        return $this->texte;
    }

    public function getEnvoyeLe(): \DateTimeImmutable
    {
        return $this->envoyeLe;
    }

    public function getVuLe(): ?\DateTimeImmutable
    {
        return $this->vuLe;
    }

    public function getVuParLibelle(): ?string
    {
        return $this->vuParLibelle;
    }

    public function estVu(): bool
    {
        return null !== $this->vuLe;
    }

    /**
     * Pose l'accusé de lecture, **une fois** : le premier regard compte, les suivants ne
     * déplacent pas l'heure. Rend `true` si quelque chose a changé.
     */
    public function marquerVu(\DateTimeImmutable $le, ?string $par = null): bool
    {
        if (null !== $this->vuLe) {
            return false;
        }

        $this->vuLe = $le;
        $this->vuParLibelle = $par;

        return true;
    }
}
