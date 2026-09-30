<?php

declare(strict_types=1);

namespace App\Module\Demarche\Contract;

/**
 * Ce que `Demarche` demande à l'arbre : le nom affiché des personnes qu'une démarche
 * concerne. `Tree` l'implémente (`Tree\Service\VueDeLArbrePourDemarches`).
 *
 * Le contrat ne transporte que des identifiants et des chaînes : aucune entité ne franchit
 * la frontière (ADR 0002). **Il ne décide pas de la visibilité** : c'est le cercle du noyau
 * qui le fait, dans ce module, avant tout appel.
 */
interface PortArbrePourDemarches
{
    /**
     * Le nom affiché de chaque personne **qui existe** parmi celles demandées. Une personne
     * inconnue est absente du résultat : c'est ainsi que l'appelant la refuse.
     *
     * @param list<int> $personIds
     *
     * @return array<int, string> indexé par identifiant de personne
     */
    public function libelles(array $personIds): array;
}
