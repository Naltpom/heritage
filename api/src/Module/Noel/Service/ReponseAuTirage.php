<?php

declare(strict_types=1);

namespace App\Module\Noel\Service;

use App\Module\Noel\Enum\Presence;
use App\Module\Noel\Enum\Reponse;
use App\Module\Noel\Enum\Revelation;

final readonly class ReponseAuTirage
{
    public function __construct(
        public Reponse $reponse,
        public ?Presence $presence,
        public ?Revelation $vote,
        public ?int $conjointUserId,
    ) {
    }
}
