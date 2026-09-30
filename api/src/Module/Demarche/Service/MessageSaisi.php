<?php

declare(strict_types=1);

namespace App\Module\Demarche\Service;

use App\Module\Demarche\Enum\CanalDEchange;
use App\Module\Demarche\Enum\SensDEchange;

final readonly class MessageSaisi
{
    public function __construct(
        public SensDEchange $sens,
        public CanalDEchange $canal,
        public \DateTimeImmutable $date,
        public ?string $de,
        public ?string $a,
        public ?string $objet,
        public string $texte,
        public ?string $reference,
    ) {
    }
}
