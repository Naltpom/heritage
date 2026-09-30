<?php

declare(strict_types=1);

namespace App\Module\Tree\Entity;

use App\Module\Tree\Enum\SourceKind;
use App\Module\Tree\Repository\SourceRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * **La source**, au sens GEDCOM `SOUR` de niveau 0 : un corpus, un fonds, un site, un
 * fichier — 28 lignes après import (§ 3.6 du modèle de données).
 *
 * Pas son usage : c'est {@see Citation} qui porte la page, le `QUAY` et la
 * transcription. Cette séparation est ce qui permettra de répondre un jour à « ces 40
 * fiches reposent sur la même recopie non vérifiée » (§ 9.7) ; `graphe.json` l'avait
 * perdue en aplatissant tout sur la personne.
 */
#[ORM\Entity(repositoryClass: SourceRepository::class)]
#[ORM\Table(name: 'tree_source')]
#[ORM\UniqueConstraint(name: 'uniq_tree_source_xref', columns: ['gedcom_xref'])]
#[ORM\Index(name: 'idx_tree_source_title', columns: ['title'])]
// « Toutes les sources qui sont des arbres d'autres utilisateurs » : le point d'entrée du
// tri de fiabilité.
#[ORM\Index(name: 'idx_tree_source_kind', columns: ['source_kind'])]
class Source
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** `@S500001@`. Idempotence du ré-import. */
    #[ORM\Column(length: 20, nullable: true)]
    private ?string $gedcomXref = null;

    /** `TITL` : « Fichier des personnes décédées », « Bourrache Web Site ». */
    #[ORM\Column(length: 255)]
    private string $title;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $author = null;

    /**
     * `PUBL` : l'éditeur, la cote, les conditions d'accès. Souvent le champ le plus
     * informatif — « archives.ombrage.fr — recensements nominatifs, cotes 4 E
     * (1802-1892) et 88 W (1958) ».
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $publication = null;

    /** `TEXT` : le descriptif du corpus fourni par l'éditeur. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $text = null;

    /** Le `NOTE` de la source. C'est ici qu'arrivent les 14 notes de `SOUR`, pas dans `tree_note`. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $note = null;

    /** `_MEDI`. Reliquat MyHeritage, souvent un identifiant de collection ; conservé pour l'export. */
    #[ORM\Column(length: 60, nullable: true)]
    private ?string $mediaType = null;

    /**
     * **Ajout par rapport au GEDCOM**, délibéré : c'est la qualification qui permet de
     * dire « ces 40 fiches reposent sur un arbre d'un autre utilisateur ». Posée à
     * l'import depuis le `TITL`/`PUBL`, révisable à la main.
     */
    #[ORM\Column(length: 24, enumType: SourceKind::class, options: ['default' => 'autre'])]
    private SourceKind $sourceKind = SourceKind::Autre;

    // Pas de colonne `version`, donc pas de verrou optimiste : **une source n'est pas
    // modifiable par proposition en v1**. Les portées d'une demande sont personne,
    // famille, citation et média (§ 6.3 du dossier de mission), et le § 9.6 du modèle
    // n'énumère que les trois tables correspondantes. Le § 3.6 donnait une colonne
    // `version` à cette table : elle ouvrait un chemin que rien d'autre n'emprunte —
    // ni `RequestKind`, ni `SubjectKind` n'ont de valeur pour les sources.
    //
    // Le sens du choix est celui qui se défait : rendre les sources modifiables plus
    // tard, c'est remettre une colonne, une migration de routine. Retirer une valeur
    // d'énumération déjà écrite dans des demandes validées, c'est une migration de
    // données.
    //
    // Depuis l'ADR 0012, l'**administration** crée et corrige les sources hors de la
    // file (`Tree\Service\ServiceDesSources`) : une notice bibliographique n'affirme rien
    // sur une personne. Toujours sans verrou : un seul écran, réservé aux
    // administrateurs, en remplacement complet.

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $title, SourceKind $sourceKind = SourceKind::Autre)
    {
        $this->title = $title;
        $this->sourceKind = $sourceKind;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getGedcomXref(): ?string
    {
        return $this->gedcomXref;
    }

    public function setGedcomXref(?string $gedcomXref): self
    {
        $this->gedcomXref = $gedcomXref;

        return $this;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): self
    {
        $this->title = $title;

        return $this;
    }

    public function getAuthor(): ?string
    {
        return $this->author;
    }

    public function setAuthor(?string $author): self
    {
        $this->author = $author;

        return $this;
    }

    public function getPublication(): ?string
    {
        return $this->publication;
    }

    public function setPublication(?string $publication): self
    {
        $this->publication = $publication;

        return $this;
    }

    public function getText(): ?string
    {
        return $this->text;
    }

    public function setText(?string $text): self
    {
        $this->text = $text;

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

    public function getMediaType(): ?string
    {
        return $this->mediaType;
    }

    public function setMediaType(?string $mediaType): self
    {
        $this->mediaType = $mediaType;

        return $this;
    }

    public function getSourceKind(): SourceKind
    {
        return $this->sourceKind;
    }

    public function setSourceKind(SourceKind $sourceKind): self
    {
        $this->sourceKind = $sourceKind;

        return $this;
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
