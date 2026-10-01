<?php

declare(strict_types=1);

namespace App\Module\Tree\ValueObject;

/**
 * L'entrée du moteur de placement : l'arbre entier, en mémoire (ADR 0005).
 *
 * **L'ordre des deux listes fait partie du contrat.** Ce n'est pas une commodité : le
 * point fixe en 12 passes et le décrochage rangée par rangée départagent par l'ordre de
 * parcours deux nœuds à égalité de position. Le script d'origine parcourait les
 * dictionnaires `indi` et `fam` dans l'ordre du fichier GEDCOM ; le portage doit
 * recevoir le même ordre, sans quoi quelques nœuds se retrouvent décalés de la largeur
 * d'une fiche sans qu'aucun invariant ne s'en plaigne.
 *
 * Dans l'application, l'ordre à fournir est celui de `tree_person.id` puis
 * `tree_family.id` croissants — l'import GEDCOM les crée dans l'ordre du fichier, donc
 * les deux coïncident.
 */
final readonly class JeuDePlacement
{
    /**
     * @param list<PersonnePlacable> $personnes dans l'ordre d'origine, jamais retriées
     * @param list<FamillePlacable>  $familles  dans l'ordre d'origine, jamais retriées
     * @param string                 $egoId     la personne racine, ramenée à `x = 0` en fin de calcul
     */
    public function __construct(
        public array $personnes,
        public array $familles,
        public string $egoId,
    ) {
    }

    /**
     * **Le jeu tel que la géométrie partagée a le droit de le lire** : les personnes
     * vivantes y perdent leur nom, leur nom d'usage et leur date de naissance.
     *
     * ── POURQUOI ─────────────────────────────────────────────────────────────────
     *
     * Révision du 2026-10-01 de l'ADR 0003 : un vivant hors cercle n'expose plus son nom.
     * L'affinage mesure déjà toute fiche vivante à une constante
     * ({@see \App\Module\Tree\Service\MesureDeFiche::LARGEUR_AFFICHEE_VIVANT}) — mais il
     * **part des abscisses du moteur**, qui mesure, lui, sur le nom (`graphe.py`, preuve
     * des 377 positions). Mesuré sur l'arbre synthétique : changer le nom d'un cousin
     * vivant déplaçait encore quatre fiches d'environ 10⁻⁷. Infime à l'écran, et pourtant
     * une fonction du nom masqué — exactement ce que la règle « tout champ dérivé d'une
     * donnée masquée est masqué » interdit.
     *
     * Le moteur n'est pas modifié : c'est **son entrée** qui l'est, dans le seul chemin
     * qui sert le graphe ({@see \App\Module\Tree\Service\PlacementPartage}). La preuve de
     * non-régression, qui appelle le moteur sur le jeu de référence entier, n'est pas
     * touchée.
     *
     * L'année de naissance (`anNais`) reste : elle n'entre dans aucune largeur, seulement
     * dans l'ordre d'une fratrie, que la fiche d'un parent visible donne déjà
     * (`siblingOrder`). Le décès aussi : une personne vivante n'en a pas.
     */
    public function sansTexteDesVivants(): self
    {
        $personnes = [];

        foreach ($this->personnes as $p) {
            $personnes[] = $p->vivant
                ? new PersonnePlacable($p->id, '', '', '', $p->sexe, '', $p->deces, $p->anNais, true, $p->generation)
                : $p;
        }

        return new self($personnes, $this->familles, $this->egoId);
    }
}
