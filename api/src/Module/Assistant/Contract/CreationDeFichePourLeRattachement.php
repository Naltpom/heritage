<?php

declare(strict_types=1);

namespace App\Module\Assistant\Contract;

/**
 * ═══════════════════════════════════════════════════════════════════════════════
 *  POINT DE BRANCHEMENT DU MODULE `Proposal` — créer la fiche d'un compte « à rattacher ».
 * ═══════════════════════════════════════════════════════════════════════════════.
 *
 * Une personne s'est inscrite par le lien d'invitation sans se trouver dans l'arbre : son
 * compte attend, **sans fiche** (`MatchKind::ARattacher`). Quand l'administrateur décide
 * qu'elle est absente de l'arbre, le geste « Créer la fiche et rattacher » doit produire
 * une vraie fiche — et, ADR 0004 oblige, **par une demande validée**, qui laisse des
 * révisions, jamais par une écriture directe dans `tree_person`.
 *
 * Ce port fait exactement cela, et rien d'autre : il dépose le corps `creation_personne`
 * (soumis) par `DepotDeDemande::deposer()` — le service de `POST /api/demandes`, avec ses
 * constructeurs et ses contrôles —, puis le valide aussitôt par
 * `ValidationDeDemande::valider()`, au nom du lecteur courant (l'administrateur). Une
 * auto-validation, donc, **marquée comme telle** (`auto_validated`), comme toutes les autres.
 *
 * ── Ce que ce port N'ouvre PAS : la transaction englobante ─────────────────────
 *
 * C'est l'appelant (`Assistant\Service\CreationEtRattachement`) qui l'ouvre, parce que le
 * geste ne s'arrête pas à la fiche : le compte doit ensuite être rattaché et activé, et
 * une fiche créée pour un compte que le rattachement refuse (`409
 * personne_deja_rattachee`) serait une fiche orpheline que personne n'a voulue. Les
 * transactions internes du dépôt et de la validation deviennent des points de sauvegarde
 * (DBAL 4) ; toute erreur annule tout, demande comprise.
 *
 * Déclaré ici, implémenté dans `Proposal` (`Service\CreationDeFicheAuRattachement`) :
 * règle 3 de l'ADR 0002.
 */
interface CreationDeFichePourLeRattachement
{
    /**
     * Dépose le corps `creation_personne` (soumis), puis le valide aussitôt au nom du lecteur courant
     * (administrateur), par `DepotDeDemande::deposer()` puis `ValidationDeDemande::valider()`.
     * Rend la fiche issue du brouillon `#p1`. N'ouvre pas de transaction englobante.
     * Les erreurs de Proposal (422 `donnees_invalides`, `demande_sans_lien`, 409…) remontent telles quelles.
     *
     * @param array<string, mixed> $corps
     */
    public function creerEtValider(array $corps, string $message): FicheCreee;
}
