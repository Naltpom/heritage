<?php

declare(strict_types=1);

namespace App\Module\Statistiques\Contract;

/**
 * Les faits que les statistiques lisent. Déclarée ici plutôt que de transporter la valeur
 * de `Tree\Enum\FactType` en chaîne : l'ADR 0002 nomme ce geste (« un contrat ne
 * transporte pas un vocabulaire déguisé »). Seuls deux types servent — les métiers, et les
 * résidences pour les migrations ; le reste n'entre pas dans le relevé.
 */
enum NatureDuFait: string
{
    case Profession = 'profession';
    case Residence = 'residence';
}
