<?php

declare(strict_types=1);

namespace App\Module\Assistant\Entity;

use App\Module\Assistant\Repository\ConversationRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Une conversation de « Poser une question » : la suite des questions qu'un compte pose à
 * l'assistant sur l'arbre, et les réponses.
 *
 * **Elle appartient à un compte, et à lui seul.** `user_id` est un identifiant nu vers
 * `core_user` (ADR 0002, règle 2), sans libellé : personne d'autre que son auteur ne lit
 * une conversation, il n'y a donc rien à afficher de lui. Sous une incarnation (ADR 0010),
 * c'est le compte **incarné** — celui que la session désigne, comme pour toute écriture —,
 * et le journal d'audit porte l'administrateur à côté.
 *
 * ── Ce que la table garde, et pourquoi c'est une table ──────────────────────────
 *
 * Les analyses de l'assistant de proposition vivent deux heures en cache
 * (`MemoireDesAnalyses`) : ce sont des brouillons de demandes, qui portent des `oldValue`
 * de vivants hors cercle et n'ont plus d'usage une fois relus. Une conversation, elle, se
 * **reprend** — « et son père ? » le lendemain — et son auteur veut retrouver ce qu'on lui
 * a répondu. Elle ne contient d'ailleurs rien que l'auteur n'ait eu le droit de lire au
 * moment de la réponse : le modèle n'a vu que son cercle.
 *
 * ── Le titre ne change jamais ───────────────────────────────────────────────────
 *
 * Il est tiré de la première question, à la création, et n'est plus réécrit. Ce n'est pas
 * une économie : le journal mécanique (`AuditDoctrineSubscriber`) écrit le diff
 * `avant`/`apres` de toute modification. Un titre réécrit y recopierait une question en
 * clair — et aucun journal ne doit contenir une question (règle de l'agent). Seul
 * `updatedAt` bouge, et c'est un champ technique que le journal ignore.
 */
#[ORM\Entity(repositoryClass: ConversationRepository::class)]
#[ORM\Table(name: 'ast_conversation')]
#[ORM\Index(name: 'idx_ast_conversation_user', columns: ['user_id', 'updated_at'])]
class Conversation
{
    /** Le titre est la première question, coupée à cette longueur. */
    public const int TITRE_MAXIMAL = 120;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private int $userId;

    #[ORM\Column(length: self::TITRE_MAXIMAL)]
    private string $titre;

    /** @var Collection<int, Echange> */
    #[ORM\OneToMany(targetEntity: Echange::class, mappedBy: 'conversation', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $echanges;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /** La date du dernier échange : c'est elle qui ordonne la liste « mes conversations ». */
    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(int $userId, string $premiereQuestion)
    {
        $this->userId = $userId;
        $this->titre = self::titreDepuis($premiereQuestion);
        $this->echanges = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    /** Une ligne, coupée au dernier mot entier avant la limite, suivie de « … ». */
    public static function titreDepuis(string $question): string
    {
        $ligne = trim((string) preg_replace('/\s+/u', ' ', $question));

        if (mb_strlen($ligne) <= self::TITRE_MAXIMAL) {
            return $ligne;
        }

        $coupe = mb_substr($ligne, 0, self::TITRE_MAXIMAL - 1);
        $espace = mb_strrpos($coupe, ' ');

        return rtrim(false !== $espace && $espace > 40 ? mb_substr($coupe, 0, $espace) : $coupe, " ,;:.").'…';
    }

    public function ajouter(Echange $echange): void
    {
        $this->echanges->add($echange);
        $this->updatedAt = $echange->getCreatedAt();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUserId(): int
    {
        return $this->userId;
    }

    public function getTitre(): string
    {
        return $this->titre;
    }

    /** @return list<Echange> dans l'ordre où ils ont eu lieu */
    public function getEchanges(): array
    {
        return array_values($this->echanges->toArray());
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
