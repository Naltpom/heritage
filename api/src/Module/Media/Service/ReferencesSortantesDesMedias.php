<?php

declare(strict_types=1);

namespace App\Module\Media\Service;

use App\Module\Core\Contract\ReferenceInterModules;
use App\Module\Core\Contract\ReferencesInterModulesInterface;
use App\Module\Core\Enum\CibleDeReference;
use App\Module\Core\Service\ReleveurDeReferences;
use App\Module\Media\Enum\MediaSubject;

/**
 * Les références de `med_media` — une table **dont tous les sujets sont inter-modules**
 * (§ 11 du modèle de données).
 *
 * `subject_id` est polymorphe : personne, famille ou citation selon `subject_kind`, et
 * PostgreSQL ne peut pas contraindre une telle colonne. C'est la « preuve orpheline » que
 * ce projet a déjà payée une fois. Elle est relevée **par sorte de sujet**, et **tous les
 * statuts comptent** — `en_attente` et `refuse` compris, comme dans le refus de suppression
 * (`PortMedias::compterLesMediasDe()`) : une preuve en attente est celle dont la perte serait
 * la plus silencieuse. Aucune tolérance : une suppression de fiche est refusée tant qu'une
 * preuve vise la fiche, une union ou une citation qui partirait avec elle.
 */
final readonly class ReferencesSortantesDesMedias implements ReferencesInterModulesInterface
{
    public function __construct(private ReleveurDeReferences $releveur)
    {
    }

    public function references(): array
    {
        $references = [];

        foreach ([
            [MediaSubject::Personne, CibleDeReference::Personne],
            [MediaSubject::Famille, CibleDeReference::Famille],
            [MediaSubject::Citation, CibleDeReference::Citation],
        ] as [$sujet, $cible]) {
            $references[] = new ReferenceInterModules(
                'med_media.subject_id',
                $cible,
                $this->releveur->occurrences('med_media', 'subject_id', 'subject_kind = :sorte', ['sorte' => $sujet->value]),
                'subject_kind = '.$sujet->value,
            );
        }

        foreach ([
            ['subject_user_id', CibleDeReference::Compte],
            ['uploaded_by_user_id', CibleDeReference::Compte],
            ['subject_request_id', CibleDeReference::Demande],
            ['change_request_id', CibleDeReference::Demande],
        ] as [$colonne, $cible]) {
            $references[] = new ReferenceInterModules('med_media.'.$colonne, $cible, $this->releveur->occurrences('med_media', $colonne));
        }

        return $references;
    }
}
