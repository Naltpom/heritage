<?php

declare(strict_types=1);

namespace App\Module\Membership\Entity;

use App\Module\Core\Enum\NiveauDePartage;
use App\Module\Core\ValueObject\PartageDeNaissance;
use App\Module\Membership\Repository\AutorisationDeNaissanceRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * L'autorisation **signée** de partager la date de naissance d'une personne vivante **qui
 * n'a pas de compte** (ADR 0016) : un écrit, une capture de SMS, déposé par un
 * administrateur sur la fiche, avec le niveau accordé à chaque composante — `tous`,
 * `proches` ou `personne` (révision du 2026-10-07).
 *
 * ## Une preuve, donc on n'efface pas
 *
 * Une autorisation remplacée, retirée, ou rendue caduque par le rattachement d'un compte
 * est **archivée** (`archived_at`, `archive_reason`), jamais supprimée : c'est la trace
 * de ce qui a été consenti, et quand. Une seule est active par personne
 * (`uniq_mbr_autorisation_active`).
 *
 * ## Le compte l'emporte
 *
 * Dès qu'un `mbr_member` désigne la personne, **seul son consentement compte** —
 * `Service\Reciprocite` ne lit plus l'autorisation, et `Service\ConsentementDeNaissance`
 * l'archive en `compte_rattache` au rattachement.
 *
 * Le document vit hors de `med_media` : il n'a ni cercle, ni vignette, ni circuit de
 * validation, et ne se montre qu'aux administrateurs (`StockageDesAutorisations`).
 */
#[ORM\Entity(repositoryClass: AutorisationDeNaissanceRepository::class)]
#[ORM\Table(name: 'mbr_autorisation_naissance')]
#[ORM\Index(name: 'idx_mbr_autorisation_person', columns: ['person_id'])]
// Une seule autorisation **active** par personne ; les archivées s'accumulent.
#[ORM\UniqueConstraint(
    name: 'uniq_mbr_autorisation_active',
    columns: ['person_id'],
    options: ['where' => '(archived_at IS NULL)'],
)]
class AutorisationDeNaissance
{
    public const string ARCHIVE_REMPLACEE = 'remplacee';
    public const string ARCHIVE_COMPTE_RATTACHE = 'compte_rattache';
    public const string ARCHIVE_RETIREE = 'retiree';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** ⇢ inter-module (`Tree\Person`). */
    #[ORM\Column]
    private int $personId;

    #[ORM\Column(length: 8, enumType: NiveauDePartage::class)]
    private NiveauDePartage $shareYear;

    #[ORM\Column(length: 8, enumType: NiveauDePartage::class)]
    private NiveauDePartage $shareMonth;

    #[ORM\Column(length: 8, enumType: NiveauDePartage::class)]
    private NiveauDePartage $shareDay;

    /** Nom aléatoire dans `StockageDesAutorisations`. */
    #[ORM\Column(length: 48)]
    private string $documentName;

    #[ORM\Column(length: 40)]
    private string $documentMime;

    #[ORM\Column]
    private int $documentSize;

    #[ORM\Column(length: 64)]
    private string $documentSha256;

    /** Une précision libre : « SMS du 2 octobre », « courrier signé ». */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $note;

    /** ⇢ inter-module (`Core\User`). */
    #[ORM\Column]
    private int $grantedByUserId;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $grantedAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $archivedAt = null;

    /** ⇢ inter-module (`Core\User`). `null` quand l'archivage suit un rattachement automatique. */
    #[ORM\Column(nullable: true)]
    private ?int $archivedByUserId = null;

    #[ORM\Column(length: 24, nullable: true)]
    private ?string $archiveReason = null;

    public function __construct(
        int $personId,
        PartageDeNaissance $partage,
        string $documentName,
        string $documentMime,
        int $documentSize,
        string $documentSha256,
        ?string $note,
        int $grantedByUserId,
    ) {
        $this->personId = $personId;
        $this->shareYear = $partage->annee;
        $this->shareMonth = $partage->mois;
        $this->shareDay = $partage->jour;
        $this->documentName = $documentName;
        $this->documentMime = $documentMime;
        $this->documentSize = $documentSize;
        $this->documentSha256 = $documentSha256;
        $this->note = $note;
        $this->grantedByUserId = $grantedByUserId;
        $this->grantedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPersonId(): int
    {
        return $this->personId;
    }

    public function getPartage(): PartageDeNaissance
    {
        return new PartageDeNaissance($this->shareYear, $this->shareMonth, $this->shareDay);
    }

    public function getDocumentName(): string
    {
        return $this->documentName;
    }

    public function getDocumentMime(): string
    {
        return $this->documentMime;
    }

    public function getDocumentSize(): int
    {
        return $this->documentSize;
    }

    public function getDocumentSha256(): string
    {
        return $this->documentSha256;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function getGrantedByUserId(): int
    {
        return $this->grantedByUserId;
    }

    public function getGrantedAt(): \DateTimeImmutable
    {
        return $this->grantedAt;
    }

    public function getArchivedAt(): ?\DateTimeImmutable
    {
        return $this->archivedAt;
    }

    public function getArchivedByUserId(): ?int
    {
        return $this->archivedByUserId;
    }

    public function getArchiveReason(): ?string
    {
        return $this->archiveReason;
    }

    public function estActive(): bool
    {
        return null === $this->archivedAt;
    }

    /** @param self::ARCHIVE_* $raison */
    public function archiver(string $raison, ?int $parUserId): self
    {
        if (null === $this->archivedAt) {
            $this->archivedAt = new \DateTimeImmutable();
            $this->archivedByUserId = $parUserId;
            $this->archiveReason = $raison;
        }

        return $this;
    }
}
