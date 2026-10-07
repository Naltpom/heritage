<?php

declare(strict_types=1);

namespace App\Module\Soutien\Service;

/**
 * La lecture du profil public d'un compte Liberapay.
 *
 * Une interface pour une seule implémentation réelle ({@see LiberapayEnLigne}) : c'est la
 * couture par laquelle les tests remplacent le réseau, comme le géocodeur de `Tree`.
 */
interface SourceLiberapay
{
    /** @throws LiberapayIndisponible */
    public function profil(string $pseudo): ProfilLiberapay;
}
