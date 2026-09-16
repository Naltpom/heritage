<?php

declare(strict_types=1);

namespace App\Module\Gedcom\Entity;

use App\Module\Gedcom\Enum\RunDirection;
use App\Module\Gedcom\Enum\RunStatus;
use App\Module\Gedcom\Repository\RunRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Une exécution d'import **ou** d'export (§ 8.1 du modèle de données).
 *
 * Le module ne possède aucune donnée généalogique : il en produit et en consomme. Il
 * **écrit** dans `Tree`, `Media` et `Core`, mais par appel de service, jamais par
 * relation — il n'y a aucune clé étrangère de `ged_*` vers `tree_*`. Le lien entre un
 * import et ce qu'il a produit passe par `tree_person.gedcom_xref` et par les
 * `prp_revision` que l'import émet.
 *
 * L'import des vraies données est une **commande locale**
 * (`bin/console heritage:import-gedcom`), pas une fixture (§ 9 du dossier de mission).
 */
#[ORM\Entity(repositoryClass: RunRepository::class)]
#[ORM\Table(name: 'ged_run')]
#[ORM\Index(name: 'idx_ged_run_date', columns: ['direction', 'started_at'])]
// « Ce fichier a-t-il déjà été importé ? », posée avant de lancer.
#[ORM\Index(
    name: 'idx_ged_run_sha',
    columns: ['file_sha256'],
    options: ['where' => '(file_sha256 IS NOT NULL)'],
)]
class Run
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 8, enumType: RunDirection::class)]
    private RunDirection $direction;

    #[ORM\Column(length: 12, enumType: RunStatus::class, options: ['default' => 'en_cours'])]
    private RunStatus $status = RunStatus::EnCours;

    #[ORM\Column(length: 255)]
    private string $filename;

    /** Permet de dire « ce fichier a déjà été importé le 14 septembre » **avant** de tout rejouer. */
    #[ORM\Column(length: 64, nullable: true, options: ['fixed' => true])]
    private ?string $fileSha256 = null;

    #[ORM\Column(nullable: true)]
    private ?int $byteSize = null;

    /**
     * Le `CHAR` de l'en-tête GEDCOM. Le `README.md` du chantier documente un fichier
     * d'origine invalide en UTF-8 : savoir ce que le fichier **annonçait** aide à
     * comprendre ce qu'on a lu.
     */
    #[ORM\Column(length: 20, nullable: true)]
    private ?string $gedcomCharset = null;

    /**
     * Les dénombrements de contrôle du § 4 du dossier de mission — « un import qui ne
     * retombe pas exactement sur ces nombres est un import raté ».
     *
     * Les stocker permet de **comparer** deux imports, pas seulement de les lire dans un
     * journal perdu.
     *
     * @var array<string, int>|null
     */
    #[ORM\Column(type: Types::JSONB, nullable: true)]
    private ?array $counts = null;

    /**
     * Exécution à blanc : on lit, on compte, on signale, on n'écrit rien. C'est le mode
     * dans lequel on découvre qu'un fichier est mauvais **avant** d'avoir touché la base.
     */
    #[ORM\Column(options: ['default' => false])]
    private bool $dryRun = false;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $startedAt;

    /** `null` tant que l'exécution court — ou si elle s'est interrompue brutalement, ce qui est une information. */
    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $error = null;

    /** ⇢ inter-module (`Core\User`). `null` pour un lancement en console. */
    #[ORM\Column(nullable: true)]
    private ?int $launchedByUserId = null;

    /** Libellé dénormalisé, ou `'console'`. */
    #[ORM\Column(length: 120, nullable: true)]
    private ?string $launchedByLabel = null;

    public function __construct(RunDirection $direction, string $filename, bool $dryRun = false)
    {
        $this->direction = $direction;
        $this->filename = $filename;
        $this->dryRun = $dryRun;
        $this->startedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDirection(): RunDirection
    {
        return $this->direction;
    }

    public function getStatus(): RunStatus
    {
        return $this->status;
    }

    public function getFilename(): string
    {
        return $this->filename;
    }

    public function getFileSha256(): ?string
    {
        return $this->fileSha256;
    }

    public function setFileSha256(?string $fileSha256): self
    {
        $this->fileSha256 = $fileSha256;

        return $this;
    }

    public function getByteSize(): ?int
    {
        return $this->byteSize;
    }

    public function setByteSize(?int $byteSize): self
    {
        $this->byteSize = $byteSize;

        return $this;
    }

    public function getGedcomCharset(): ?string
    {
        return $this->gedcomCharset;
    }

    public function setGedcomCharset(?string $gedcomCharset): self
    {
        $this->gedcomCharset = $gedcomCharset;

        return $this;
    }

    /** @return array<string, int>|null */
    public function getCounts(): ?array
    {
        return $this->counts;
    }

    /** @param array<string, int>|null $counts */
    public function setCounts(?array $counts): self
    {
        $this->counts = $counts;

        return $this;
    }

    public function isDryRun(): bool
    {
        return $this->dryRun;
    }

    public function getStartedAt(): \DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function getFinishedAt(): ?\DateTimeImmutable
    {
        return $this->finishedAt;
    }

    public function getError(): ?string
    {
        return $this->error;
    }

    public function terminer(RunStatus $status, ?string $error = null, ?\DateTimeImmutable $le = null): self
    {
        $this->status = $status;
        $this->error = $error;
        $this->finishedAt = $le ?? new \DateTimeImmutable();

        return $this;
    }

    public function getLaunchedByUserId(): ?int
    {
        return $this->launchedByUserId;
    }

    public function getLaunchedByLabel(): ?string
    {
        return $this->launchedByLabel;
    }

    public function setLaunchedBy(?int $userId, ?string $label): self
    {
        $this->launchedByUserId = $userId;
        $this->launchedByLabel = $label;

        return $this;
    }
}
