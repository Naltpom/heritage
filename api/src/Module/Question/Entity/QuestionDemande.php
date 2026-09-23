<?php

declare(strict_types=1);

namespace App\Module\Question\Entity;

use App\Module\Question\Repository\QuestionDemandeRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Ce qu'une réponse a déposé dans la file de validation — le lien entre une question
 * et les `prp_change_request` qu'elle a produites.
 *
 * ## Pourquoi une table et pas une colonne `demande_id` sur la question
 *
 * Parce qu'**une réponse peut produire deux demandes**, et que c'est le cas utile et
 * non le cas tordu. Un membre à qui l'on demande où est mort son arrière-grand-père
 * répond « à Brest » **et** dépose la photo de l'acte : ce sont deux natures
 * différentes (`ajout_note` et `ajout_media`), donc deux demandes, et il faut qu'elles
 * soient deux — l'administrateur doit pouvoir accepter la photo et refuser le lieu, ou
 * l'inverse. Les fondre en une seule ferait de la file un tout-ou-rien sur des faits
 * sans rapport.
 *
 * Une colonne unique aurait donc perdu une des deux demandes, silencieusement, en
 * écrasant la première par la seconde. Deux colonnes (`demande_texte_id`,
 * `demande_media_id`) auraient tenu aujourd'hui et cassé à la troisième nature.
 *
 * ## Ce que `nature` fait ici
 *
 * C'est la valeur de `Proposal\Enum\RequestKind`, **recopiée en chaîne** au moment du
 * dépôt (§ 0.4 du modèle de données, ADR 0002 règle 2). Elle permet à la page de dire
 * « votre photo a été transmise » plutôt que « demande n° 42 » **sans jointure et sans
 * franchir la frontière** : `Question` n'a pas le droit de nommer `RequestKind`, qui
 * appartient à `Proposal`.
 *
 * Elle est un **instantané**, comme `prp_change_request.author_label` : elle décrit ce
 * qui a été déposé, et ne se recalcule jamais. L'**état** de la demande, lui, n'est
 * pas recopié — il change, et une copie d'un état qui change est une copie fausse. Qui
 * veut l'état va le lire chez `Proposal`.
 *
 * ## Pas de clé étrangère vers `prp_change_request`
 *
 * ADR 0002, règle 2 : aucune relation Doctrine ni contrainte référentielle entre
 * modules. `demande_id` est un identifiant nu.
 *
 * La conséquence est nommée plutôt que subie : **rien en base n'empêche ce lien de
 * pointer vers une demande disparue.** C'est acceptable ici, et pour une raison
 * précise : une demande n'est **jamais supprimée** (ADR 0004 — « elle est l'historique
 * de la discussion familiale autour d'une fiche »), refus et caducité compris. Le seul
 * chemin qui effacerait la ligne est un `DELETE` manuel en base, que l'ADR 0004 traite
 * ailleurs. Si ce jour arrive, le lien pendant se **lit** — il ne se répare pas —, et
 * la page affichera le libellé recopié sans lien cliquable plutôt qu'une erreur.
 */
#[ORM\Entity(repositoryClass: QuestionDemandeRepository::class)]
#[ORM\Table(name: 'qst_question_demande')]
// La même demande ne peut être rattachée qu'une fois à la même question : un double
// envoi du formulaire déposerait sinon deux liens pour un seul dépôt.
#[ORM\UniqueConstraint(name: 'uniq_qst_question_demande', columns: ['question_id', 'demande_id'])]
// Le chemin inverse : « de quelle question vient cette demande ? », que la file
// d'administration affiche pour donner son contexte à une contribution.
#[ORM\Index(name: 'idx_qst_question_demande_demande', columns: ['demande_id'])]
class QuestionDemande
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** FK **intra-module**, la seule de ce module. */
    #[ORM\ManyToOne(targetEntity: Question::class, inversedBy: 'demandes')]
    #[ORM\JoinColumn(name: 'question_id', nullable: false, onDelete: 'CASCADE')]
    private Question $question;

    /** ⇢ inter-module (`Proposal\ChangeRequest`). Identifiant nu, sans clé étrangère. */
    #[ORM\Column]
    private int $demandeId;

    /** Valeur de `Proposal\Enum\RequestKind`, recopiée. Instantané : voir l'en-tête. */
    #[ORM\Column(length: 24)]
    private string $nature;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(Question $question, int $demandeId, string $nature)
    {
        $this->question = $question;
        $this->demandeId = $demandeId;
        $this->nature = $nature;
        $this->createdAt = new \DateTimeImmutable();

        $question->attacherLaDemande($this);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getQuestion(): Question
    {
        return $this->question;
    }

    public function getDemandeId(): int
    {
        return $this->demandeId;
    }

    public function getNature(): string
    {
        return $this->nature;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
