<?php

declare(strict_types=1);

namespace App\Module\Proposal\Contract;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * ═══════════════════════════════════════════════════════════════════════════════
 * POINT DE GREFFE : ce qu'un module accroche à une fiche que `Proposal` va supprimer.
 *
 * Même mécanisme que {@see ApplicateurDOperation} — une interface déclarée ici, implémentée
 * par chaque module concerné, rassemblée par l'étiquette. `Proposal` ne lit ni `dmr_*`, ni
 * `evt_*`, ni `trg_*`, ni `qst_*` (règle 3 de l'ADR 0002) ; il demande, et chaque module
 * répond pour ses propres tables. Un module qui ajouterait demain un `person_id` n'a qu'à
 * implémenter cette interface pour que la suppression en tienne compte — et
 * `heritage:verifier-integrite` le rappellera à qui l'oublie.
 *
 * Le sort de chaque nature — bloque ou nettoyée — est fixé par {@see DependanceDeFiche},
 * pas par l'implémenteur : la règle est celle de la suppression, et elle doit se lire en un
 * seul endroit.
 */
#[AutoconfigureTag('proposal.dependances_d_une_fiche')]
interface DependancesDUneFiche
{
    /**
     * Ce que le module accroche à la fiche — **lecture seule**, appelée au dépôt, à chaque
     * lecture d'administration et à l'aperçu.
     *
     * Une nature à zéro n'est pas rendue : l'inventaire n'affiche pas « 0 démarche ».
     *
     * @return list<DependanceMesuree>
     */
    public function mesurer(FicheASupprimer $fiche): array;

    /**
     * Efface ce qui doit l'être — les natures **nettoyées**, jamais une nature qui bloque —
     * dans la transaction de la validation, et le trace au journal d'audit.
     *
     * Appelée **après** la suppression de la fiche dans l'arbre, dans la même transaction :
     * une levée annule tout, fiche comprise. Rend ce qui a été effacé, que l'applicateur
     * inscrit dans la révision.
     *
     * @return list<DependanceMesuree>
     */
    public function detacher(FicheASupprimer $fiche): array;
}
