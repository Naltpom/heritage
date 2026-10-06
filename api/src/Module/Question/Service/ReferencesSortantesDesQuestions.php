<?php

declare(strict_types=1);

namespace App\Module\Question\Service;

use App\Module\Core\Contract\ReferenceInterModules;
use App\Module\Core\Contract\ReferencesInterModulesInterface;
use App\Module\Core\Enum\CibleDeReference;
use App\Module\Core\Enum\SubjectKind;
use App\Module\Core\Service\ReleveurDeReferences;

/**
 * Les références de `Question` (ADR 0008).
 *
 * `qst_question.sujet_id` est polymorphe — `Personne` dans toutes les natures livrées,
 * `Famille` le jour d'une lacune d'union — et relevée par sorte de sujet. Stricte : une
 * question sur une fiche supprimée est effacée avec elle ({@see DependancesDesQuestions}),
 * il n'en reste donc aucune de légitime.
 *
 * `qst_question_demande.demande_id` est stricte aussi : une demande n'est jamais supprimée
 * (ADR 0004).
 */
final readonly class ReferencesSortantesDesQuestions implements ReferencesInterModulesInterface
{
    public function __construct(private ReleveurDeReferences $releveur)
    {
    }

    public function references(): array
    {
        $references = [];

        foreach ([
            [SubjectKind::Personne, CibleDeReference::Personne],
            [SubjectKind::Famille, CibleDeReference::Famille],
        ] as [$sorte, $cible]) {
            $references[] = new ReferenceInterModules(
                'qst_question.sujet_id',
                $cible,
                $this->releveur->occurrences('qst_question', 'sujet_id', 'sujet_kind = :sorte', ['sorte' => $sorte->value]),
                'sujet_kind = '.$sorte->value,
            );
        }

        $references[] = new ReferenceInterModules('qst_question.destinataire_user_id', CibleDeReference::Compte, $this->releveur->occurrences('qst_question', 'destinataire_user_id'));
        $references[] = new ReferenceInterModules('qst_question_demande.demande_id', CibleDeReference::Demande, $this->releveur->occurrences('qst_question_demande', 'demande_id'));

        return $references;
    }
}
