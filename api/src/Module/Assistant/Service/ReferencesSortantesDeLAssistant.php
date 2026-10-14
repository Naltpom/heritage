<?php

declare(strict_types=1);

namespace App\Module\Assistant\Service;

use App\Module\Core\Contract\ReferenceInterModules;
use App\Module\Core\Contract\ReferencesInterModulesInterface;
use App\Module\Core\Enum\CibleDeReference;
use App\Module\Core\Service\ReleveurDeReferences;

/**
 * `ast_conversation.user_id` — le propriétaire d'une conversation de « Poser une question ».
 *
 * Les personnes que cite une réponse vivent dans le texte des échanges, pas dans une
 * colonne : elles ne sont pas des références, et la commande ne les lit pas.
 */
final readonly class ReferencesSortantesDeLAssistant implements ReferencesInterModulesInterface
{
    public function __construct(private ReleveurDeReferences $releveur)
    {
    }

    public function references(): array
    {
        return [
            new ReferenceInterModules('ast_conversation.user_id', CibleDeReference::Compte, $this->releveur->occurrences('ast_conversation', 'user_id')),
        ];
    }
}
