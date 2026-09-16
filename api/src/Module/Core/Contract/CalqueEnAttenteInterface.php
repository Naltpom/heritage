<?php

declare(strict_types=1);

namespace App\Module\Core\Contract;

use App\Module\Core\Enum\SubjectKind;

/**
 * ═══════════════════════════════════════════════════════════════════════════════
 * LA SURFACE PUBLIQUE DE `Proposal` — le seul port qui va dans l'autre sens.
 *
 * Les autres interfaces de ce dossier vont de `Proposal` **vers** les autres modules :
 * {@see PortArbre}, {@see PortMedias}, {@see NotificateurDeDemandes}. Celle-ci est
 * l'inverse — c'est ce que `Proposal` **offre**, et `Tree` en est le premier appelant
 * (ADR 0002, règle 4 : « un module en appelle un autre par son service applicatif »).
 *
 * Elle existe pour une raison précise, et c'est la promesse centrale du produit : *le
 * demandeur voit sa modification **avant** validation*. Sans ce port, le sérialiseur de
 * l'arbre n'a aucun moyen de savoir qu'une fiche porte une proposition, ni que l'auteur
 * a proposé une personne qui n'existe pas encore — et la fonctionnalité que le
 * commanditaire a placée en priorité 2 n'existe pas à l'écran.
 *
 * ── La règle est ici, pas chez l'appelant ────────────────────────────────────────
 *
 * **Les deux méthodes prennent le lecteur et rendent ce que ce lecteur a le droit de
 * voir.** Rendre tout et laisser `Tree` trier mettrait « visible de l'auteur et des
 * administrateurs, invisible d'un autre membre » à deux endroits — et la seconde copie
 * serait fausse un jour, sur des données de personnes vivantes. Le filtrage est donc
 * dans l'implémentation, et il n'en existe qu'une.
 *
 * ── Ce que `Tree` fait de tout cela ─────────────────────────────────────────────
 *
 * `Tree` compose **son** filtre par-dessus : le cercle de visibilité et le calque sont
 * deux filtres distincts, composés dans le même normaliseur (ADR 0004), et ils ne se
 * remplacent pas. Conséquence à ne pas manquer : une personne vivante **hors cercle**
 * n'a pas de clé `enAttente` du tout — pas un calque vide, pas de clé (§ 11.1, § 13.3).
 * Ce port ne le sait pas et n'a pas à le savoir ; c'est `Tree` qui ne l'appelle pas pour
 * ces lignes-là.
 *
 * ── Aucune entité ne traverse ──────────────────────────────────────────────────
 *
 * Identifiants nus et objets de transfert en lecture seule, jamais une `ChangeRequest`
 * ni un `DraftPerson` (ADR 0002, règle 2). La surface publiée de ce module est donc
 * exactement : cette interface, {@see ProjectionEnAttente}, {@see NoeudPropose},
 * {@see AncragePropose}, {@see Lecteur} et l'énumération `SubjectKind`. Rien d'autre de
 * `Proposal` n'a à être importé ailleurs.
 */
interface CalqueEnAttenteInterface
{
    /**
     * Le **calque « en attente »** de lignes existantes — personnes, familles,
     * citations.
     *
     * La forme est celle du § 13.1 du contrat d'API, et la clé de chaque entrée est
     * **littéralement la clé du corps principal** de la fiche (`surname`, `birth`) :
     * le front pose sa pastille sans aucune logique de traduction (§ 1.2). La valeur
     * validée reste dans le corps ; le calque est **à côté, jamais à la place**.
     *
     * `actuel` vaut l'`old_value` de l'op, c'est-à-dire la valeur **au moment de la
     * soumission** : s'il diffère de la valeur courante de la fiche, c'est qu'une
     * caducité s'annonce. L'appelant peut l'afficher ; il ne doit pas supposer qu'ils
     * sont égaux.
     *
     * @param list<int> $ids les lignes à documenter, **après** application du cercle de
     *                       visibilité par l'appelant
     *
     * @return array<int, array<string, array{propose: mixed, actuel: mixed, demandeId: int, auteur: string, depuis: string|null, autresDemandes: list<int>}>>
     *                                                                                                                                                         indexé par identifiant de ligne ; une ligne sans proposition est **absente**
     */
    public function calque(SubjectKind $kind, array $ids, Lecteur $lecteur): array;

    /**
     * Les **personnes proposées mais non encore créées**, et leurs ancrages dans
     * l'arbre.
     *
     * Elles n'ont pas d'identifiant : elles vivent sous une référence provisoire jusqu'à
     * la validation, qui la résout. L'identifiant de nœud rendu ici est
     * **`#<demandeId>/<localRef>`** et non `local_ref` seul — celui-ci n'est unique que
     * dans sa demande, or un auteur peut en avoir plusieurs en attente et un
     * administrateur les a toutes ; deux nœuds `#p1` se recouvriraient dans la même
     * liste (§ 5.2, § 17.3 divergence 7).
     *
     * `Tree` **insère ces nœuds localement**, sans réorganiser le graphe : « décalage
     * des seuls voisins immédiats sur la même ligne, jamais par un recalcul global »
     * (ADR 0005). C'est le prix pour que le placement reste un objet partagé, mis en
     * cache une fois pour tout le monde.
     */
    public function projection(Lecteur $lecteur): ProjectionEnAttente;

    /**
     * Les identifiants des demandes en cours **visibles du lecteur** sur une ligne — la
     * clé `demandesEnAttente` d'une fiche (§ 5.3).
     *
     * Elle dit *qu'il y a* quelque chose en attente sans transporter *quoi* : une fiche
     * qui n'affiche pas le détail du calque a quand même besoin d'allumer sa pastille.
     *
     * @return list<int>
     */
    public function demandesSur(SubjectKind $kind, int $id, Lecteur $lecteur): array;

    /**
     * La **seconde composante de l'`ETag`** du graphe : une empreinte des demandes en
     * attente du lecteur, chaîne vide quand il n'en a aucune (ADR 0005).
     *
     * ── Pourquoi l'empreinte de l'arbre ne peut pas suffire ─────────────────────
     *
     * Déposer un brouillon ne touche **aucune** table `tree_*` : l'empreinte de l'arbre
     * ne bouge donc pas, le client revalide, reçoit `304`, et **ne voit jamais son
     * propre nœud en attente** — c'est-à-dire précisément la seule chose que ce calque
     * existe pour produire.
     *
     * Elle doit changer à **toute** évolution du calque du lecteur : dépôt, retrait,
     * validation, refus, caducité, et modification d'un brouillon. C'est pourquoi elle
     * est bâtie sur `updated_at`, que chaque décision touche, et non sur la seule liste
     * des identifiants — deux demandes dont l'une passe de `en_attente` à `refusee`
     * changeraient de contenu sans changer de liste.
     */
    public function empreinteDesDemandes(Lecteur $lecteur): string;
}
