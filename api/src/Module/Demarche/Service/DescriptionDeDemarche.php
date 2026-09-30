<?php

declare(strict_types=1);

namespace App\Module\Demarche\Service;

use App\Module\Demarche\Enum\FormeDActe;
use App\Module\Demarche\Enum\NatureDActe;

/** Ce qui décrit une démarche : l'acte visé, à qui on le demande, qui il concerne. */
final readonly class DescriptionDeDemarche
{
    /** @param list<int> $personIds */
    public function __construct(
        public NatureDActe $nature,
        public FormeDActe $forme,
        public string $intitule,
        public ?string $commune,
        public ?string $dateDeLActe,
        public string $destinataire,
        public array $personIds,
    ) {
    }
}
