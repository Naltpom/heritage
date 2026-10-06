<?php

declare(strict_types=1);

namespace App\Module\Proposal\Service;

use App\Module\Core\Contract\ReferenceInterModules;
use App\Module\Core\Contract\ReferencesInterModulesInterface;
use App\Module\Core\Enum\CibleDeReference;
use App\Module\Core\Enum\SubjectKind;
use App\Module\Core\Service\ReleveurDeReferences;

/**
 * Les références de `Proposal` — le module « qui référence à peu près tout » (ADR 0002).
 *
 * ## Presque toutes sont tolérées, et c'est la règle du module, pas une indulgence
 *
 * Une demande parle d'une fiche qui peut disparaître : c'est même le cas `cible_disparue`
 * du § 6.8 du contrat d'API. Une demande **ouverte** dont la cible a été supprimée devient
 * caduque **à son prochain examen**, jamais par un balayage (ADR 0004) ; une demande
 * **tranchée** est l'historique de la discussion, et l'historique survit à son sujet —
 * `prp_revision.subject_id` est un entier nu précisément pour cela. Une valeur pendante y
 * est donc l'état prévu : elle est relevée, montrée, et ne fait pas échouer la commande.
 *
 * Restent **strictes** les références aux comptes : `core_user` ne se supprime pas, et un
 * auteur ou un acteur introuvable n'a aucune explication métier.
 *
 * Ce qui n'est **pas** relevé, et pourquoi : `prp_change_op.old_value` / `new_value` (un
 * identifiant de média dans un `JSONB`), et les sujets d'op de nature `note`, `fait` ou
 * `media`, que le § 10.8 ne trace pas.
 */
final readonly class ReferencesSortantesDesDemandes implements ReferencesInterModulesInterface
{
    private const string DISPARITION_PREVUE = 'une demande ouverte dont la cible a disparu devient caduque (cible_disparue) à son prochain examen, § 6.8 du contrat d\'API ; une demande tranchée est l\'historique';

    private const string HISTORIQUE = 'historique : une révision survit à son sujet, c\'est ce qui la rend lisible après une suppression';

    public function __construct(private ReleveurDeReferences $releveur)
    {
    }

    public function references(): array
    {
        $references = [
            $this->stricte('prp_change_request', 'author_user_id', CibleDeReference::Compte),
            $this->stricte('prp_change_request', 'decided_by_user_id', CibleDeReference::Compte),
            $this->stricte('prp_revision', 'actor_user_id', CibleDeReference::Compte),
        ];

        foreach ([
            [SubjectKind::Personne, CibleDeReference::Personne],
            [SubjectKind::Famille, CibleDeReference::Famille],
            [SubjectKind::Citation, CibleDeReference::Citation],
        ] as [$sorte, $cible]) {
            $references[] = $this->toleree('prp_change_request', 'target_id', $cible, 'target_kind', $sorte, self::DISPARITION_PREVUE);
            $references[] = $this->toleree('prp_change_op', 'subject_id', $cible, 'subject_kind', $sorte, self::DISPARITION_PREVUE);
            $references[] = $this->toleree('prp_revision', 'subject_id', $cible, 'subject_kind', $sorte, self::HISTORIQUE);
        }

        foreach (['subject_person_id', 'other_person_id', 'other2_person_id'] as $colonne) {
            $references[] = $this->toleree('prp_draft_link', $colonne, CibleDeReference::Personne, null, null, self::DISPARITION_PREVUE);
        }

        $references[] = $this->toleree('prp_draft_link', 'family_id', CibleDeReference::Famille, null, null, self::DISPARITION_PREVUE);
        $references[] = $this->toleree('prp_draft_link', 'resolved_family_id', CibleDeReference::Famille, null, null, 'historique : la famille trouvée ou créée à la validation');
        $references[] = $this->toleree('prp_draft_person', 'resolved_person_id', CibleDeReference::Personne, null, null, 'historique : la fiche créée à la validation');

        return $references;
    }

    private function stricte(string $table, string $colonne, CibleDeReference $cible): ReferenceInterModules
    {
        return new ReferenceInterModules($table.'.'.$colonne, $cible, $this->releveur->occurrences($table, $colonne));
    }

    private function toleree(string $table, string $colonne, CibleDeReference $cible, ?string $discriminant, ?SubjectKind $sorte, string $raison): ReferenceInterModules
    {
        if (null === $discriminant || null === $sorte) {
            return new ReferenceInterModules($table.'.'.$colonne, $cible, $this->releveur->occurrences($table, $colonne), null, $raison);
        }

        return new ReferenceInterModules(
            $table.'.'.$colonne,
            $cible,
            $this->releveur->occurrences($table, $colonne, $discriminant.' = :sorte', ['sorte' => $sorte->value]),
            $discriminant.' = '.$sorte->value,
            $raison,
        );
    }
}
