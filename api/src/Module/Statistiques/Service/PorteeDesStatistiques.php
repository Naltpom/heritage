<?php

declare(strict_types=1);

namespace App\Module\Statistiques\Service;

/**
 * Ce que les chiffres couvrent (décision du commanditaire, 05/10/2026).
 *
 * - `Arbre` : **l'arbre du lecteur**, la composante reliée qui contient sa fiche — à défaut
 *   de fiche, celle de la racine. Les fiches sans lien connu, que le périmètre laisse
 *   pourtant lire, n'y entrent pas : elles ne sont pas de sa famille. Tout membre, et
 *   l'administration par défaut.
 * - `Tout` : tout le périmètre du lecteur — pour l'administrateur hors bascule, toute la
 *   base. Réservée à l'administration ; un membre qui la demanderait reçoit `Arbre`.
 */
enum PorteeDesStatistiques: string
{
    case Arbre = 'arbre';
    case Tout = 'tout';
}
