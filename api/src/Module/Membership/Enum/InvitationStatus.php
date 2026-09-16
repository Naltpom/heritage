<?php

declare(strict_types=1);

namespace App\Module\Membership\Enum;

/**
 * L'état d'un lien d'invitation (§ 4.5 du modèle de données).
 *
 * Colonne dénormalisée par rapport à `used_at`/`revoked_at`/`expires_at` : elle rend la
 * liste d'administration lisible d'un coup d'œil et s'indexe. Le service la maintient ;
 * `Expiree` est posée paresseusement à la première lecture après échéance.
 */
enum InvitationStatus: string
{
    case Active = 'active';
    case Utilisee = 'utilisee';
    case Expiree = 'expiree';
    case Revoquee = 'revoquee';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Utilisee => 'Utilisée',
            self::Expiree => 'Expirée',
            self::Revoquee => 'Révoquée',
        };
    }
}
