<?php

declare(strict_types=1);

namespace App\Module\Tree\Service;

/**
 * Les réglages d'administration dont l'arbre a besoin : deux pour son calcul, un pour ses
 * affiches.
 *
 * Interface plutôt qu'un appel direct à `Core\Service\SettingService`, pour la même
 * raison que `Core\Contract\SessionPayloadContributorInterface` existe : `Tree` déclare
 * ce dont il a besoin, `Core` le fournit, et le conteneur les branche. Le fournisseur de
 * placement reste ainsi **testable sans base de réglages**, ce qui compte parce que le
 * test de non-régression doit pouvoir tourner sur un jeu d'entités minimal.
 *
 * Aucun de ces réglages n'entre dans l'empreinte de l'arbre (§ 2.3) — aucun n'est une
 * donnée structurante :
 *
 * - changer le **seuil** ne déplace aucun nœud… sauf à travers la largeur des fiches, et
 *   c'est le seul point où il faut être attentif : une personne qui cesse d'être vivante
 *   voit sa ligne de dates passer de « vivant·e » à ses vraies dates, donc sa fiche
 *   change de largeur. Le placement en cache resterait alors périmé. Dans les faits, le
 *   passage de vivant à décédé se fait par une écriture sur `tree_person`
 *   (`deceased = true`), qui **change l'empreinte** — le cas courant est donc couvert.
 *   Reste le changement du seuil lui-même, rare et réservé à l'administration : il faut
 *   savoir qu'il demande un vidage du pool `cache.arbre`, et c'est écrit ici pour que
 *   personne ne le découvre devant un arbre aux fiches mal dimensionnées ;
 * - changer la **racine** recalcule `branch` et `generation`, donc écrit sur
 *   `tree_person`, donc change l'empreinte d'elle-même.
 */
interface ReglagesDeLArbre
{
    /** `tree.seuil_vivant_annees`, 110 par défaut (§ 9.2). */
    public function seuilVivantAnnees(): int;

    /**
     * `tree.personne_racine_id` — l'ego, ramené à `x = 0` par le moteur.
     *
     * `null` quand il n'est pas réglé : le moteur n'a alors aucun point de départ pour sa
     * récursion d'ascendance et **tout l'arbre tombe dans le point fixe de la phase 2**.
     * Le graphe reste calculable, mais il ne ressemble plus à l'arbre attendu — ce n'est
     * pas un état de service acceptable, c'est un état de démarrage.
     */
    public function personneRacineId(): ?int;

    /**
     * `tree.signature_affiches` — le nom écrit au pied des affiches (« Arbre établi par … »),
     * `null` quand il n'est pas réglé. Un réglage, parce que le dépôt ne porte aucun nom réel
     * (ADR 0006).
     */
    public function signatureDesAffiches(): ?string;
}
