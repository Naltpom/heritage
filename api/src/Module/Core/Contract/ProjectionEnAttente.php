<?php

declare(strict_types=1);

namespace App\Module\Core\Contract;

/**
 * Tout ce qu'un lecteur donné doit voir **en plus** de l'arbre validé.
 *
 * C'est l'étage 2 de l'ADR 0005 : le placement partagé est calculé une fois pour tout le
 * monde sur l'arbre validé et mis en cache sous `placement.{empreinte}` ; cette
 * projection-ci s'y **ajoute**, pour un lecteur, et **n'est jamais mise en cache**. C'est
 * ce qui évite d'avoir un graphe par compte — l'architecture que le projet a
 * explicitement écartée (arbitrage B).
 *
 * `demandes` alimente directement `calque.demandes` du § 5.2 du contrat d'API, et
 * {@see estVide()} alimente `calque.actif` : le front n'affiche la légende « en attente »
 * que s'il y a quelque chose en attente.
 */
final readonly class ProjectionEnAttente
{
    /**
     * @param list<int>            $demandes les demandes incorporées : celles du lecteur, ou
     *                                       **toutes** pour un administrateur (§ 9.5)
     * @param list<NoeudPropose>   $noeuds
     * @param list<AncragePropose> $ancrages
     */
    public function __construct(
        public array $demandes,
        public array $noeuds,
        public array $ancrages,
    ) {
    }

    public static function vide(): self
    {
        return new self([], [], []);
    }

    /**
     * Y a-t-il quelque chose à montrer ?
     *
     * On regarde les **ancrages** autant que les nœuds : une demande `ajout_lien` ne
     * propose aucune personne — « ils étaient frère et sœur », « elle s'est remariée avec
     * lui » — et elle a pourtant des arêtes à dessiner.
     */
    public function estVide(): bool
    {
        return [] === $this->noeuds && [] === $this->ancrages;
    }
}
