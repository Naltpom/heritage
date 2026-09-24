<?php

declare(strict_types=1);

namespace App\Module\Assistant\Contract;

/**
 * ═══════════════════════════════════════════════════════════════════════════════
 *  POINT DE BRANCHEMENT DU MODULE `Tree` — les yeux de l'assistant sur l'arbre.
 * ═══════════════════════════════════════════════════════════════════════════════.
 *
 * L'assistant de proposition lit des documents (actes, photos, PDF) et un texte libre,
 * puis doit **reconnaître** dans l'arbre les personnes dont ils parlent : « Jean Dupont,
 * né à Brest vers 1890 » est-il la fiche n° 12 ou la n° 48 ? Pour cela, le modèle dispose
 * de deux outils — `chercher_personnes` et `lire_personne` — qui passent par ce port.
 *
 * Déclaré ici, implémenté dans `Tree` : même forme que `Question\Contract\PortArbrePourQuestions`
 * et `Almanach\Contract\PortEvenementsArbre`, pour la même raison — la règle 3 de l'ADR 0002
 * n'autorise un `use` inter-modules que vers `Core\…` ou `<Autre>\Contract\…`.
 *
 * ── La règle qui surprend : CE PORT NE FILTRE PAS PAR LE CERCLE ─────────────────
 *
 * C'est une **décision du commanditaire**, pas un oubli : le modèle voit tout l'arbre,
 * vivants hors cercle compris, parce qu'il ne peut pas reconnaître une personne qu'on lui
 * cache — et une personne non reconnue devient un **doublon** proposé, ce qui est le
 * défaut le plus coûteux de la file d'administration.
 *
 * Ce qui revient AU MEMBRE, en revanche, passe par son cercle, et c'est l'assistant qui
 * s'en charge (`Service\VueDuLecteur`) : chaque objet rendu ici porte `vivante`, et
 * l'appelant confronte ce drapeau à `Core\Security\CercleDeVisibiliteInterface`. Rendre
 * ce port « prudent » en filtrant à la source casserait la reconnaissance sans rien
 * protéger de plus — la réponse est déjà filtrée à la sortie.
 *
 * L'implémentation ne rend donc **aucun** drapeau de visibilité : elle ne sait pas qui
 * lit, et c'est voulu.
 */
interface ArbrePourLAssistant
{
    /**
     * Les personnes dont le nom correspond à la requête, **sur tout l'arbre**.
     *
     * Même moteur que `GET /api/arbre/recherche` (mots dans la clé de recherche, dans
     * n'importe quel ordre), sans le filtre du cercle.
     *
     * @return list<PersonneDeLArbre> au plus `$limite`, avec les noms de leurs parents et
     *                                conjoints — c'est ce qui départage deux homonymes
     */
    public function chercher(string $requete, int $limite): array;

    /** Une personne, sans sa parenté détaillée. `null` si elle n'existe pas. */
    public function personne(int $personneId): ?PersonneDeLArbre;

    /**
     * La fiche complète : champs éditables, `version`, parents, unions (avec enfants),
     * fratrie. `null` si elle n'existe pas.
     */
    public function fiche(int $personneId): ?FicheDeLArbre;
}
