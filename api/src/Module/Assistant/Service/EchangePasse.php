<?php

declare(strict_types=1);

namespace App\Module\Assistant\Service;

/**
 * Un échange précédent de la conversation, tel qu'on le rend au modèle pour qu'il
 * comprenne une question de suivi (« et son père ? »).
 */
final readonly class EchangePasse
{
    /**
     * @param list<int> $personIds les fiches que la réponse citait
     */
    public function __construct(
        public string $question,
        public string $reponse,
        public array $personIds,
    ) {
    }
}
