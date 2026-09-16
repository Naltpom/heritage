<?php

declare(strict_types=1);

namespace App\Module\Tree\Service;

use App\Module\Core\Service\SettingService;

/**
 * L'implémentation de {@see ReglagesDeLArbre} adossée à `core_setting`.
 *
 * C'est le seul point de `Tree` qui connaisse `Core\Service\SettingService`. Un appel de
 * service, pas une relation Doctrine : la frontière du § 0.4 interdit les secondes, pas
 * les premiers — c'est d'ailleurs ainsi que `Membership` appelle {@see TreeFingerprint}.
 */
final class ReglagesDeLArbreParSettings implements ReglagesDeLArbre
{
    public const string CLE_SEUIL_VIVANT = 'tree.seuil_vivant_annees';
    public const string CLE_PERSONNE_RACINE = 'tree.personne_racine_id';

    public function __construct(private readonly SettingService $reglages)
    {
    }

    public function seuilVivantAnnees(): int
    {
        return $this->reglages->entier(self::CLE_SEUIL_VIVANT, ReglePersonneVivante::SEUIL_DEFAUT);
    }

    public function personneRacineId(): ?int
    {
        // 0 sert de sentinelle « non réglé » : aucune séquence PostgreSQL ne le produit.
        $racine = $this->reglages->entier(self::CLE_PERSONNE_RACINE, 0);

        return $racine > 0 ? $racine : null;
    }
}
