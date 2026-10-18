<?php

declare(strict_types=1);

namespace App\Module\Noel\Contract;

/** Un fichier image sur le disque, prêt à servir. */
final readonly class PhotoDuCompte
{
    public function __construct(
        public string $chemin,
        public string $mime,
        public string $empreinte,
    ) {
    }
}
