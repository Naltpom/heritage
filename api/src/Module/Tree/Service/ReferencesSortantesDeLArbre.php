<?php

declare(strict_types=1);

namespace App\Module\Tree\Service;

use App\Module\Core\Contract\ReferenceInterModules;
use App\Module\Core\Contract\ReferencesInterModulesInterface;
use App\Module\Core\Enum\CibleDeReference;
use App\Module\Core\Service\ReleveurDeReferences;

/**
 * La seule flèche qui part de l'arbre : l'auteur d'une note (§ 10.8 du modèle de
 * données, « à l'exception de `tree_note.author_user_id` »).
 *
 * `NULL` y veut dire « importée », et n'est pas relevé. Un auteur renseigné doit être un
 * compte qui existe — `core_user` ne se supprime pas.
 */
final readonly class ReferencesSortantesDeLArbre implements ReferencesInterModulesInterface
{
    public function __construct(private ReleveurDeReferences $releveur)
    {
    }

    public function references(): array
    {
        return [
            new ReferenceInterModules('tree_note.author_user_id', CibleDeReference::Compte, $this->releveur->occurrences('tree_note', 'author_user_id')),
            // La fin de recherche (ADR 0023) a le même auteur qu'une note : le déposant.
            new ReferenceInterModules('tree_research_closure.author_user_id', CibleDeReference::Compte, $this->releveur->occurrences('tree_research_closure', 'author_user_id')),
        ];
    }
}
