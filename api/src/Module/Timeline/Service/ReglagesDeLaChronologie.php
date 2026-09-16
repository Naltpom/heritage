<?php

declare(strict_types=1);

namespace App\Module\Timeline\Service;

use App\Module\Core\Service\SettingService;

/**
 * Les réglages de la vue chronologique, lus dans `core_setting` (§ 10.2 du contrat).
 *
 * Un seul aujourd'hui : `timeline.bande_annees_defaut`, la largeur de bande servie à un
 * compte qui n'a **pas encore** enregistré de préférence. À ne pas confondre avec
 * `tml_preference.band_years`, qui est le choix d'un lecteur : celui-ci est le point de
 * départ commun, celui-là est personnel.
 *
 * ## Les deux bornes du projet ne sont pas les mêmes, et ce n'est pas une erreur
 *
 * | | plage | posée par |
 * |---|---|---|
 * | le réglage d'administration | **1 à 200** | `core_setting.min_value` / `max_value`, migration initiale |
 * | la préférence d'un compte | **1 à 500** | `ck_tml_preference_bande` |
 *
 * L'administrateur choisit un défaut raisonnable pour tout le monde ; un lecteur peut
 * aller plus loin pour lui seul. On borne néanmoins la lecture à 1–500 ci-dessous, et
 * l'assertion de {@see CalculDesBandes} la redouble : un réglage écrit à `0` par une
 * restauration partielle ferait tourner la boucle des bandes indéfiniment, et une lecture
 * de réglage ne doit jamais faire tomber une requête.
 */
final class ReglagesDeLaChronologie
{
    public const string CLE_BANDE_DEFAUT = 'timeline.bande_annees_defaut';

    /** Le défaut du § 9.1, égal à celui de la migration : les deux doivent rester d'accord. */
    public const int DEFAUT_BANDE = 10;

    /** Les bornes de `ck_tml_preference_bande`, reprises à l'identique. */
    public const int BANDE_MIN = 1;
    public const int BANDE_MAX = 500;

    public function __construct(private readonly SettingService $reglages)
    {
    }

    public function bandeParDefaut(): int
    {
        $valeur = $this->reglages->entier(self::CLE_BANDE_DEFAUT, self::DEFAUT_BANDE);

        return max(self::BANDE_MIN, min(self::BANDE_MAX, $valeur));
    }

    public static function bandeEstValide(int $bande): bool
    {
        return $bande >= self::BANDE_MIN && $bande <= self::BANDE_MAX;
    }
}
