<?php

declare(strict_types=1);

namespace App\Module\Core\Security;

use App\Module\Core\Service\ProfilGoogle;
use Symfony\Component\Security\Core\Exception\AuthenticationException;

/**
 * Un ID token Google valide, mais aucun compte ne lui correspond : le front propose de
 * s'inscrire (site de Noël) avec le jeton d'inscription signé qui l'accompagne.
 */
final class InscriptionGoogleRequise extends AuthenticationException
{
    public function __construct(
        public readonly ProfilGoogle $profil,
        public readonly string $jeton,
    ) {
        parent::__construct('Aucun compte pour ce compte Google.');
    }

    public function getMessageKey(): string
    {
        return 'Aucun compte pour ce compte Google.';
    }
}
