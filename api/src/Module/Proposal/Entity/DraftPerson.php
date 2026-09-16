<?php

declare(strict_types=1);

namespace App\Module\Proposal\Entity;

use App\Module\Core\Enum\Sex;
use App\Module\Core\ValueObject\UncertainDate;
use App\Module\Proposal\Repository\DraftPersonRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * La fiche proposée, tant qu'elle n'a pas d'identifiant (§ 5.3 du modèle de données).
 *
 * **Ses colonnes sont exactement le sous-ensemble éditable de `tree_person`**, avec les
 * mêmes noms et les mêmes types. Ce n'est pas de la paresse : la validation devient une
 * copie colonne à colonne, sans table de correspondance — et une table de correspondance
 * entre deux jeux de noms est l'endroit où les champs se perdent en silence quand on en
 * ajoute un.
 *
 * Pourquoi une table plutôt qu'une liste d'ops sur un sujet inexistant : c'est le piège
 * classique de ce genre de modèle. On se retrouverait à reconstituer une fiche à partir
 * d'un sac de paires clé-valeur à chaque rendu du graphe, et la première valeur manquante
 * deviendrait un `null` silencieux dans l'arbre.
 */
#[ORM\Entity(repositoryClass: DraftPersonRepository::class)]
#[ORM\Table(name: 'prp_draft_person')]
// Garantit qu'une référence provisoire désigne une seule fiche dans la demande. C'est la
// contrainte sur laquelle repose toute la résolution d'identifiants du § 9.5.
#[ORM\UniqueConstraint(name: 'uniq_prp_draft_person_ref', columns: ['request_id', 'local_ref'])]
// « Cette personne vient-elle d'une demande, et laquelle ? » — encart de provenance.
#[ORM\Index(
    name: 'idx_prp_draft_person_resolved',
    columns: ['resolved_person_id'],
    options: ['where' => '(resolved_person_id IS NOT NULL)'],
)]
class DraftPerson
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ChangeRequest::class)]
    #[ORM\JoinColumn(name: 'request_id', nullable: false, onDelete: 'CASCADE')]
    private ChangeRequest $request;

    /**
     * L'identifiant **provisoire** : `#p1`, `#p2`… Unique dans la demande.
     *
     * Une personne n'a pas d'identifiant tant qu'elle n'est pas validée ; tout ce qui la
     * référence (liens, médias) utilise cette référence jusqu'à la validation, qui la
     * résout. `#p1` de la demande 12 et `#p1` de la demande 13 ne désignent pas la même
     * personne — d'où l'unicité **par demande** et non globale.
     */
    #[ORM\Column(length: 8)]
    private string $localRef;

    #[ORM\Column(length: 160, options: ['default' => ''])]
    private string $givenNames = '';

    #[ORM\Column(length: 120, options: ['default' => ''])]
    private string $surname = '';

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $marriedName = null;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $nickname = null;

    #[ORM\Column(length: 10, enumType: Sex::class, options: ['default' => 'inconnu'])]
    private Sex $sex = Sex::Inconnu;

    #[ORM\Column(options: ['default' => false])]
    private bool $deceased = false;

    #[ORM\Embedded(class: UncertainDate::class, columnPrefix: 'birth_')]
    private UncertainDate $birth;

    #[ORM\Embedded(class: UncertainDate::class, columnPrefix: 'death_')]
    private UncertainDate $death;

    /** La note de recherche jointe par le demandeur — elle deviendra une `tree_note` de portée générale. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $note = null;

    /**
     * ⇢ inter-module (`Tree\Person`). Rempli **à la validation**.
     *
     * C'est ce qui permet, six mois plus tard, d'ouvrir la demande n° 12 et d'arriver sur
     * la fiche réellement créée. Sans cette colonne, une demande validée perd le lien
     * vers son résultat.
     */
    #[ORM\Column(nullable: true)]
    private ?int $resolvedPersonId = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(ChangeRequest $request, string $localRef)
    {
        $this->request = $request;
        $this->localRef = $localRef;
        $this->birth = UncertainDate::inconnue();
        $this->death = UncertainDate::inconnue();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRequest(): ChangeRequest
    {
        return $this->request;
    }

    public function getLocalRef(): string
    {
        return $this->localRef;
    }

    public function getGivenNames(): string
    {
        return $this->givenNames;
    }

    public function setGivenNames(string $givenNames): self
    {
        $this->givenNames = $givenNames;

        return $this;
    }

    public function getSurname(): string
    {
        return $this->surname;
    }

    public function setSurname(string $surname): self
    {
        $this->surname = $surname;

        return $this;
    }

    public function getMarriedName(): ?string
    {
        return $this->marriedName;
    }

    public function setMarriedName(?string $marriedName): self
    {
        $this->marriedName = $marriedName;

        return $this;
    }

    public function getNickname(): ?string
    {
        return $this->nickname;
    }

    public function setNickname(?string $nickname): self
    {
        $this->nickname = $nickname;

        return $this;
    }

    public function getSex(): Sex
    {
        return $this->sex;
    }

    public function setSex(Sex $sex): self
    {
        $this->sex = $sex;

        return $this;
    }

    public function isDeceased(): bool
    {
        return $this->deceased;
    }

    public function setDeceased(bool $deceased): self
    {
        $this->deceased = $deceased;

        return $this;
    }

    public function getBirth(): UncertainDate
    {
        return $this->birth;
    }

    public function setBirth(UncertainDate $birth): self
    {
        $this->birth = $birth;

        return $this;
    }

    public function getDeath(): UncertainDate
    {
        return $this->death;
    }

    public function setDeath(UncertainDate $death): self
    {
        $this->death = $death;

        return $this;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function setNote(?string $note): self
    {
        $this->note = $note;

        return $this;
    }

    /**
     * Le nom de cette fiche proposée — **la seule composition**, et elle est ici parce
     * que trois services en avaient besoin.
     *
     * `ConstructeurDeCreation` la pose dans les libellés dénormalisés des liens,
     * `SerialiseurDeDemande` la rend à l'écran de validation, `LectureDesPropositions`
     * la met sur le nœud du graphe. Elle était écrite deux fois et absente de la
     * troisième : les liens portaient « Personne proposée #p1 », c'est-à-dire un numéro
     * de ligne là où l'administrateur devait lire un nom pour décider.
     *
     * **C'est un repli, jamais la vérité.** `tree_person.display_name` est une colonne
     * dérivée que `Tree` compose selon sa propre règle ; celle-ci ne vaut que tant que la
     * personne n'existe pas. Elle ne porte donc **pas** la mention « proposée » : un
     * libellé stocké est relu par l'applicateur au moment même où la personne cesse de
     * l'être, et l'historique dirait « Girolle Chanterelle (proposée) » d'une fiche validée.
     * La mention est posée à la sérialisation, là où elle est vraie.
     *
     * `'(sans nom)'` est inatteignable pour une fiche déposée aujourd'hui — le
     * constructeur exige un nom ou un prénom, et `ck_prp_draft_person_nom` le redit en
     * base. Le repli reste pour les quatre individus sans `NAME` qu'un import GEDCOM
     * pourrait un jour faire passer par ici : une ligne vide à l'écran de décision serait
     * pire qu'une mention explicite.
     */
    public function nomPropose(): string
    {
        $parties = array_filter(
            [$this->givenNames, $this->surname],
            static fn (string $partie): bool => '' !== trim($partie),
        );

        return '' === ($nom = implode(' ', $parties)) ? '(sans nom)' : $nom;
    }

    public function getResolvedPersonId(): ?int
    {
        return $this->resolvedPersonId;
    }

    public function setResolvedPersonId(?int $resolvedPersonId): self
    {
        $this->resolvedPersonId = $resolvedPersonId;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
