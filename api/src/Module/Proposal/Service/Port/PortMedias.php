<?php

declare(strict_types=1);

namespace App\Module\Proposal\Service\Port;

/**
 * ═══════════════════════════════════════════════════════════════════════════════
 * POINT DE BRANCHEMENT DU MODULE `Media` — une seule méthode.
 *
 * Le § 6.6 du contrat d'API veut que le détail d'un nœud en attente porte ses médias.
 * Or un média joint à une personne **proposée** n'a pas de sujet réel : il porte
 * `med_media.subject_request_id` + `subject_draft_ref` (§ 6.1 du modèle), colonnes que
 * seul `Media` peut lire.
 *
 * {@see MediasNonBranches} est le repli, et le seul des trois dont l'absence soit
 * **inoffensive** : un brouillon sans média est un cas courant, pas une panne.
 *
 * **L'alias de `config/services/proposal.yaml` doit rester en place même une fois le
 * port branché** : `Media` le **décore** plutôt que de l'aliaser, et un décorateur
 * reçoit en argument le service qu'il enveloppe. Le retirer ne libère pas le port, il
 * supprime ce que la décoration attend — « has a dependency on a non-existent
 * service », et le conteneur entier cesse de compiler. Constaté en le retirant.
 */
interface PortMedias
{
    /**
     * Les médias joints à une personne proposée, dans la forme exacte du § 6.6.
     *
     * @param string $localRef la référence provisoire **avec** son dièse (`#p1`)
     *
     * @return list<array{mediaId: int, kind: string, caption: string|null, url: string, vignetteUrl: string, status: string}>
     */
    public function mediasDeBrouillon(int $demandeId, string $localRef): array;

    /**
     * **La passe 6 du § 9.5** : une fois les personnes créées, les médias qui pointaient
     * sur `#<demande>/<ref>` passent sur l'identifiant réel et deviennent visibles.
     *
     * ── Pourquoi cette méthode existe ───────────────────────────────────────────────
     *
     * `Media` avait préparé sa moitié de la couture — `CircuitDeValidationMedia::resoudreLeBrouillon()`
     * — et **rien ne l'appelait** : 0 appelant en production, 2 en test, qui
     * l'invoquaient à la main sur le service. Ils prouvaient que la méthode *fonctionne*,
     * jamais qu'elle est *appelée*. Pendant ce temps `POST /api/medias` acceptait
     * `demandeId` + `brouillonRef` — une forme activement validée par
     * `TeleversementMedia::verifierLaCoherenceDuSujet()`, donc pas un vestige — et la
     * validation de la demande laissait la ligne pendante.
     *
     * Le résultat n'était pas une preuve **perdue** mais une preuve **orpheline** : les
     * octets sur le disque, la ligne intacte, `subject_id` à `NULL`, et plus aucun écran
     * pour y mener une fois le nœud en attente disparu du graphe. Le contributeur voit sa
     * création validée *sans sa preuve*.
     *
     * ── Pourquoi c'est un port et non un appel direct ───────────────────────────────
     *
     * Règle 5 de l'ADR 0002 : lectures transverses assumées, **écritures strictement dans
     * leur module**. `Proposal` ne doit pas écrire dans `med_media`. Il déclare donc ce
     * qu'il lui faut, et `Media` le décore — le même mécanisme, dans le même fichier, que
     * `mediasDeBrouillon()`. Aucune frontière nouvelle n'est franchie.
     *
     * @param int                                                  $demandeId la demande dont les brouillons viennent d'être créés
     * @param array<string, array{personId: int, libelle: string}> $creees    indexé par référence provisoire **avec** son dièse (`#p1`) — la
     *                                                                        carte de résolution de la passe 2, telle que l'applicateur la tient
     *
     * @return int le nombre de médias effectivement rattachés
     */
    public function resoudreLesBrouillons(int $demandeId, array $creees): int;

    /**
     * Combien de médias visent cette personne — **lecture seule**, appelée au dépôt d'une
     * demande de suppression et à chaque lecture d'administration.
     *
     * ## Pourquoi une suppression de fiche a besoin de cette question
     *
     * `med_media.subject_id` n'est **protégé par aucune clé étrangère** : la colonne porte
     * un identifiant de personne, de famille ou de citation selon `subject_kind`, et
     * PostgreSQL ne peut pas contraindre une référence polymorphe. Supprimer `tree_person`
     * ne fait donc rien côté médias : la ligne reste, les octets restent sur le disque, et
     * plus aucun écran n'y mène. C'est la « preuve orpheline » que ce module a déjà
     * rencontrée au moment des brouillons non résolus, et le remède est le même : poser la
     * question plutôt que supposer la réponse.
     *
     * Le compte **bloque** la suppression tant qu'il n'est pas nul ; il ne la modifie pas.
     * `Proposal` n'a aucun droit d'écriture sur `med_media` (règle 5 de l'ADR 0002), et
     * même s'il en avait un, effacer des octets en effet de bord d'une autre opération est
     * exactement ce que {@see \App\Module\Media\Service\SuppressionMedia} refuse de
     * laisser faire à trois autres chemins. L'opérateur déplace ou supprime la preuve
     * lui-même, depuis la galerie, et c'est un geste qu'il aura vu.
     *
     * **Tous les statuts sont comptés**, y compris `en_attente` et `refuse` : une preuve en
     * attente de validation est celle dont la perte serait la plus silencieuse, personne ne
     * l'ayant encore vue.
     *
     * @param string $subjectKind valeur de `Core\Enum\SubjectKind` — elle traverse en chaîne,
     *                            comme partout au bord de ce module : `MediaSubject` est une
     *                            énumération de `Media`, que `Proposal` ne peut pas nommer
     */
    public function compterLesMediasDe(string $subjectKind, int $subjectId): int;

    /**
     * **La fusion d'un complément** (ADR 0017) : les pièces jointes à la fiche du
     * complément passent sur la fiche d'origine, **toujours proposée** — elles suivront
     * ensuite la personne à la validation de l'origine, par
     * {@see resoudreLesBrouillons()}, comme celles que l'auteur de l'origine avait jointes.
     *
     * ── Pourquoi déplacer, et non laisser en place ──────────────────────────────────
     *
     * Le complément est clos par la fusion (`refusee`, `duplicate_of_id`). Ses pièces, si
     * elles restaient sur `#<complément>/p1`, ne seraient **jamais résolues** : la passe 6
     * ne tourne qu'à la validation de la demande qui porte la référence, et celle-ci ne
     * sera jamais validée. Ce serait la « preuve orpheline » de l'en-tête de ce port,
     * revenue par la fusion — l'acte photographié, le travail le plus coûteux d'un
     * complément, perdu au moment même où l'administration l'accepte.
     *
     * Le statut et l'auteur du téléversement ne bougent pas : une pièce `en_attente` le
     * reste jusqu'à la validation, et `uploaded_by_user_id` dit toujours qui l'a apportée.
     * Rien n'est écrit dans la base par cette méthode : les lignes sont modifiées dans
     * l'unité de travail, et la transaction de la fusion les écrit avec le reste — ou ne
     * les écrit pas.
     *
     * @param string $deRef   la référence du complément, **avec** son dièse (`#p1`)
     * @param string $versRef celle de la fiche d'origine, **avec** son dièse
     *
     * @return int le nombre de pièces déplacées
     */
    public function faireSuivreLesMedias(int $deDemande, string $deRef, int $versDemande, string $versRef): int;
}
