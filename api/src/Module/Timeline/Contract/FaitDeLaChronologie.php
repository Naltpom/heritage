<?php

declare(strict_types=1);

namespace App\Module\Timeline\Contract;

use App\Module\Core\ValueObject\UncertainDate;

/**
 * Un `tree_fact` daté, réduit à ce qu'un **jalon** du § 9.1 porte : un type, une année,
 * un libellé, et la personne dont il relève.
 *
 * ## Pourquoi seulement les faits de sujet `person`
 *
 * `tree_fact` admet exactement un sujet, `person` **ou** `family`
 * (`ck_tree_fact_sujet_unique`, `num_nonnulls(...) = 1`). Or le § 9.1 fait de `personId`
 * une clé **obligatoire** du jalon : un fait de famille n'a pas de `personId` à y mettre,
 * et lui en inventer un — le premier époux, par exemple — serait attribuer à quelqu'un un
 * événement qui ne le nomme pas. Les faits de famille sont donc hors de ce port, par
 * absence de forme dans laquelle les rendre, et non par oubli.
 *
 * Conséquence pour l'implémenteur : **ne rends que les faits dont `person_id` est
 * renseigné**, et seulement ceux qui portent une date exploitable — un fait sans date n'a
 * pas de place sur un axe du temps.
 *
 * ## `type` est une chaîne, et c'est un choix d'architecture
 *
 * `Tree\Enum\FactType` vit dans `Enum/`, c'est-à-dire dans l'**interne** de `Tree` au sens
 * de l'ADR 0002 révisé : le typer ici obligerait `Timeline` à un `use` illicite. Le port
 * transporte donc le `value` de l'énumération (`recensement`, `residence`, `profession`…),
 * qui est exactement ce que le § 9.1 met dans la clé `type` du jalon. Un `string` qui
 * traverse la frontière et redevient un libellé côté `Tree` : personne ne perd rien.
 *
 * ## `happened` reste une `UncertainDate`
 *
 * Même raison que sur {@see PersonneDeLaChronologie} : le port ne décide pas de l'année.
 * Le § 9.1 demande bien un `annee` scalaire pour un jalon — un jalon *est* un point, c'est
 * le contrat qui le dit —, mais ce choix de borne est un calcul de rendu, et il doit être
 * **visible à l'endroit où il est fait** plutôt que cuit dans un adaptateur.
 */
final readonly class FaitDeLaChronologie
{
    /**
     * @param int           $personId  `tree_fact.person_id`, toujours renseigné ici
     * @param string        $type      le `value` de `Tree\Enum\FactType`
     * @param string        $typeLabel le libellé français de ce type (« Recensement »)
     * @param string|null   $label     la valeur textuelle du fait (« Tisserand »)
     * @param UncertainDate $happened  la date de l'événement ; son `place` porte le lieu
     */
    public function __construct(
        public int $personId,
        public string $type,
        public string $typeLabel,
        public ?string $label,
        public UncertainDate $happened,
    ) {
    }
}
