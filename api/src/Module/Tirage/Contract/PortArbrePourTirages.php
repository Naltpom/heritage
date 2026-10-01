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
 * ## Ce qui sort d'ici est de la liste blanche
 *
 * Le nom affiché d'une personne, **même vivante et hors cercle**, fait partie de ce que
 * l'arbre expose à tout compte connecté (ADR 0003, § 11.1 du contrat). Le fait « ces deux
 * personnes forment un couple » est la position dans le graphe, qui y figure aussi. Aucune
 * date, aucun lieu : ce module n'en a pas besoin.
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
