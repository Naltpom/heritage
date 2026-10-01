<?php

declare(strict_types=1);

namespace App\Module\Core\Security;

use Symfony\Component\Security\Http\Authenticator\Passport\Badge\BadgeInterface;

/**
 * Marque un passeport émis par {@see CleDAccesAuthenticator}.
 *
 * Ne vérifie rien — il est résolu d'emblée. Il sert à **dire la méthode** : le journal
 * d'audit ({@see \App\Module\Core\EventSubscriber\LoginAuditSubscriber}) écrit
 * `methode: cle_d_acces` quand il le trouve, `mot_de_passe` sinon. On le lit sur le
 * passeport plutôt que sur l'authentificateur parce qu'en mode débogage Symfony enrobe
 * celui-ci dans un `TraceableAuthenticator`, et un `instanceof` y échouerait sans bruit.
 */
final class CleDAccesBadge implements BadgeInterface
{
    public function isResolved(): bool
    {
        return true;
    }
}
