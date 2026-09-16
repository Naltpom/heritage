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
}
