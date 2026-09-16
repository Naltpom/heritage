<?php

declare(strict_types=1);

namespace App\Module\Gedcom\Entity;

use App\Module\Gedcom\Enum\IssueSeverity;
use App\Module\Gedcom\Repository\IssueRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Le rapport d'import, ligne par ligne (§ 8.2 du modèle de données).
 *
 * Sans cette table, un import raté ne laisse qu'un message générique et il faut relire
 * 9 516 lignes à la main.
 *
 * **Codes attendus**, tirés de ce que le fichier réel contient :
 * `tag_proprietaire_ignore`, `role_mal_forme` (info) ; `nom_vide`, `quay_hors_norme`,
 * `date_non_analysable`, `filiation_multiple`, `media_distant` (avertissement) ;
 * `lien_casse`, `denombrement_inattendu`, `encodage_invalide` (erreur).
 */
#[ORM\Entity(repositoryClass: IssueRepository::class)]
#[ORM\Table(name: 'ged_issue')]
// Le rapport d'un import, groupé par sévérité puis par catégorie — exactement la
// présentation du tableau de contrôle qualité du `README.md` du chantier.
#[ORM\Index(name: 'idx_ged_issue_run', columns: ['run_id', 'severity', 'code'])]
#[ORM\Index(
    name: 'idx_ged_issue_xref',
    columns: ['gedcom_xref'],
    options: ['where' => '(gedcom_xref IS NOT NULL)'],
)]
class Issue
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Run::class)]
    #[ORM\JoinColumn(name: 'run_id', nullable: false, onDelete: 'CASCADE')]
    private Run $run;

    // 16 et non 12 : `avertissement` fait treize signes, et c'est la sévérité la plus
    // fréquente d'un import réel (35 occurrences sur le fichier de référence). Un
    // `VARCHAR(12)` passait tous les contrôles du projet et ne tombait qu'au premier
    // `INSERT` — `LongueurDesEnumerationsTest` garde désormais ce genre d'écart.
    #[ORM\Column(length: 16, enumType: IssueSeverity::class)]
    private IssueSeverity $severity;

    /** Code stable : c'est lui qui permet de filtrer, et de **taire une catégorie entière**. */
    #[ORM\Column(length: 40)]
    private string $code;

    #[ORM\Column(nullable: true)]
    private ?int $lineNo = null;

    /** L'enregistrement concerné (`@I500017@`). */
    #[ORM\Column(length: 20, nullable: true)]
    private ?string $gedcomXref = null;

    /** Le tag GEDCOM en cause (`_UID`, `ROLE`, `QUAY`). */
    #[ORM\Column(length: 20, nullable: true)]
    private ?string $tag = null;

    #[ORM\Column(type: Types::TEXT)]
    private string $message;

    /** La ligne brute, pour ne pas avoir à rouvrir le fichier. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $rawLine = null;

    public function __construct(Run $run, IssueSeverity $severity, string $code, string $message)
    {
        $this->run = $run;
        $this->severity = $severity;
        $this->code = $code;
        $this->message = $message;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRun(): Run
    {
        return $this->run;
    }

    public function getSeverity(): IssueSeverity
    {
        return $this->severity;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getLineNo(): ?int
    {
        return $this->lineNo;
    }

    public function getGedcomXref(): ?string
    {
        return $this->gedcomXref;
    }

    public function getTag(): ?string
    {
        return $this->tag;
    }

    public function getRawLine(): ?string
    {
        return $this->rawLine;
    }

    /** Le contexte de la ligne fautive : tout ce qui évite de rouvrir le fichier. */
    public function situer(?int $lineNo, ?string $gedcomXref, ?string $tag, ?string $rawLine): self
    {
        $this->lineNo = $lineNo;
        $this->gedcomXref = $gedcomXref;
        $this->tag = $tag;
        $this->rawLine = $rawLine;

        return $this;
    }

    public function getMessage(): string
    {
        return $this->message;
    }
}
