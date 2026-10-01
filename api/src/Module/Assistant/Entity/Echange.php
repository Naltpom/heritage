<?php

declare(strict_types=1);

namespace App\Module\Assistant\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Une question et sa réponse. **Écrit une fois, jamais modifié** : rien ne passe donc par
 * le diff du journal mécanique, qui n'écrit d'une création que le type et l'identifiant
 * (voir {@see Conversation}).
 *
 * Une question qui n'a pas reçu de réponse — modèle en panne, délai dépassé — **n'est pas
 * gardée** : l'échange n'est écrit qu'au retour de la réponse. Le front garde la question
 * dans son champ, on la repose ; une conversation ne se remplit pas de questions orphelines
 * que le modèle relirait ensuite comme si elles avaient eu une suite.
 *
 * `personnes_citees` : les identifiants des fiches que la réponse cite, **pas leurs
 * noms**. Un nom recopié ici vieillirait mal deux fois — un renommage ne le suivrait pas,
 * et un cercle qui rétrécit (ADR 0008, § 3) le laisserait affiché. Les noms sont relus à
 * chaque lecture, à travers le cercle du moment (`SerialiseurDeConversation`).
 *
 * `reponse` garde en revanche le texte du modèle tel qu'il a été servi, mentions de fiches
 * comprises (`[Marie Becquelin](personne:12)`) : c'est une réponse, et une réponse ne se
 * réécrit pas. Ses mentions sont repassées au cercle à la lecture, comme les identifiants.
 */
#[ORM\Entity]
#[ORM\Table(name: 'ast_echange')]
class Echange
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Conversation::class, inversedBy: 'echanges')]
    #[ORM\JoinColumn(name: 'conversation_id', nullable: false, onDelete: 'CASCADE')]
    private Conversation $conversation;

    #[ORM\Column(type: Types::TEXT)]
    private string $question;

    #[ORM\Column(type: Types::TEXT)]
    private string $reponse;

    /** @var list<int> */
    #[ORM\Column(type: Types::JSONB)]
    private array $personnesCitees;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /**
     * @param list<int> $personnesCitees
     */
    public function __construct(Conversation $conversation, string $question, string $reponse, array $personnesCitees)
    {
        $this->conversation = $conversation;
        $this->question = $question;
        $this->reponse = $reponse;
        $this->personnesCitees = array_values(array_unique($personnesCitees));
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getConversation(): Conversation
    {
        return $this->conversation;
    }

    public function getQuestion(): string
    {
        return $this->question;
    }

    public function getReponse(): string
    {
        return $this->reponse;
    }

    /** @return list<int> */
    public function getPersonnesCitees(): array
    {
        return array_values(array_filter($this->personnesCitees, \is_int(...)));
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
