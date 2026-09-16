<?php

declare(strict_types=1);

namespace App\Module\Tree\Entity;

use App\Module\Tree\Enum\CitationContext;
use App\Module\Tree\Enum\CitationQuality;
use App\Module\Tree\Enum\NoteScope;
use App\Module\Tree\Repository\CitationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * **L'usage d'une source sur un fait précis**, avec sa page et son `QUAY` — 483 lignes
 * après import (§ 3.7 du modèle de données).
 *
 * Le fichier porte 484 lignes `1 SOUR`, dont **une dans l'enregistrement `HEAD`** :
 * celle-là décrit la provenance du fichier, pas un fait, et n'est pas une citation.
 * Restent 483, ventilées en 414 sur des `INDI` et 69 sur des `FAM`.
 *
 * C'est la table qui porte la fiabilité, et c'est elle qui répondra un jour à « ces 40
 * fiches reposent sur la même recopie non vérifiée » (§ 9.7).
 */
#[ORM\Entity(repositoryClass: CitationRepository::class)]
#[ORM\Table(name: 'tree_citation')]
#[ORM\Index(name: 'idx_tree_citation_person', columns: ['person_id', 'scope'])]
// **La requête du § 9.7** : « quelles fiches reposent sur cette source, et avec quelle
// confiance ». Composite dans cet ordre parce que la question se pose toujours ainsi :
// d'abord la source, puis le tri par fiabilité.
#[ORM\Index(name: 'idx_tree_citation_source', columns: ['source_id', 'quay'])]
#[ORM\Index(name: 'idx_tree_citation_family', columns: ['family_id'])]
#[ORM\Index(name: 'idx_tree_citation_fact', columns: ['fact_id'])]
// Écran « ce qui repose sur du vent » : 220 citations sur 414 côté personnes.
#[ORM\Index(
    name: 'idx_tree_citation_douteuses',
    columns: ['quay'],
    options: ['where' => '(quay = 0)'],
)]
class Citation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * `RESTRICT` et pas `CASCADE` : supprimer une source qui étaye 161 citations doit
     * être **refusé**, pas exécuté.
     */
    #[ORM\ManyToOne(targetEntity: Source::class)]
    #[ORM\JoinColumn(name: 'source_id', nullable: false, onDelete: 'RESTRICT')]
    private Source $source;

    #[ORM\ManyToOne(targetEntity: Person::class)]
    #[ORM\JoinColumn(name: 'person_id', nullable: true, onDelete: 'CASCADE')]
    private ?Person $person = null;

    #[ORM\ManyToOne(targetEntity: Family::class)]
    #[ORM\JoinColumn(name: 'family_id', nullable: true, onDelete: 'CASCADE')]
    private ?Family $family = null;

    /** Exactement un des trois sujets est renseigné (`ck_tree_citation_sujet_unique`). */
    #[ORM\ManyToOne(targetEntity: Fact::class)]
    #[ORM\JoinColumn(name: 'fact_id', nullable: true, onDelete: 'CASCADE')]
    private ?Fact $fact = null;

    /** `NoteScope` réutilisé : permet de dire « cette citation étaye la *naissance* ». */
    #[ORM\Column(length: 16, enumType: NoteScope::class, options: ['default' => 'generale'])]
    private NoteScope $scope = NoteScope::Generale;

    /**
     * Le `PAGE` : la référence qui permet de **retrouver** la pièce. `TEXT` et pas
     * `VARCHAR` parce que certains `PAGE` sont des URL de plusieurs centaines de signes.
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $page = null;

    /** `null` = non renseigné, ce qui n'est **pas** la même chose que 0. */
    #[ORM\Column(type: Types::SMALLINT, nullable: true, enumType: CitationQuality::class)]
    private ?CitationQuality $quay = null;

    /** Le `2 EVEN` de MyHeritage : c'est lui qui a servi à requalifier les `QUAY`. */
    #[ORM\Column(length: 20, nullable: true, enumType: CitationContext::class)]
    private ?CitationContext $evenContext = null;

    /** Le `ROLE`, mal formaté chez MyHeritage (`10823:18741399-:`). Conservé brut pour l'export. */
    #[ORM\Column(length: 120, nullable: true)]
    private ?string $role = null;

    /**
     * Le `DATA/DATE` : la date **de consultation ou de saisie** de la citation, pas
     * celle du fait. Gardée en texte, elle ne sert qu'à l'export et à l'archéologie —
     * d'où l'absence de bloc date ici.
     */
    #[ORM\Column(length: 60, nullable: true)]
    private ?string $dataDateRaw = null;

    /** Le `DATA/TEXT` : la transcription de ce que dit la pièce. Parfois très long. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $dataText = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $url = null;

    /** Verrou optimiste n° 1 : une citation est une cible de `ChangeRequest` (§ 9.6). */
    #[ORM\Version]
    #[ORM\Column(type: Types::INTEGER, options: ['default' => 1])]
    private int $version = 1;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(Source $source, NoteScope $scope = NoteScope::Generale)
    {
        $this->source = $source;
        $this->scope = $scope;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSource(): Source
    {
        return $this->source;
    }

    public function setSource(Source $source): self
    {
        $this->source = $source;

        return $this;
    }

    public function getPerson(): ?Person
    {
        return $this->person;
    }

    public function setPerson(?Person $person): self
    {
        $this->person = $person;

        return $this;
    }

    public function getFamily(): ?Family
    {
        return $this->family;
    }

    public function setFamily(?Family $family): self
    {
        $this->family = $family;

        return $this;
    }

    public function getFact(): ?Fact
    {
        return $this->fact;
    }

    public function setFact(?Fact $fact): self
    {
        $this->fact = $fact;

        return $this;
    }

    public function getScope(): NoteScope
    {
        return $this->scope;
    }

    public function setScope(NoteScope $scope): self
    {
        $this->scope = $scope;

        return $this;
    }

    public function getPage(): ?string
    {
        return $this->page;
    }

    public function setPage(?string $page): self
    {
        $this->page = $page;

        return $this;
    }

    public function getQuay(): ?CitationQuality
    {
        return $this->quay;
    }

    public function setQuay(?CitationQuality $quay): self
    {
        $this->quay = $quay;

        return $this;
    }

    public function getEvenContext(): ?CitationContext
    {
        return $this->evenContext;
    }

    public function setEvenContext(?CitationContext $evenContext): self
    {
        $this->evenContext = $evenContext;

        return $this;
    }

    public function getRole(): ?string
    {
        return $this->role;
    }

    public function setRole(?string $role): self
    {
        $this->role = $role;

        return $this;
    }

    public function getDataDateRaw(): ?string
    {
        return $this->dataDateRaw;
    }

    public function setDataDateRaw(?string $dataDateRaw): self
    {
        $this->dataDateRaw = $dataDateRaw;

        return $this;
    }

    public function getDataText(): ?string
    {
        return $this->dataText;
    }

    public function setDataText(?string $dataText): self
    {
        $this->dataText = $dataText;

        return $this;
    }

    public function getUrl(): ?string
    {
        return $this->url;
    }

    public function setUrl(?string $url): self
    {
        $this->url = $url;

        return $this;
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function toucher(): self
    {
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }
}
