<?php

declare(strict_types=1);

namespace App\Module\Tree\Serializer;

use App\Module\Core\Contract\NoeudPropose;

/**
 * Un nœud proposé que la projection **n'a pas placé**, et la raison.
 *
 * ── Pourquoi cet objet existe ───────────────────────────────────────────────────
 *
 * {@see PropositionsProjetees} et {@see \App\Module\Tree\Service\ProjecteurDePlacement}
 * omettent tous deux un nœud dont l'ancre n'est pas dans le graphe validé, plutôt que de
 * le poser à `x = 0` où il recouvrirait l'ego. **Ce comportement est le bon et ne change
 * pas** : dix-sept fiches empilées sur l'ego seraient pires qu'absentes.
 *
 * Ce qui était faux, c'est le silence. Mesuré sur un cas réel : cinq demandes formant une
 * branche de vingt-deux personnes ont rendu un calque annonçant `actif: true` et
 * `demandes: [1,2,3,4,5]` avec **cinq nœuds placés et dix-sept écartés**, et rien dans la
 * charge ne disait l'écart. Un dispositif qui ne peut plus tout montrer et qui ne le dit
 * pas est précisément le défaut que ce projet traque : le lecteur croyait voir la branche
 * entière.
 *
 * ── Le cas qui domine, et pourquoi il n'est pas un accident ─────────────────────
 *
 * `enchainement` n'est pas une cible disparue mais la forme normale d'une branche
 * proposée : « Morille, fille de Girolle » où Girolle est elle-même une fiche de la même
 * demande. L'ancre vaut alors `#1/p1`, qui n'est dans aucun placement partagé — celui-ci
 * ne contient que l'arbre validé. Seules les personnes rattachées à quelqu'un qui existe
 * déjà se placent ; tout ce qui pend derrière elles tombe. C'est pour cela que le compte
 * doit être rendu au lieu d'être déduit : rien, dans `demandes`, ne laisse deviner que la
 * moitié d'une demande manque.
 */
final readonly class NoeudProposeEcarte
{
    /** L'ancre désigne une autre fiche **proposée**, qui n'est donc dans aucun placement. */
    public const string ENCHAINEMENT = 'enchainement';

    /** L'ancre désigne une personne ou une famille **validée** qui n'est pas dans le graphe. */
    public const string ANCRE_ABSENTE = 'ancre_absente';

    /**
     * « Enfant de » avec un seul parent nommé, et ce parent n'a **aucun couple** dans
     * l'arbre : le projecteur ne sait ancrer un enfant que sous un losange, et il n'y en
     * a pas. La validation en créerait un ; le rendu, lui, ne devine pas.
     */
    public const string SANS_COUPLE = 'sans_couple';

    /** Aucun lien ne porte cette fiche : elle flotterait hors de l'arbre (§ 6.4 du dossier). */
    public const string SANS_LIEN = 'sans_lien';

    /** L'ancre est là, mais sa rangée ne se lit pas — le nœud tomberait sur celle de l'ego. */
    public const string GENERATION_INDETERMINEE = 'generation_indeterminee';

    /**
     * Traduit, puis refusé par le moteur : l'ancre a disparu entre les deux passes.
     * Posé par {@see \App\Module\Tree\Serializer\NormaliseurDeGraphe}, seul à voir le
     * placement final.
     */
    public const string PLACEMENT_REFUSE = 'placement_refuse';

    public function __construct(
        public NoeudPropose $source,
        /** L'une des constantes ci-dessus. Français, comme toute clé qui n'est pas une colonne (§ 1.2). */
        public string $raison,
    ) {
    }
}
