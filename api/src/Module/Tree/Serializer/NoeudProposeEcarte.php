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
 * ── Le cas qui dominait, et qui ne se produit plus ──────────────────────────────
 *
 * `enchainement` désignait la forme **normale** d'une branche proposée : « Morille, fille
 * de Girolle » où Girolle est elle-même une fiche de la même demande. L'ancre valait
 * `#1/p1`, qui n'est dans aucun placement partagé — celui-ci ne contient que l'arbre
 * validé —, et tout ce qui pendait derrière la racine tombait. C'était la majorité des
 * absents.
 *
 * Ce n'était pas une fatalité, c'était une seule passe de résolution.
 * {@see PropositionsProjetees} résout désormais les ancrages **par vagues** : ce qui
 * s'ancre au validé se pose d'abord, puis ce qui s'ancre à ce qui vient d'être posé. Une
 * branche enchaînée se place en entier.
 *
 * `enchainement` reste, pour ce qu'aucune vague ne rattrape : une ancre proposée qui n'est
 * elle-même jamais posée, ou un cycle. C'est devenu rare, et c'est exactement pour cela
 * qu'il faut continuer à le dire — rien, dans `demandes`, ne laisse deviner qu'une fiche
 * manque.
 *
 * ── Un motif retiré : `sans_couple` ─────────────────────────────────────────────
 *
 * Il nommait « enfant d'un parent qui n'a aucun couple » : le rendu ne savait ancrer un
 * enfant que sous un losange, et il n'y en avait pas. Le constat était exact, la
 * conclusion non — la validation, elle, **crée** cette famille (§ 6.4), et ce calque
 * existe pour montrer ce que la validation ferait. Le losange provisoire se pose donc
 * sous le parent, et le motif n'a plus d'objet. Il n'est pas laissé en place vide : une
 * constante que plus rien n'émet finit par être réemployée pour autre chose.
 */
final readonly class NoeudProposeEcarte
{
    /** L'ancre désigne une autre fiche **proposée**, qui n'est donc dans aucun placement. */
    public const string ENCHAINEMENT = 'enchainement';

    /** L'ancre désigne une personne ou une famille **validée** qui n'est pas dans le graphe. */
    public const string ANCRE_ABSENTE = 'ancre_absente';

    /**
     * Aucun lien ne porte cette fiche, **dans un sens ni dans l'autre** : elle flotterait
     * hors de l'arbre (§ 6.4 du dossier).
     *
     * « Dans un sens ni dans l'autre » n'est pas une précision de style. Ce motif sortait
     * autrefois sur des fiches parfaitement rattachées, parce que les liens n'étaient lus
     * que par leur sujet — et l'écran qui ajoute un parent ou un conjoint met en sujet la
     * fiche qu'on complète, pas celle qu'on ajoute. Voir
     * {@see PropositionsProjetees::rattachements()}.
     */
    public const string SANS_LIEN = 'sans_lien';

    /** L'ancre est là, mais sa rangée ne se lit pas — le nœud tomberait sur celle de l'ego. */
    public const string GENERATION_INDETERMINEE = 'generation_indeterminee';

    /**
     * Traduit, puis refusé par le moteur : l'ancre a disparu entre les deux passes.
     * Posé par {@see NormaliseurDeGraphe}, seul à voir le
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
