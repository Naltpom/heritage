<?php

declare(strict_types=1);

namespace App\Module\Proposal\Contract;

use App\Module\Core\Enum\SubjectKind;
use App\Module\Proposal\Enum\RevisionOperation;

/**
 * Ce qu'une passe de validation a écrit dans l'arbre, **avant** qu'on sache sous quelle
 * empreinte l'inscrire.
 *
 * ── Pourquoi un objet intermédiaire plutôt que la `Revision` directement ─────────
 *
 * `prp_revision.tree_fingerprint_after` est l'empreinte de l'arbre **après**
 * application, et elle ne peut donc être calculée qu'une fois toutes les écritures
 * faites (§ 9.4, point 6). Or une validation écrit par morceaux : les champs d'une
 * fiche, puis une personne créée, puis la famille qui la relie, puis les filiations.
 * Si chaque morceau construisait sa `Revision`, il faudrait soit calculer l'empreinte
 * plusieurs fois — elle changerait entre deux —, soit repasser sur les objets pour la
 * poser après coup.
 *
 * Chaque passe rend donc **ce qu'elle a fait**, et le validateur matérialise les
 * révisions en une fois, avec l'empreinte finale. C'est ce qui garantit que **toutes
 * les révisions d'une même validation portent la même empreinte** — sans quoi deux
 * lignes d'un même acte sembleraient appartenir à deux états de l'arbre.
 */
final readonly class EcritureDeRevision
{
    /**
     * @param array<string, mixed> $changes le diff appliqué, dans la forme de
     *                                      `core_audit_log.changes`. Pour une création,
     *                                      `avant` vaut `null` partout (§ 5.5)
     */
    public function __construct(
        public SubjectKind $subjectKind,
        public int $subjectId,
        public string $subjectLabel,
        public RevisionOperation $operation,
        public array $changes,
        public ?int $versionAvant = null,
        public ?int $versionApres = null,
    ) {
    }
}
