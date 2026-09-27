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
}
