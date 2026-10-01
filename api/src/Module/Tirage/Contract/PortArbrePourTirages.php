<?php

declare(strict_types=1);

namespace App\Module\Tirage\Contract;

/**
 * Ce que `Tirage` demande à l'arbre : des noms, et des couples.
 *
 * `Tree` l'implémente (`Tree\Service\VueDeLArbrePourTirages`). Le contrat ne transporte
 * que des identifiants et des chaînes : aucune entité ne franchit la frontière
 * (ADR 0002).
 *
 * ## Ce qui sort d'ici : des noms **que l'appelant a passés au cercle**
 *
 * Le port ne décide d'aucune visibilité : il rend le **vrai** nom affiché de chaque
 * personne demandée. Depuis la révision du 2026-10-01 de l'ADR 0003, ce nom ne fait plus
 * partie de la liste blanche d'une personne vivante hors cercle — c'est donc à `Tirage`
 * de ne demander que des personnes du cercle du lecteur
 * (`ServiceDesTirages::dansLeCercle()`) avant d'enregistrer ou de rendre un nom. Le fait
 * « ces deux personnes forment un couple » est la position dans le graphe, qui reste dans
 * la liste blanche. Aucune date, aucun lieu : ce module n'en a pas besoin.
 *
 * « Cette personne est décédée » n'en dit pas plus : c'est l'état que la recherche rend
 * déjà à côté de chaque nom (`vivant`), et le port le rend **sans la date** qui l'établit.
 */
interface PortArbrePourTirages
{
    /**
     * Le nom affiché de chaque personne **qui existe** parmi celles demandées.
     *
     * Une personne inconnue est absente du résultat : c'est ainsi que l'appelant la refuse.
     * Aucun filtre de cercle ici : l'appelant ne demande que des personnes que le lecteur
     * voit (voir l'en-tête).
     *
     * @param list<int> $personIds
     *
     * @return array<int, string> indexé par identifiant de personne
     */
    public function libelles(array $personIds): array;

    /**
     * Les couples formés **entre** les personnes demandées, divorcés exclus.
     *
     * @param list<int> $personIds
     *
     * @return list<array{0: int, 1: int}>
     */
    public function couples(array $personIds): array;

    /**
     * Les personnes **qui ne sont pas vivantes**, parmi celles demandées.
     *
     * « Vivante » au sens de la règle unique du produit (`Tree\Service\ReglePersonneVivante`,
     * § 9.2 du modèle) — celle-là même qui décide du champ `vivant` de la recherche. Un
     * tirage ne demande pas une autre définition : s'il en avait une à lui, la recherche
     * des tirages proposerait des personnes que l'API refuserait ensuite, ou l'inverse.
     *
     * Une personne inconnue n'est pas dans le résultat : {@see self::libelles()} la refuse.
     *
     * @param list<int> $personIds
     *
     * @return list<int>
     */
    public function nonVivantes(array $personIds): array;
}
