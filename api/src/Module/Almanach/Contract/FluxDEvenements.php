<?php

declare(strict_types=1);

namespace App\Module\Almanach\Contract;

/**
 * Ce que {@see PortEvenementsArbre::flux()} rend : les événements datés de l'arbre et les
 * lieux résolus, en un seul aller-retour.
 *
 * Un seul objet plutôt que deux méthodes, pour la même raison mesurable
 * qu'`ArbreDeLaChronologie` : les deux routes d'`Almanach` lisent **tout** — une carte se
 * dessine entière, un calendrier couvre l'année entière — et il n'y a aucune pagination à
 * servir. Deux appels séparés inviteraient un implémenteur à ouvrir deux fois la base pour
 * une seule réponse HTTP.
 *
 * ## Les deux listes ne sont pas appariées, et c'est voulu
 *
 * Un événement porte une chaîne `PLAC` dans sa date ; un lieu porte sa clé repliée. Le
 * rapprochement se fait au rendu, par {@see \App\Module\Core\Service\CleDeRecherche}. Le
 * port ne le fait pas lui-même pour une raison qui n'est pas de commodité :
 * **`resume.lieuNonResolu` est un chiffre qu'on veut publier**, et il n'existe que si
 * l'échec de rapprochement reste visible à l'endroit du rendu. Un port qui poserait
 * `placeId: null` aurait déjà avalé l'information.
 *
 * ## Ce que ce flux **n'est pas**
 *
 * Il n'est pas filtré. Il porte tout l'arbre validé, vivants compris, hors cercle compris.
 * C'est la doctrine du port, écrite en entier sur {@see PortEvenementsArbre} ; le filtre
 * vit dans {@see \App\Module\Almanach\Service\FluxVisible} et nulle part ailleurs.
 */
final readonly class FluxDEvenements
{
    /**
     * @param list<EvenementDeLArbre> $evenements **tous** les événements datés de l'arbre
     *                                            validé, sans aucun filtre de confidentialité
     * @param list<LieuResolu>        $lieux      les lignes de `tree_place` qui portent des
     *                                            coordonnées ; celles qui n'en ont pas ne
     *                                            traversent pas
     */
    public function __construct(
        public array $evenements = [],
        public array $lieux = [],
    ) {
    }

    /** Le flux vide — ce que rend le repli fail-closed tant que rien n'est branché. */
    public static function vide(): self
    {
        return new self();
    }
}
