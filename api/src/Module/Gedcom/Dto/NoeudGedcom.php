<?php

declare(strict_types=1);

namespace App\Module\Gedcom\Dto;

/**
 * Une ligne logique du fichier GEDCOM, avec les lignes de niveau inférieur qu'elle porte.
 *
 * **Un arbre, et non la pile de drapeaux des scripts Python d'origine.** `build_data.py`
 * et `graphe.py` retiennent la structure avec un booléen « suis-je encore dans le bloc
 * BIRT ? » remis à zéro à chaque ligne de niveau 1. Ça marche tant qu'on ne descend pas
 * à trois niveaux — et ce fichier y descend : `1 SOUR / 2 DATA / 3 TEXT`,
 * `1 SOUR / 2 EVEN / 3 ROLE`, `1 RESI / 2 ADDR / 3 ADR1`. Reconstruire l'arbre une fois
 * coûte moins cher que de rejouer le drapeau pour chaque sous-tag, et surtout ça ne se
 * trompe pas.
 *
 * `valeur` et `enfants` restent modifiables parce que le lecteur les complète après
 * coup : `CONC` rallonge la valeur, `CONT` y ajoute une ligne, et les enfants arrivent
 * au fil de la lecture.
 */
final class NoeudGedcom
{
    /** @var list<self> */
    public array $enfants = [];

    public function __construct(
        public readonly int $niveau,
        /** L'identifiant d'enregistrement, arobases comprises : `@I500001@`. Seuls les niveaux 0 en portent. */
        public readonly ?string $xref,
        public readonly string $tag,
        public string $valeur,
        /** Numéro de la ligne **physique** où le tag apparaît, pour situer une anomalie sans rouvrir le fichier. */
        public readonly int $ligne,
    ) {
    }

    public function ajouter(self $enfant): void
    {
        $this->enfants[] = $enfant;
    }

    /** Le premier enfant portant ce tag, ou `null`. */
    public function enfant(string $tag): ?self
    {
        foreach ($this->enfants as $enfant) {
            if ($enfant->tag === $tag) {
                return $enfant;
            }
        }

        return null;
    }

    /**
     * Tous les enfants portant l'un de ces tags, **dans l'ordre du fichier**.
     *
     * L'ordre n'est pas un détail : c'est lui qui donne `tree_child.sibling_order`, que
     * le moteur de placement consomme tel quel.
     *
     * @return list<self>
     */
    public function tous(string ...$tags): array
    {
        $trouves = [];

        foreach ($this->enfants as $enfant) {
            if (\in_array($enfant->tag, $tags, true)) {
                $trouves[] = $enfant;
            }
        }

        return $trouves;
    }

    /** La valeur brute du premier enfant portant ce tag, ou `null` s'il n'y en a pas. */
    public function valeurDe(string $tag): ?string
    {
        return $this->enfant($tag)?->valeur;
    }

    /**
     * La valeur du premier enfant portant ce tag, déséchappée et débarrassée de ses
     * espaces de bord ; `null` si le tag est absent **ou** si sa valeur est vide.
     *
     * C'est l'accesseur à utiliser pour tout ce qui part en base : un `2 SURN ` vide,
     * et il y en a, ne doit pas donner une chaîne vide déguisée en donnée.
     */
    public function texteDe(string $tag): ?string
    {
        $noeud = $this->enfant($tag);

        if (null === $noeud) {
            return null;
        }

        $texte = $noeud->texte();

        return '' === $texte ? null : $texte;
    }

    /**
     * La valeur de ce nœud, déséchappée.
     *
     * La norme GEDCOM 5.5.1 écrit une arobase littérale `@@` pour ne pas la confondre
     * avec le début d'un pointeur. Sans ce déséchappement, l'adresse de courriel du
     * fichier ressort avec deux arobases.
     */
    public function texte(): string
    {
        return trim(str_replace('@@', '@', $this->valeur));
    }

    public function a(string $tag): bool
    {
        return null !== $this->enfant($tag);
    }
}
