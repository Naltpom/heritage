<?php

declare(strict_types=1);

namespace App\Module\Statistiques\Contract;

/**
 * ═══════════════════════════════════════════════════════════════════════════════
 *  POINT DE BRANCHEMENT DU MODULE `Tree` — depuis quelle fiche le lecteur regarde.
 * ═══════════════════════════════════════════════════════════════════════════════.
 *
 * Les statistiques décrivent **l'arbre du lecteur** — la composante reliée qui contient sa
 * fiche —, et non tout ce que la base porte (décision du commanditaire, 05/10/2026 : « j'ai
 * des données de personnes non rattachées à mon arbre »). Il leur faut donc le point de
 * départ : la fiche du lecteur, celle du membre emprunté sous la bascule « voir comme ».
 *
 * Déclaré ici, implémenté dans `Tree` (`Tree\Service\PointDeVuePourStatistiques`), qui
 * sait déjà répondre à cette question pour la parenté et la mise en page du graphe : une
 * seule réponse, un seul endroit.
 */
interface PortPointDeVue
{
    /** La fiche du lecteur effectif, `null` sans rattachement (compte d'amorçage, attente). */
    public function ficheDuLecteur(): ?int;
}
