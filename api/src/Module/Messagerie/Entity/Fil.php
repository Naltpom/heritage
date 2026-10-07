<?php

declare(strict_types=1);

namespace App\Module\Messagerie\Entity;

use App\Module\Messagerie\Enum\NatureDuFil;
use App\Module\Messagerie\Enum\StatutDuFil;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Une conversation entre **un membre** et **l'administration** — une question, une idée,
 * un bogue.
 *
 * **Pourquoi elle existe.** Jusqu'ici, une remarque sur l'application partait par SMS ou
 * de vive voix, et se perdait : l'administrateur ne savait plus qui avait signalé quoi,
 * le membre ne savait pas si on l'avait lu. Un fil garde la question, la réponse, et
 * **dit à chacun ce que l'autre a vu**.
 *
 * Le fil appartient au membre qui l'a ouvert : lui seul le lit, avec les administrateurs.
 * L'administration y répond **d'une seule voix** — n'importe quel administrateur peut
 * répondre, et ce qu'il a lu est lu pour tous.
 *
 * `membreUserId` est un identifiant nu, avec son libellé dénormalisé : ADR 0002, règle 2.
 */
#[ORM\Entity]
#[ORM\Table(name: 'msg_fil')]
#[ORM\Index(name: 'idx_msg_fil_membre', columns: ['membre_user_id'])]
class Fil
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 12, enumType: NatureDuFil::class)]
    private NatureDuFil $nature;

    /** « La carte ne s'affiche pas sur mon téléphone » : ce qu'on lit dans une liste. */
    #[ORM\Column(length: 160)]
    private string $sujet;

    #[ORM\Column(length: 8, enumType: StatutDuFil::class, options: ['default' => 'ouvert'])]
    private StatutDuFil $statut = StatutDuFil::Ouvert;

    #[ORM\Column]
    private int $membreUserId;

    #[ORM\Column(length: 180)]
    private string $membreLibelle;

    /** @var Collection<int, Message> */
    #[ORM\OneToMany(targetEntity: Message::class, mappedBy: 'fil', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['envoyeLe' => 'ASC', 'id' => 'ASC'])]
    private Collection $messages;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /** Le dernier message : c'est par lui que les listes se rangent. */
    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(NatureDuFil $nature, string $sujet, int $membreUserId, string $membreLibelle)
    {
        $this->nature = $nature;
        $this->sujet = $sujet;
        $this->membreUserId = $membreUserId;
        $this->membreLibelle = $membreLibelle;
        $this->messages = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNature(): NatureDuFil
    {
        return $this->nature;
    }

    public function getSujet(): string
    {
        return $this->sujet;
    }

    public function getStatut(): StatutDuFil
    {
        return $this->statut;
    }

    public function getMembreUserId(): int
    {
        return $this->membreUserId;
    }

    public function getMembreLibelle(): string
    {
        return $this->membreLibelle;
    }

    /** @return list<Message> */
    public function getMessages(): array
    {
        $messages = array_values($this->messages->toArray());

        // L'`OrderBy` ne vaut qu'au chargement : un message ajouté dans la requête en cours
        // se range ici, pour que la réponse relue et la page rechargée disent la même chose.
        usort($messages, static fn (Message $a, Message $b): int => [$a->getEnvoyeLe(), $a->getId() ?? \PHP_INT_MAX] <=> [$b->getEnvoyeLe(), $b->getId() ?? \PHP_INT_MAX]);

        return $messages;
    }

    public function getDernierMessageOuNull(): ?Message
    {
        $messages = $this->getMessages();

        return [] === $messages ? null : $messages[\count($messages) - 1];
    }

    public function ajouterMessage(Message $message): void
    {
        $this->messages->add($message);
        $this->updatedAt = $message->getEnvoyeLe();
    }

    public function changerStatut(StatutDuFil $statut): void
    {
        $this->statut = $statut;
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
