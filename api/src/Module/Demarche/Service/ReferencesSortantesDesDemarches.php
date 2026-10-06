<?php

declare(strict_types=1);

namespace App\Module\Demarche\Service;

use App\Module\Core\Contract\ReferenceInterModules;
use App\Module\Core\Contract\ReferencesInterModulesInterface;
use App\Module\Core\Enum\CibleDeReference;
use App\Module\Core\Service\ReleveurDeReferences;

/**
 * Les références de `Demarche` (ADR 0011).
 *
 * `dmr_personne.person_id` est **stricte**, et c'est la plus coûteuse quand elle pend :
 * une démarche se montre à qui voit **toutes** ses personnes, et une personne disparue n'est
 * vue de personne — la démarche entière, son texte et son suivi, devient invisible sans que
 * rien ne le dise. C'est pour cela qu'elle bloque la suppression d'une fiche
 * ({@see DependancesDesDemarches}).
 */
final readonly class ReferencesSortantesDesDemarches implements ReferencesInterModulesInterface
{
    public function __construct(private ReleveurDeReferences $releveur)
    {
    }

    public function references(): array
    {
        return [
            new ReferenceInterModules('dmr_personne.person_id', CibleDeReference::Personne, $this->releveur->occurrences('dmr_personne', 'person_id')),
            new ReferenceInterModules('dmr_demarche.auteur_user_id', CibleDeReference::Compte, $this->releveur->occurrences('dmr_demarche', 'auteur_user_id')),
            new ReferenceInterModules('dmr_echange.saisi_par_user_id', CibleDeReference::Compte, $this->releveur->occurrences('dmr_echange', 'saisi_par_user_id')),
        ];
    }
}
