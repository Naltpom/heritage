<?php

declare(strict_types=1);

namespace App\Module\Core\Contract;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * La **couture** par laquelle un module déclare les références inter-modules qu'il porte —
 * même motif que {@see DeclarationDeQuotasInterface}.
 *
 * ## Pourquoi chaque module déclare les siennes
 *
 * L'ADR 0002 retire les clés étrangères entre modules et les remplace par quatre mesures,
 * dont la commande `heritage:verifier-integrite`. Elle aurait pu tout lire elle-même, d'une
 * seule requête par flèche : `LEFT JOIN tree_person … WHERE tree_person.id IS NULL`. C'est
 * précisément la jointure inter-modules que la règle 2 interdit, et elle aurait recopié dans
 * un fichier unique le schéma de neuf modules — une carte qui vieillit sans que personne ne
 * la voie vieillir.
 *
 * Chaque module répond donc pour ses **propres** tables : il sait quelles colonnes portent
 * un identifiant étranger, sous quelle portée, et lesquelles sont faites pour survivre à
 * leur cible. Il ne lit que chez lui ({@see \App\Module\Core\Service\ReleveurDeReferences}).
 *
 * **Lecture seule**, sans condition : la commande tourne en CI sur les fixtures et en
 * production sur la vraie base.
 */
#[AutoconfigureTag('core.references_inter_modules')]
interface ReferencesInterModulesInterface
{
    /** @return list<ReferenceInterModules> */
    public function references(): array;
}
