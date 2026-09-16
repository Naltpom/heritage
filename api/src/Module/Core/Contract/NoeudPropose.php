<?php

declare(strict_types=1);

namespace App\Module\Core\Contract;

/**
 * Une personne **proposée**, telle que `Tree` doit la dessiner : un nœud en attente.
 *
 * ── Ce que cet objet porte, et ce qu'il ne porte pas ─────────────────────────────
 *
 * Il porte ce que `Proposal` sait : l'identité proposée, les dates, l'auteur. Il ne
 * porte **ni `x`, ni `y`, ni `w`, ni `h`, ni `generation`, ni `branch`** — ce sont des
 * valeurs du moteur de placement et des colonnes dérivées de `Tree`, et les calculer
 * ici serait recopier dans `Proposal` des règles qui appartiennent à `Tree` (ADR 0002,
 * règle 5). Il ne porte pas non plus `visibilite` : une personne proposée est toujours
 * `complete` pour son auteur et les administrateurs, et **invisible pour tout le monde
 * d'autre** — elle n'est dans aucun cercle, puisque le cercle porte sur des personnes
 * validées (§ 6.6).
 *
 * ── Deux identifiants, et pourquoi ils sont séparés ──────────────────────────────
 *
 * `noeudId` vaut `#12/p1` et sert **dans le graphe**. `brouillonRef` vaut `p1`, **sans
 * dièse**, et sert à construire l'URL de détail : `GET /api/demandes/12/brouillons/p1`.
 * Les deux sont rendus séparément pour que le front n'ait jamais à découper le premier —
 * le `#` est le séparateur de fragment d'une URL, tout ce qui le suit ne quitterait
 * jamais le navigateur, et la panne est silencieuse (§ 17.3, divergence 8).
 *
 * ── `displayName` est un repli, pas la vérité ────────────────────────────────────
 *
 * `tree_person.display_name` est une **colonne dérivée**, composée par un service de
 * `Tree` selon sa propre règle. Les trois parties du nom sont donc rendues telles
 * quelles, et `Tree` compose comme il le fait pour une personne validée. `displayName`
 * n'est là que pour l'appelant qui n'a pas besoin de cette finesse ; s'en servir dans le
 * graphe ferait apparaître un nœud proposé nommé autrement que ses voisins.
 */
final readonly class NoeudPropose
{
    /**
     * @param string $noeudId      `#<demandeId>/<localRef>` — unique dans la liste entière
     * @param string $brouillonRef la référence **sans dièse**, pour l'URL de détail
     * @param string $sex          valeur de `Core\Enum\Sex` : `masculin`, `feminin`, `inconnu`
     * @param string $ligneDates   « vers 1889 – 1972 » ; vide si aucune date n'est connue.
     *                             **Pour une personne vivante, le moteur lui substitue la
     *                             constante `vivant·e`** et ne lit jamais ce champ (§ 11.2)
     * @param string $auteurLabel  de qui vient la proposition — la pastille le dit
     */
    public function __construct(
        public string $noeudId,
        public int $demandeId,
        public string $brouillonRef,
        public string $displayName,
        public string $givenNames,
        public string $surname,
        public ?string $marriedName,
        public string $sex,
        public bool $deceased,
        public string $ligneDates,
        public string $auteurLabel,
    ) {
    }

    /**
     * « Cette personne proposée est-elle **déclarée décédée** ? » — et rien de plus.
     *
     * ── Ce n'est PAS la règle du § 9.2, et le commentaire d'avant le prétendait ───────
     *
     * Il annonçait « le pendant de `vivant` sur une fiche (§ 9.2) ». C'était faux, et
     * l'écart est mesurable : sur la pile de travail, **2 des 6 `prp_draft_person`**
     * portent `deceased = false` et une naissance bornée à 1902. La règle du § 9.2
     * ({@see \App\Module\Tree\Service\ReglePersonneVivante::estVivante()}) les tient pour
     * décédées — 2026 − 1902 = 124 ans, au-delà du seuil de 110 — là où `!deceased` les
     * tient pour vivantes. Un nœud bâti sur l'une d'elles afficherait donc `vivant·e` là
     * où sa jumelle validée afficherait ses dates.
     *
     * **Nuance mesurée, et elle compte** : aucune de ces deux lignes n'est *rendue*
     * aujourd'hui. `LectureDesPropositions` ne projette que les demandes `en_attente`, et
     * la base de travail n'en porte aucune — les six brouillons appartiennent à des
     * demandes `validee` ou `refusee`. L'écart est donc **latent dans la donnée**, pas
     * visible à l'écran : il attend la prochaine demande en attente portant une naissance
     * ancienne sans case « décédé ». Dire qu'il s'affiche serait plus vendeur et faux.
     *
     * **L'écart va toujours vers le masquage, jamais vers la fuite** : c'est un défaut
     * d'affichage, pas de confidentialité.
     *
     * ── Pourquoi la règle ne peut pas être appliquée ICI ─────────────────────────────
     *
     * Ce n'est pas une paresse, c'est une impossibilité structurelle : `estVivante()`
     * prend **trois** entrées dont aucune n'est atteignable depuis `Core\Contract` —
     *
     * | Entrée du § 9.2 | Pourquoi elle manque |
     * |---|---|
     * | le seuil `tree.seuil_vivant_annees` | c'est un **réglage d'administration**, lu par `Tree\Service\ReglagesDeLArbre`. `Core` ne peut référencer aucun module (ADR 0002, règle 3), et un objet valeur `readonly` n'interroge aucun service. |
     * | la **médiane par génération** | elle se calcule sur **toute la population validée**, et `generation` est justement l'une des valeurs que cet objet refuse de porter — voir l'en-tête : les calculer ici recopierait dans `Proposal` des règles qui appartiennent à `Tree`. |
     * | `force_privacy` | `prp_draft_person` n'a pas cette colonne. Un brouillon ne se caviarde pas : il n'est visible que de son auteur et des administrateurs. |
     *
     * Écrire ici une variante du § 9.2 avec les entrées qui manquent produirait une
     * **seconde définition** de la règle la plus sensible du produit — exactement ce que
     * {@see \App\Module\Core\Service\CleDeRecherche} existe pour empêcher sur le
     * repliement d'accents, et pour la même raison : le jour où les deux divergeraient,
     * une fiche changerait de régime de confidentialité sans qu'aucun test ne rougisse.
     *
     * ── Où la réparation doit se faire, si on la fait ────────────────────────────────
     *
     * Dans `Tree`, du côté qui possède le seuil : {@see \App\Module\Tree\Serializer\PropositionsProjetees}
     * traduit ce nœud en `PersonnePlacable` et c'est **là** que `vivant` devient
     * l'étiquette `vivant·e` (§ 11.2 du contrat). Le geste demande de porter le seuil et
     * l'année courante jusqu'à cette traduction, et d'ajouter à ce contrat une borne de
     * naissance — donc de toucher `Core\Contract`, surface partagée par sept modules.
     * Signalé, non fait : ce lot n'a pas la main sur cet arbitrage.
     */
    public function vivant(): bool
    {
        return !$this->deceased;
    }
}
