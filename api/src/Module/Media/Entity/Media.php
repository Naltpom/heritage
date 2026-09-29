<?php

declare(strict_types=1);

namespace App\Module\Media\Entity;

use App\Module\Core\ValueObject\UncertainDate;
use App\Module\Media\Enum\MediaKind;
use App\Module\Media\Enum\MediaStatus;
use App\Module\Media\Enum\MediaSubject;
use App\Module\Media\Repository\MediaRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Un portrait ou une preuve (§ 6.1 du modèle de données).
 *
 * Les contrôles d'entrée du § 6.5 du dossier — reniflage des **octets d'en-tête**,
 * ré-encodage pour purger l'EXIF, nom de fichier aléatoire, empreinte, vignette — sont
 * du ressort du service, pas du schéma. Mais le schéma porte leurs **résultats**, sinon
 * on ne peut pas prouver qu'ils ont eu lieu : c'est tout le sens de `exifStripped`,
 * `mimeType` et `sha256`.
 *
 * **Le fichier n'est pas dans la base.** Aucune colonne `BYTEA` : les fichiers vivent
 * hors racine web, dans un volume monté, servis par une route authentifiée qui applique
 * le cercle de visibilité. Conséquence directe sur les sauvegardes, à porter dans
 * `compose.prod.yaml` : **un `pg_dump` seul ne sauvegarde pas les médias.** Il faut
 * sauvegarder la base *et* le volume, et les restaurer ensemble — une base restaurée
 * seule référence des fichiers absents.
 */
