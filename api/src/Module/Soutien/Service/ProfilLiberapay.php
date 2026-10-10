<?php

declare(strict_types=1);

namespace App\Module\Soutien\Service;

/** Ce qu'on retient du profil public d'un compte Liberapay, à l'instant où on le lit. */
final readonly class ProfilLiberapay
{
    public function __construct(
        public int $recuParSemaineCentimes,
        public string $devise,
        public int $donateurs,
    ) {
    }
}
