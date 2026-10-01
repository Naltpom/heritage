<?php

declare(strict_types=1);

namespace App\Module\Core\Contract;

use Symfony\Component\RateLimiter\RateLimit;

/**
 * Le **port** par lequel `Assistant` et `Media` décomptent un usage avec la limite
 * **effective** du compte — sa limite personnelle si l'administrateur en a posé une, le
 * défaut de configuration sinon.
 *
 * Contrat déclaré par `Core` et non par l'un des deux consommateurs : c'est `Core` qui
 * possède `core_user` et la table des limites personnelles (`core_quota_limite`), et
 * l'écran d'administration des comptes. Deux modules le consomment ; il n'installe dans
 * le noyau aucun métier de l'un ou de l'autre (ADR 0002, « Quand un contrat monte dans
 * `Core` ») — seulement « compter, avec la bonne limite ».
 */
interface QuotasDUsageInterface
{
    /**
     * Décompte **un** usage et rend l'état du compteur.
     *
     * `isAccepted()` faux : l'usage est refusé, et rien n'a été décompté. `getLimit()` porte
     * la limite effective — c'est elle que le message `429` doit citer, pas le défaut. Une
     * limite **0** refuse toujours, sans toucher au compteur.
     *
     * @param int|null $userId le compte ; ignoré (et admis `null`) pour un quota global
     */
    public function consommer(Quota $quota, ?int $userId): RateLimit;
}
