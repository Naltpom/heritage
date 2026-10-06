<?php

declare(strict_types=1);

namespace App\Module\Core\Contract;

/**
 * Le repli de {@see ArbreDuLecteurInterface} tant que `Tree` n'est pas branché : rien
 * n'est retiré. Ouvert et non fermé, parce que ce contrat ne protège rien — le cercle de
 * visibilité s'applique de toute façon ; un repli fermé viderait les onglets d'Explorer.
 */
final class ArbreDuLecteurOuvert implements ArbreDuLecteurInterface
{
    public function filtrer(array $personIds, bool $toutLaBase = false): array
    {
        return array_values($personIds);
    }
}
