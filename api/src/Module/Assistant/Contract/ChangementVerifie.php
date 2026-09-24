<?php

declare(strict_types=1);

namespace App\Module\Assistant\Contract;

/**
 * Un op de modification en une ligne de français : « Naissance : 1987 → 3 mars 1921 ».
 *
 * `personneId` est là pour que l'assistant sache **de qui** est la valeur d'avant, et
 * donc s'il a le droit de la montrer au lecteur.
 */
final readonly class ChangementVerifie
{
    public function __construct(
        public int $personneId,
        public string $champ,
        public string $libelle,
        public string $avant,
        public string $apres,
    ) {
    }
}
