<?php

declare(strict_types=1);

namespace App\Module\Timeline\Contract;

/**
 * Ce que {@see PortChronologieArbre::vue()} rend : les personnes et les faits datés, en
 * un seul aller-retour.
 *
 * Un seul objet plutôt que deux méthodes, pour une raison mesurable : la chronologie lit
 * **tout** l'arbre à chaque requête — il n'y a pas de pagination, un axe du temps se
 * dessine entier ou pas du tout. Deux appels séparés inviteraient un implémenteur à
 * ouvrir deux fois la base pour une seule réponse HTTP.
 */
final readonly class ArbreDeLaChronologie
{
    /**
     * @param list<PersonneDeLaChronologie> $personnes **toutes** les personnes validées,
     *                                                 sans aucun filtre de confidentialité
     * @param list<FaitDeLaChronologie>     $faits     les `tree_fact` datés de sujet
     *                                                 `person`, même règle
     */
    public function __construct(
        public array $personnes = [],
        public array $faits = [],
    ) {
    }

    /** L'arbre vide — ce que rend le repli fail-closed tant que rien n'est branché. */
    public static function vide(): self
    {
        return new self();
    }
}
