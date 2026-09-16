<?php

declare(strict_types=1);

namespace App\Module\Media\Service;

use App\Module\Media\Entity\Media;

/**
 * Le média enregistré, et ce qu'il faut dire au déposant en plus.
 */
final readonly class ResultatDeTeleversement
{
    /** @param list<Avertissement> $avertissements */
    public function __construct(
        public Media $media,
        public array $avertissements = [],
    ) {
    }
}
