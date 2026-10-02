<?php

declare(strict_types=1);

namespace App\Module\Statistiques\Service;

use App\Module\Statistiques\Contract\PortStatistiquesArbre;
use App\Module\Statistiques\Contract\ReleveDeLArbre;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Le relevé de l'arbre, mis en cache **par état de l'arbre**.
 *
 * ── Ce qu'on met en cache, et ce qu'on n'y met pas ─────────────────────────────
 *
 * Le **relevé** (l'arbre complet, non filtré) est le même pour tous les lecteurs : il se
 * met en cache une fois. Les **statistiques**, elles, dépendent du lecteur — le cercle
 * décide de qui nommer et le seuil de discrétion de ce qu'on compte — : elles se calculent
 * à chaque requête, sur le relevé en mémoire. C'est l'ordre inverse qui serait dangereux :
 * des statistiques mises en cache sous une clé qui oublierait le cercle serviraient celles
 * de l'administrateur à un membre. Le calcul, lui, est de l'arithmétique sur un millier
 * de lignes : quelques millisecondes.
 *
 * ── L'invalidation ─────────────────────────────────────────────────────────────
 *
 * La clé porte {@see PortStatistiquesArbre::jeton()} : un compte et une date de dernière
 * modification par table lue, plus le jour courant. Toute écriture validée dans l'arbre
 * (une demande appliquée, un import, une citation ajoutée) la change ; la page suivante
 * relit l'arbre. Une heure de vie en plus, pour qu'un changement qui échapperait au jeton
 * (une ligne modifiée sans horodatage) ne survive pas au-delà.
 */
final class ReleveEnCache
{
    /** Le préfixe porte une version : changer la forme du relevé, c'est la monter. */
    private const string PREFIXE = 'statistiques.releve.v1.';

    private const int DUREE = 3600;

    private ?ReleveDeLArbre $memoire = null;

    public function __construct(
        private readonly PortStatistiquesArbre $port,
        private readonly CacheInterface $cache,
    ) {
    }

    public function releve(): ReleveDeLArbre
    {
        if (null !== $this->memoire) {
            return $this->memoire;
        }

        $releve = $this->cache->get(self::PREFIXE.hash('sha256', $this->port->jeton()), function (ItemInterface $item): ReleveDeLArbre {
            $item->expiresAfter(self::DUREE);

            return $this->port->releve();
        });

        // Une entrée illisible (forme changée sans monter la version) : on relit l'arbre.
        if (!$releve instanceof ReleveDeLArbre) {
            $releve = $this->port->releve();
        }

        return $this->memoire = $releve;
    }
}
