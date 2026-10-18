<?php

declare(strict_types=1);

namespace App\Module\Core\Security;

use Symfony\Component\Security\Http\Authenticator\Passport\Badge\BadgeInterface;

/** Marque une connexion faite avec Google, pour le journal (`LoginAuditSubscriber`). */
final class GoogleBadge implements BadgeInterface
{
    public function isResolved(): bool
    {
        return true;
    }
}
