<?php

declare(strict_types=1);

namespace App\Module\Core\Contract;

/**
 * L'implémentation **par défaut** de {@see AnnuaireDesMembresInterface} : elle ne connaît
 * personne.
 *
 * Même raisonnement que `Core\Security\CercleFerme`, et le même arbitrage : sans
 * `Membership` branché, la bascule « voir comme un membre » rend `404` et la charge
 * d'administration ne porte pas de `membre`. Des écrans incomplets, jamais une identité
 * inventée.
 *
 * Elle n'avertit pas dans les journaux, à la différence de `CercleFerme` : ne pas
 * connaître les membres n'expose rien, alors que ne pas connaître le cercle ferait taire
 * une règle de confidentialité. Deux absences, deux niveaux de bruit.
 */
final class AnnuaireVide implements AnnuaireDesMembresInterface
{
    public function libelleDuMembre(int $memberId): ?string
    {
        return null;
    }

    public function membreDuCompte(int $userId): ?array
    {
        return null;
    }

    public function userIdDuMembre(int $memberId): ?int
    {
        return null;
    }

    public function personneDuCompte(int $userId): ?int
    {
        return null;
    }
}
