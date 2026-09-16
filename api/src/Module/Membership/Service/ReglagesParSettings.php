<?php

declare(strict_types=1);

namespace App\Module\Membership\Service;

use App\Module\Core\Service\SettingService;

/**
 * {@see ReglagesDeMembership} lu dans `core_setting`, par le service de réglages de `Core`.
 *
 * Les bornes sont **réappliquées ici**, alors que `PATCH /api/administration/reglages/{cle}`
 * les vérifie déjà à l'écriture (§ 10.2 du contrat). Ce n'est pas une redondance inutile :
 * `min_value` et `max_value` sont des colonnes, une restauration partielle ou un correctif
 * SQL à chaud peut poser un `cercle.generations = 40`, et ce qui en sortirait n'est pas une
 * erreur visible mais **un cercle qui couvre l'arbre entier**. Une fuite, donc, et
 * silencieuse. Le bornage à la lecture est la défense en profondeur qui va avec.
 *
 * Le repli, quand la ligne manque — base non migrée, restauration incomplète —, est la
 * valeur par défaut de la migration (§ 2.2 du modèle). Une lecture de réglage ne doit
 * jamais faire tomber une requête, et surtout pas en ouvrant le cercle.
 */
final readonly class ReglagesParSettings implements ReglagesDeMembership
{
    public const string CLE_TTL_JOURS = 'invitation.ttl_jours';
    public const string CLE_TENTATIVES_PAR_HEURE = 'invitation.tentatives_par_heure';
    public const string CLE_CANDIDATS_MAX = 'invitation.candidats_max';

    public function __construct(private SettingService $reglages)
    {
    }

    public function generationsDuCercle(): int
    {
        return $this->borne($this->reglages->generationsDuCercle(), 1, 6);
    }

    public function ttlDInvitationEnJours(): int
    {
        return $this->borne($this->reglages->entier(self::CLE_TTL_JOURS, 7), 1, 30);
    }

    public function tentativesParHeure(): int
    {
        return $this->borne($this->reglages->entier(self::CLE_TENTATIVES_PAR_HEURE, 5), 1, 20);
    }

    public function candidatsMax(): int
    {
        return $this->borne($this->reglages->entier(self::CLE_CANDIDATS_MAX, 3), 1, 5);
    }

    private function borne(int $valeur, int $min, int $max): int
    {
        return max($min, min($max, $valeur));
    }
}
