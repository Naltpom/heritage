<?php

declare(strict_types=1);

namespace App\Module\Proposal\Contract;

/**
 * Les deux vocabulaires fermés d'une **annotation** — une note ou une citation —,
 * déclarés par celui qui les possède.
 *
 * ── Pourquoi un seul objet pour les deux natures ────────────────────────────────
 *
 * Parce que le modèle le dit avant nous : `NoteScope` est l'énumération des notes, et
 * `tree_citation.scope` la **réutilise telle quelle** — « c'est la même question »
 * (§ 3.9). Une note et une citation annotent toutes deux une ligne de l'arbre et
 * répondent au même « qu'est-ce que ceci étaye ? ». En déclarer deux copies laisserait
 * l'une vieillir sans que l'autre bouge.
 *
 * `qualites` ne concerne que les citations : une note n'a pas de niveau de confiance,
 * elle porte un raisonnement. Le constructeur de notes ignore ce champ.
 *
 * ── Le défaut que cet objet existe pour ne pas rejouer ───────────────────────────
 *
 * `quay` est nominalement proposable par `modification_citation` : la surface éditable le
 * déclare `ValueKind::Enum`, et un `enum` n'est validé au dépôt que comme **une chaîne** —
 * personne ne vérifie qu'elle vaut `0`, `1`, `2` ou `3`.
 *
 * **Mesuré le 17/09/2026, ce champ est en réalité inatteignable par ce chemin.** Sur une
 * citation de `quay = 1` qu'on essaie de porter à `2` : `oldValue` en entier rend `422`
 * (le normaliseur d'un `enum` exige une chaîne), `oldValue` en chaîne rend `409 conflit`
 * « la fiche a changé pendant la saisie » — alors que rien n'a changé. La cause est que
 * l'état lu au port rend un **entier**, `CitationQuality` étant la seule énumération du
 * projet adossée à `int`, et que la comparaison stricte oppose alors `"1"` à `1`.
 *
 * On ne rejoue pas ce défaut pour la création. Mais on ne le corrige pas non plus en
 * recopiant `0, 1, 2, 3` dans `Proposal` : la règle 3 de l'ADR 0002 interdit d'y nommer
 * `Tree\Enum\CitationQuality`, et une **copie** de ses valeurs serait pire qu'un `use` —
 * elle ne rougirait pas le jour où l'énumération change, elle se contenterait de refuser
 * une valeur devenue légitime, en français, avec assurance.
 *
 * `Tree` déclare donc son vocabulaire, `Proposal` l'applique, et la `422` tombe au dépôt,
 * sur le chemin du champ fautif, devant la personne qui vient de le saisir.
 *
 * Les deux listes traversent en **scalaires** et non en cas d'énumération, pour la raison
 * qui vaut partout au bord de ce port : ce sont les valeurs qui partent en base.
 */
final readonly class VocabulaireDAnnotation
{
    /**
     * @param list<string> $portees  les valeurs de `Tree\Enum\NoteScope` — ce qu'une
     *                               citation peut étayer (`generale`, `naissance`, …)
     * @param list<int>    $qualites les valeurs de `Tree\Enum\CitationQuality` — le `QUAY`
     *                               de la norme GEDCOM. En `int` et non en `string` :
     *                               c'est la seule énumération du projet adossée à `int`,
     *                               l'exception assumée du § 2.7 du modèle
     */
    public function __construct(
        public array $portees,
        public array $qualites,
    ) {
    }
}