#[ORM\Entity(repositoryClass: MediaRepository::class)]
#[ORM\Table(name: 'med_media')]
// « Les médias validés de cette personne » — à chaque ouverture de fiche. `status` est
// dans l'index parce que la requête filtre toujours dessus : on ne montre pas les preuves
// en attente aux autres membres.
#[ORM\Index(name: 'idx_med_media_sujet', columns: ['subject_kind', 'subject_id', 'status'])]
// **« Un seul portrait actif par personne »** (§ 6.5 du dossier) : règle métier dure,
// donc contrainte et non discipline de service. Un index unique partiel est exactement
// l'outil — il n'empêche pas d'empiler dix portraits remplacés.
#[ORM\UniqueConstraint(
    name: 'uniq_med_portrait_actif',
    columns: ['subject_kind', 'subject_id'],
    options: ['where' => "(((kind)::text = 'portrait'::text) AND (is_active_portrait = true))"],
)]
// **Non unique** : le même acte de mariage étaye légitimement les fiches des deux époux
// et de leurs quatre parents. Le doublon est un avertissement, pas un refus.
#[ORM\Index(name: 'idx_med_media_sha', columns: ['sha256'])]
#[ORM\Index(
    name: 'idx_med_media_brouillon',
    columns: ['subject_request_id', 'subject_draft_ref'],
    options: ['where' => '(subject_draft_ref IS NOT NULL)'],
)]
#[ORM\Index(
    name: 'idx_med_media_attente',
    columns: ['status', 'created_at'],
    options: ['where' => "((status)::text = 'en_attente'::text)"],
)]
class Media
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 12, enumType: MediaKind::class)]
    private MediaKind $kind;

    #[ORM\Column(length: 12, enumType: MediaSubject::class)]
    private MediaSubject $subjectKind;

    /** ⇢ inter-module (`Tree\Person`, `Tree\Family`, `Tree\Citation`). */
    #[ORM\Column(nullable: true)]
    private ?int $subjectId = null;

    /**
     * La référence provisoire (`#p1`) quand le média est joint à une personne **pas
     * encore créée**. Exactement un des deux sujets est renseigné.
     */
    #[ORM\Column(length: 8, nullable: true)]
    private ?string $subjectDraftRef = null;

    /**
     * ⇢ inter-module (`Proposal\ChangeRequest`). Obligatoire quand `subjectDraftRef` est
     * posé : une référence provisoire n'a de sens que dans le contexte de **sa** demande.
     * `#p1` de la demande 12 et `#p1` de la demande 13 ne désignent pas la même personne.
     */
    #[ORM\Column(nullable: true)]
    private ?int $subjectRequestId = null;

    /** Libellé dénormalisé. Filtré comme la personne elle-même (§ 0.4). */
    #[ORM\Column(length: 255)]
    private string $subjectLabel;

    /**
     * Nom du fichier sur disque : **aléatoire**, sans rapport avec le nom d'origine ni
     * avec le sujet. Un nom devinable rend le stockage énumérable, même hors racine web.
     */
    #[ORM\Column(length: 80)]
    private string $storageName;

    /** Nom fourni au téléversement. Affiché, **jamais** utilisé pour lire ou écrire un fichier. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $originalName = null;

    /**
     * Type réel, **déterminé par les octets d'en-tête** — jamais l'extension, jamais le
     * `Content-Type` annoncé. C'est le type constaté qui est stocké : si la liste blanche
     * change un jour, on sait ce qu'on a déjà accepté.
     */
    #[ORM\Column(length: 60)]
    private string $mimeType;

    /** Taille du fichier stocké, **après ré-encodage**. */
    #[ORM\Column]
    private int $byteSize;

    #[ORM\Column(length: 64, options: ['fixed' => true])]
    private string $sha256;

    /**
     * Empreinte du fichier **tel que reçu**, avant ré-encodage : permet de reconnaître un
     * second dépôt du même original, que le ré-encodage aurait rendu différent octet pour
     * octet.
     */
    #[ORM\Column(length: 64, nullable: true, options: ['fixed' => true])]
    private ?string $originalSha256 = null;

    /**
     * Le ré-encodage a bien eu lieu.
     *
     * **Colonne d'audit, pas de configuration** : elle répond à « est-on certain qu'aucune
     * coordonnée GPS n'est partie avec cette photo de famille ». Un `false` sur une image
     * est un incident.
     */
    #[ORM\Column(options: ['default' => false])]
    private bool $exifStripped = false;

    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $width = null;

    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $height = null;

    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $pageCount = null;

    /** `null` si la génération a échoué : l'interface affiche alors un substitut plutôt que de casser. */
    #[ORM\Column(length: 80, nullable: true)]
    private ?string $thumbnailName = null;

    #[ORM\Column(length: 12, enumType: MediaStatus::class, options: ['default' => 'en_attente'])]
    private MediaStatus $status = MediaStatus::EnAttente;

    /** Le portrait courant. Les précédents sont **conservés**, avec `status = remplace`. */
    #[ORM\Column(options: ['default' => false])]
    private bool $isActivePortrait = false;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $caption = null;

    /**
     * Une photo d'archive est datée, souvent approximativement — mêmes règles que partout
     * ailleurs, **pas d'exception** (§ 0.5).
     */
    #[ORM\Embedded(class: UncertainDate::class, columnPrefix: 'taken_')]
    private UncertainDate $taken;

    /** ⇢ inter-module (`Core\User`). */
    #[ORM\Column]
    private int $uploadedByUserId;

    #[ORM\Column(length: 120)]
    private string $uploadedByLabel;

    /**
     * ⇢ inter-module (`Proposal\ChangeRequest`). La demande qui a validé ce média.
     * `null` pour un dépôt direct d'administrateur ou pour l'import.
     */
    #[ORM\Column(nullable: true)]
    private ?int $changeRequestId = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        MediaKind $kind,
        MediaSubject $subjectKind,
        string $subjectLabel,
        string $storageName,
        string $mimeType,
        int $byteSize,
        string $sha256,
        int $uploadedByUserId,
        string $uploadedByLabel,
    ) {
        $this->kind = $kind;
        $this->subjectKind = $subjectKind;
        $this->subjectLabel = $subjectLabel;
        $this->storageName = $storageName;
        $this->mimeType = $mimeType;
        $this->byteSize = $byteSize;
        $this->sha256 = $sha256;
        $this->uploadedByUserId = $uploadedByUserId;
        $this->uploadedByLabel = $uploadedByLabel;
        $this->taken = UncertainDate::inconnue();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getKind(): MediaKind
    {
        return $this->kind;
    }

    public function getSubjectKind(): MediaSubject
    {
        return $this->subjectKind;
    }

    public function getSubjectId(): ?int
    {
        return $this->subjectId;
    }

    public function getSubjectDraftRef(): ?string
    {
        return $this->subjectDraftRef;
    }

    public function getSubjectRequestId(): ?int
    {
        return $this->subjectRequestId;
    }

    public function getSubjectLabel(): string
    {
        return $this->subjectLabel;
    }

    public function setSubjectLabel(string $subjectLabel): self
    {
        $this->subjectLabel = $subjectLabel;

        return $this;
    }

    /** Rattache à une ligne qui existe : c'est le cas nominal, et il exclut la référence provisoire. */
    public function rattacherA(int $subjectId): self
    {
        $this->subjectId = $subjectId;
        $this->subjectDraftRef = null;
        $this->subjectRequestId = null;

        return $this;
    }

    /**
     * Rattache à une personne encore à créer. La référence et sa demande se posent
     * ensemble — `ck_med_media_brouillon_contexte` refuse l'une sans l'autre.
     */
    public function rattacherAuBrouillon(string $draftRef, int $requestId): self
    {
        $this->subjectDraftRef = $draftRef;
        $this->subjectRequestId = $requestId;
        $this->subjectId = null;

        return $this;
    }

    public function getStorageName(): string
    {
        return $this->storageName;
    }

    /**
     * Pointe la ligne vers un fichier réécrit (une rotation). **Nouveau nom** plutôt
     * qu'un fichier écrasé sur place : les octets d'un nom de stockage restent
     * immuables, ce sur quoi reposent l'`ETag` et la version des URL de
     * {@see \App\Module\Media\Service\AdresseDuMedia}. `originalSha256` n'est pas touché :
     * il décrit le fichier **reçu**, et sert à reconnaître un second dépôt du même.
     */
    public function remplacerLeFichier(string $storageName, int $byteSize, string $sha256): self
    {
        $this->storageName = $storageName;
        $this->byteSize = $byteSize;
        $this->sha256 = $sha256;

        return $this->toucher();
    }

    public function getOriginalName(): ?string
    {
        return $this->originalName;
    }

    public function setOriginalName(?string $originalName): self
    {
        $this->originalName = $originalName;

        return $this;
    }

    public function getMimeType(): string
    {
        return $this->mimeType;
    }

    public function getByteSize(): int
    {
        return $this->byteSize;
    }

    public function getSha256(): string
    {
        return $this->sha256;
    }

    public function getOriginalSha256(): ?string
    {
        return $this->originalSha256;
    }

    public function setOriginalSha256(?string $originalSha256): self
    {
        $this->originalSha256 = $originalSha256;

        return $this;
    }

    public function isExifStripped(): bool
    {
        return $this->exifStripped;
    }

    public function setExifStripped(bool $exifStripped): self
    {
        $this->exifStripped = $exifStripped;

        return $this;
    }

    public function getWidth(): ?int
    {
        return $this->width;
    }

    public function getHeight(): ?int
    {
        return $this->height;
    }

    public function setDimensions(?int $width, ?int $height): self
    {
        $this->width = $width;
        $this->height = $height;

        return $this;
    }

    public function getPageCount(): ?int
    {
        return $this->pageCount;
    }

    public function setPageCount(?int $pageCount): self
    {
        $this->pageCount = $pageCount;

        return $this;
    }

    public function getThumbnailName(): ?string
    {
        return $this->thumbnailName;
    }

    public function setThumbnailName(?string $thumbnailName): self
    {
        $this->thumbnailName = $thumbnailName;

        return $this;
    }

    public function getStatus(): MediaStatus
    {
        return $this->status;
    }

    public function setStatus(MediaStatus $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function isActivePortrait(): bool
    {
        return $this->isActivePortrait;
    }

    /**
     * `ck_med_media_portrait_actif` refuse en base un portrait actif qui ne serait pas un
     * média `actif` de nature `portrait`.
     */
    public function setActivePortrait(bool $actif): self
    {
        $this->isActivePortrait = $actif;

        return $this;
    }

    public function getCaption(): ?string
    {
        return $this->caption;
    }

    public function setCaption(?string $caption): self
    {
        $this->caption = $caption;

        return $this;
    }

    public function getTaken(): UncertainDate
    {
        return $this->taken;
    }

    public function setTaken(UncertainDate $taken): self
    {
        $this->taken = $taken;

        return $this;
    }

    public function getUploadedByUserId(): int
    {
        return $this->uploadedByUserId;
    }

    public function getUploadedByLabel(): string
    {
        return $this->uploadedByLabel;
    }

    public function getChangeRequestId(): ?int
    {
        return $this->changeRequestId;
    }

    public function setChangeRequestId(?int $changeRequestId): self
    {
        $this->changeRequestId = $changeRequestId;

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
