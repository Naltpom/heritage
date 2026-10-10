<?php

declare(strict_types=1);

namespace App\Module\Core\Contract;

use App\Module\Core\Enum\DepotSurFicheProposee;

/**
 * ═══════════════════════════════════════════════════════════════════════════════
 * PEUT-ON JOINDRE UNE PIÈCE À CETTE PERSONNE PROPOSÉE ? — implémenté par `Proposal`,
 * consommé par `Media`.
 *
 * ── La faille que ce contrat ferme ──────────────────────────────────────────────
 *
 * `POST /api/medias` accepte, à la place d'un `subjectId`, le couple `demandeId` +
 * `brouillonRef` : la pièce est alors jointe à une personne **pas encore créée**. Jusqu'au
 * 09/10/2026, `Media` vérifiait la **forme** de ce couple (`p1`, un entier) et rien
 * d'autre — ni que la demande existe, ni son statut, ni que le déposant en soit l'auteur.
 * Or à la validation, `ApplicateurDeCreation` rattache **tous** les médias du brouillon à
 * la personne créée (passe 6, `PortMedias::resoudreLesBrouillons()`). N'importe quel
 * membre pouvait donc glisser une pièce dans la proposition d'un autre — le numéro d'une
 * demande se devine, `p1` est la référence de presque toutes —, et elle arrivait sur la
 * fiche validée comme si l'auteur l'avait jointe.
 *
 * `Media` ne peut pas trancher seul : les demandes appartiennent à `Proposal`, et la règle
 * 3 de l'ADR 0002 lui interdit de les lire. D'où ce port, dans le noyau, comme
 * {@see CalqueEnAttenteInterface} : `Proposal` l'implémente
 * (`Proposal\Service\FichesProposeesPourLesModules`), `Media` le consulte.
 *
 * ── La règle — elle est dans l'implémentation, pas chez l'appelant ──────────────
 *
 * 1. la demande existe, et la référence existe **dans cette demande** — `#p1` de la
 *    demande 12 et `#p1` de la demande 13 n'ont aucun rapport ;
 * 2. **son auteur** y joint une pièce tant qu'elle est `brouillon` ou `en_attente` —
 *    c'est le parcours normal : on décrit la personne, on joint l'acte, avant ou après
 *    l'envoi ;
 * 3. **un administrateur** qui n'en est pas l'auteur y joint une pièce si elle est
 *    `en_attente` — il instruit la demande, et un brouillon non envoyé n'est visible que
 *    de son auteur (§ 5.7) ;
 * 4. sur une demande **tranchée de son auteur** : `Tranchee` ;
 * 5. **tout le reste** : `Introuvable`, sans distinction.
 *
 * ── Les deux formes de la référence ─────────────────────────────────────────────
 *
 * `$localRef` est accepté **avec ou sans dièse** — `#p1` comme `prp_draft_person.local_ref`,
 * `p1` comme la partie `brouillonRef` du téléversement et `med_media.subject_draft_ref`.
 * C'est au contrat d'absorber l'écart entre les deux conventions, une fois, plutôt qu'à
 * chaque appelant de se souvenir laquelle l'autre côté attend : une référence mal
 * préfixée rendrait `Introuvable` sur une fiche bien réelle, sans autre symptôme.
 *
 * ── Pourquoi un booléen et non le `Lecteur` du noyau ────────────────────────────
 *
 * `Media` ne reçoit pas de {@see Lecteur} : il construit son propre `LecteurMedia`, qui
 * porte l'identifiant du compte et son rôle, et rien d'autre. Ce sont les deux seuls faits
 * dont la règle ait besoin. La bascule « voir comme un membre » n'a pas à figurer ici :
 * `POST /api/medias` la refuse en `409 vue_comme_membre_active` avant d'arriver jusqu'à
 * ce contrat, comme toute écriture.
 *
 * ── Le repli est fermé ─────────────────────────────────────────────────────────
 *
 * {@see FichesProposeesFermees} répond `Introuvable` à tout. Mal câblé, le dépôt sur une
 * personne proposée échoue en `404` — une fonctionnalité qui disparaît, visible au premier
 * essai. Le repli inverse rouvrirait la faille sans qu'aucun écran ne le montre.
 */
interface FichesProposeesInterface
{
    /**
     * @param int    $demandeId      la demande qui porte la personne proposée
     * @param string $localRef       sa référence provisoire, `p1` ou `#p1`
     * @param int    $userId         le compte qui dépose (`Core\User.id`)
     * @param bool   $administrateur `ROLE_ADMIN`, hors bascule « voir comme un membre »
     */
    public function depot(int $demandeId, string $localRef, int $userId, bool $administrateur): DepotSurFicheProposee;

    /**
     * Ce compte est-il **l'auteur de la demande** qui porte cette personne proposée ?
     * — la question que `Media` pose pour **lire** une pièce jointe à une fiche proposée
     * (`CatalogueMedia::lisible()`), depuis le 10/10/2026.
     *
     * ── Pourquoi la lecture a besoin de cette question ──────────────────────────────
     *
     * Une pièce sans sujet réel ne se lisait que par **son déposant** et l'administration :
     * tant que seul l'auteur d'une demande y joignait des pièces, c'était la même personne.
     * Ce n'est plus vrai. L'administrateur joint une pièce à la proposition d'un membre
     * (règle 3 de {@see depot()}), et surtout la **fusion d'un complément** (ADR 0017)
     * verse dans la proposition d'origine les pièces qu'un autre membre avait jointes au
     * sien. Sans cette question, l'auteur de l'origine verrait dans sa propre proposition
     * des vignettes cassées — des pièces listées par le détail du nœud (§ 6.6), dont les
     * octets lui répondraient `404`.
     *
     * **Tous statuts confondus** : une demande refusée reste consultable par son auteur,
     * avec ce qu'elle portait (ADR 0004). L'administrateur n'a pas besoin de cette
     * question — il lit tout —, et le déposant non plus.
     *
     * Le repli fermé répond `false` : la lecture retombe sur la règle d'avant, déposant et
     * administration.
     */
    public function estAuteurDe(int $demandeId, int $userId): bool;
}
