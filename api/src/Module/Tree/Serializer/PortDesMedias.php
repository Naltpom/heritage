<?php

declare(strict_types=1);

namespace App\Module\Tree\Serializer;

/**
 * ═══════════════════════════════════════════════════════════════════════════════
 *  POINT DE BRANCHEMENT DU MODULE `Media` — déclaré ici, implémenté là-bas.
 * ═══════════════════════════════════════════════════════════════════════════════.
 *
 * Une fiche porte un portrait, des preuves, et une vignette par média de citation
 * (§ 5.3 du contrat). Ces lignes vivent dans `med_media`, que `Tree` ne lit pas
 * (ADR 0002, règle 3) — d'où ce port, symétrique de
 * `Media\Security\VisibiliteDesSujetsInterface`, qui va dans l'autre sens.
 *
 * ── Ce que l'implémentation doit respecter ───────────────────────────────────────
 *
 * - **Aucun chemin de disque ne sort, jamais** (§ 16.1). Les URL rendues sont
 *   **relatives** et pointent sur les deux routes authentifiées du § 7 :
 *   `/api/medias/{id}/fichier` et `/api/medias/{id}/vignette`. Ni `storage_name`, ni
 *   `thumbnail_name`, ni `sha256`, ni `originalName`.
 * - **Seuls les médias `actif` sortent ici.** Un média `en_attente` appartient au calque
 *   de `Proposal`, pas à la fiche validée ; un média `refuse` ou `remplace` n'a rien à
 *   faire dans une lecture.
 * - Le module applique **sa propre** vérification de visibilité du sujet. `Tree` ne
 *   l'appelle de toute façon jamais pour une personne hors cercle — la clé `medias` fait
 *   partie de ce qui n'est pas envoyé (§ 11.1) —, mais la défense en profondeur veut que
 *   les deux contrôles existent.
 *
 * Tant qu'aucune implémentation n'est branchée, {@see MediasNonBranches} rend `null` et
 * des listes vides : une fiche sans portrait, jamais une fuite.
 */
interface PortDesMedias
{
    /**
     * Le portrait actif d'une personne — un seul à la fois, les précédents étant
     * conservés en `remplace` (§ 6.5 du dossier de mission).
     *
     * @return array{mediaId: int, url: string, vignetteUrl: string, caption: string|null}|null
     */
    public function portraitDe(int $personneId, LecteurDeLArbre $lecteur): ?array;

    /**
     * Tous les médias actifs rattachés à la personne, portrait compris — c'est la clé
     * `medias` du § 5.3, et le portrait y figure aussi, sous son `kind`.
     *
     * @return list<array{mediaId: int, kind: string, caption: string|null, url: string, vignetteUrl: string}>
     */
    public function mediasDePersonne(int $personneId, LecteurDeLArbre $lecteur): array;

    /**
     * Les médias des citations, indexés par `tree_citation.id` — une requête pour toute
     * la fiche, pas une par citation : une fiche bien sourcée en porte six.
     *
     * @param list<int> $citationIds
     *
     * @return array<int, list<array{mediaId: int, kind: string, vignetteUrl: string}>>
     */
    public function mediasDesCitations(array $citationIds, LecteurDeLArbre $lecteur): array;

    /**
     * Quelles personnes portent **au moins un média actif** — le filtre « Avec image » de
     * la barre d'outils du graphe.
     *
     * Une question en bloc plutôt que 274 : le graphe la pose pour tout l'arbre à chaque
     * affichage, et une boucle sur `portraitDe()` ferait 274 allers-retours pour un
     * booléen.
     *
     * Elle ne rend **aucune information sur les médias eux-mêmes** — ni identifiant, ni
     * légende, ni vignette —, seulement un booléen par personne. C'est ce qui permet de la
     * servir sur un nœud sans rouvrir la question de la confidentialité des médias : le
     * nœud qui la porte est déjà `visibilite: "complete"`, donc son sujet est visible.
     *
     * @param list<int> $personneIds
     *
     * @return array<int, true> indexé par identifiant ; une personne sans média est **absente**
     */
    public function personnesAvecMedia(array $personneIds, LecteurDeLArbre $lecteur): array;
}
