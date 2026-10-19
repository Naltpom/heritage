<?php

declare(strict_types=1);

namespace App\Module\Evenement\Contract;

/**
 * Ce que `Evenement` demande à l'arbre : des noms, et qui est encore en vie.
 *
 * `Tree` l'implémente (`Tree\Service\VueDeLArbrePourEvenements`). Le contrat ne transporte
 * que des identifiants et des chaînes : aucune entité ne franchit la frontière (ADR 0002).
 *
 * Pas de couples : une invitation n'a que faire de qui est marié à qui. Le port a été taillé
 * pour `Evenement` seul — un module qui en lit un autre par son contrat se lie à des besoins
 * qui ne sont pas les siens, et le jour où l'autre voudrait une date de plus, celui-ci la
 * recevrait sans l'avoir demandée.
 *
 * ## Ce qui sort d'ici : des noms **que l'appelant a passés au cercle**
 *
 * Le port ne décide d'aucune visibilité : il rend le **vrai** nom affiché de chaque personne
 * demandée. Depuis la révision du 2026-10-01 de l'ADR 0003, ce nom ne fait plus partie de la
 * liste blanche d'une personne vivante hors cercle — c'est donc à `Evenement` de ne demander
 * que des personnes du cercle du lecteur (`ServiceDesEvenements::dansLeCercle()`) avant
 * d'enregistrer ou de rendre un nom. Aucune date, aucun lieu : ce module n'en a pas besoin.
 */
interface PortArbrePourEvenements
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
     * Les personnes **qui ne sont pas vivantes**, parmi celles demandées.
     *
     * « Vivante » au sens de la règle unique du produit (`Tree\Service\ReglePersonneVivante`)
     * — celle qui décide du champ `vivant` du graphe, dans lequel l'écran choisit les
     * invités. Une seconde définition ferait proposer à l'écran des personnes que l'API
     * refuserait ensuite, ou l'inverse.
     *
     * @param list<int> $personIds
     *
     * @return list<int>
     */
    public function nonVivantes(array $personIds): array;
}
