<?php

declare(strict_types=1);

namespace App\Module\Core\Contract;

/**
 * L'implémentation **par défaut** de {@see FichesTitulairesInterface} : aucun titulaire.
 *
 * Sans `Membership` branché, « Vérifiée » retombe sur la seule règle des actes
 * (`bestQuay ≥ 2`). Le défaut sûr : une fiche ne paraît jamais mieux tenue qu'elle ne
 * l'est.
 */
final class AucuneFicheTitulaire implements FichesTitulairesInterface
{
    public function fichesTitulaires(): array
    {
        return [];
    }
}
