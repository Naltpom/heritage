<?php

declare(strict_types=1);

namespace App\Module\Media\Service;

/**
 * Un avertissement rendu avec le média créé (§ 7.2 du contrat).
 *
 * Le seul émis à ce jour est `doublon_probable`. **Le doublon est un avertissement, pas
 * un refus** : le même acte de mariage étaye légitimement les fiches des deux époux et
 * de leurs quatre parents. La réponse est `201`, pas `409`.
 */
final readonly class Avertissement
{
    public function __construct(
        public string $code,
        public string $message,
        public ?int $mediaId = null,
    ) {
    }
}
