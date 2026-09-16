<?php

declare(strict_types=1);

namespace App\Module\Gedcom\Dto;

use App\Module\Gedcom\Enum\IssueSeverity;

/**
 * Une anomalie relevée pendant la lecture, avant qu'elle ne devienne une ligne de
 * `ged_issue` (§ 8.2 du modèle de données).
 *
 * Objet de transport et non entité : l'analyse se fait **sans base** — c'est ce qui
 * permet au mode `--dry-run` de tourner et aux tests de couvrir tout l'analyseur sans
 * serveur PostgreSQL.
 */
final class Anomalie
{
    public function __construct(
        public readonly IssueSeverity $severite,
        /** Code stable : c'est lui qui permet de filtrer, et de taire une catégorie entière. */
        public readonly string $code,
        public readonly string $message,
        public readonly ?int $ligne = null,
        public readonly ?string $xref = null,
        public readonly ?string $tag = null,
        public readonly ?string $ligneBrute = null,
    ) {
    }
}
