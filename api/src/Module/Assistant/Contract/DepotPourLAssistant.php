<?php

declare(strict_types=1);

namespace App\Module\Assistant\Contract;

/**
 * ═══════════════════════════════════════════════════════════════════════════════
 *  POINT DE BRANCHEMENT DU MODULE `Proposal` — **l'assistant n'écrit rien lui-même**.
 * ═══════════════════════════════════════════════════════════════════════════════.
 *
 * Ce que le modèle rend n'est qu'une **intention** : « la naissance de la fiche 12 est le
 * 3 mars 1921 ». Pour en faire une demande que `POST /api/demandes` accepterait, il faut
 * trois choses que seul `Proposal` sait faire :
 *
 * 1. **lire en base** `valueKind`, `oldValue` et `baseVersion` — les deux verrous de
 *    l'ADR 0004 se posent sur la fiche courante, jamais sur ce que le modèle croit savoir ;
 * 2. **valider** le corps avec exactement les constructeurs du dépôt, sans rien écrire —
 *    « un corps invalide est retiré avec un avertissement, jamais rendu cassé » ;
 * 3. **déposer**, plus tard, au clic du membre, par `DepotDeDemande` — le même service
 *    que `POST /api/demandes`, avec la même file, les mêmes contrôles, la même
 *    notification. *Si l'on se surprend à écrire ici une méthode qui modifie l'arbre, on
 *    s'est trompé de route.*
 *
 * ## L'auteur n'est pas un paramètre
 *
 * Même choix que `Question\Contract\DepotDeReponse` : l'adaptateur résout le lecteur
 * lui-même, depuis la session, par la fabrique de `Proposal`. Un auteur passé par chaque
 * appelant est un auteur qu'un appelant finira par construire de travers — et les trois
 * garde-fous (régime en attente, bascule « voir comme », instantané du libellé) viennent
 * avec, sans être réécrits ici.
 */
interface DepotPourLAssistant
{
    /**
     * Complète un corps de demande et le **vérifie sans rien écrire**.
     *
     * Le corps reçu est celui d'une demande `creation_personne`, `modification_personne`
     * ou `ajout_lien`, à ceci près que les ops d'une modification n'ont pas encore
     * `valueKind`, `oldValue` ni `baseVersion` : l'adaptateur les lit sur la fiche
     * courante. Un op qui ne change rien à la fiche est **retiré** — le modèle recopie
     * volontiers une valeur déjà juste, et une modification qui ne modifie rien serait
     * refusée par le dépôt.
     *
     * @param array<string, mixed> $corps
     *
     * @throws DemandeIrrecevable si le corps ne passerait pas le dépôt
     */
    public function preparer(array $corps): DemandeVerifiee;

    /**
     * Dépose un corps **déjà préparé**, soumis (`en_attente`), au nom du lecteur courant.
     *
     * Les erreurs du dépôt ne sont pas traduites : ce sont des erreurs de domaine de
     * `Proposal` (`409 conflit` si la fiche a changé depuis l'analyse, `403`, `422`…),
     * et le front les connaît déjà sous cette forme.
     *
     * @param array<string, mixed> $corps
     *
     * @return array<string, mixed> la demande créée, sous la forme de `POST /api/demandes`
     */
    public function deposer(array $corps): array;
}
