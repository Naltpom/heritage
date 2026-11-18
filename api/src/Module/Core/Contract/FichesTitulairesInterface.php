<?php

declare(strict_types=1);

namespace App\Module\Core\Contract;

/**
 * ═══════════════════════════════════════════════════════════════════════════════
 * LES FICHES TENUES PAR LEUR TITULAIRE — implémenté par `Membership`, consommé par
 * `Tree` (le graphe, la fiche, le tableau, le relevé des statistiques).
 *
 * ## Ce que le commanditaire a demandé (18/11/2026)
 *
 * « Les fiches rattachées à une personne doivent être des fiches validées, étant donné
 * que la personne s'est connectée et est rattachée à sa fiche. » Une fiche dont le
 * titulaire a un compte **actif** compte donc « Vérifiée », au même titre qu'une fiche
 * appuyée sur un acte (`bestQuay ≥ 2`) : la personne elle-même a reconnu sa fiche, et un
 * rattachement actif est passé par l'invitation ou par la validation d'un administrateur.
 * Voir l'ADR 0024.
 *
 * ## Pourquoi un contrat
 *
 * Le rattachement vit dans `mbr_member`, que `Tree` n'a pas le droit de lire (ADR 0002,
 * règle 3) ; la colonne `mbr_member.person_id` n'a même pas de clé étrangère. Plutôt qu'une
 * colonne recopiée dans `tree_person`, qu'il faudrait tenir à jour à chaque activation,
 * suspension, re-rattachement ou suppression de compte — autant d'occasions de dériver —,
 * `Tree` pose la question à chaque lecture. Une requête sur une vingtaine de lignes.
 *
 * ## Ce qu'il rend, et ce qu'il ne filtre pas
 *
 * Les `tree_person.id` dont un compte **actif** est le titulaire, avec la date depuis
 * laquelle il l'est (l'activation, à défaut le rattachement) : les statistiques datent la
 * vérification. Un compte en attente ou suspendu n'en fait pas partie.
 *
 * Rien n'est filtré ici, comme pour `best_quay` : c'est le sérialiseur qui omet le drapeau
 * sur un nœud restreint, exactement là où il omet `bestQuay` (§ 16.2).
 *
 * ## Le repli
 *
 * {@see AucuneFicheTitulaire} ne connaît aucun titulaire : sans `Membership` branché, les
 * fiches retombent sur la seule règle des actes. Une fiche qui paraît moins tenue qu'elle
 * ne l'est, jamais l'inverse.
 */
interface FichesTitulairesInterface
{
    /**
     * @return array<int, \DateTimeImmutable> `tree_person.id` → depuis quand un compte
     *                                        actif en est le titulaire
     */
    public function fichesTitulaires(): array;
}
