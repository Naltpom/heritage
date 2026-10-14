<?php

declare(strict_types=1);

namespace App\Module\Proposal\Service;

use App\Module\Core\Contract\CiblesDeReferenceInterface;
use App\Module\Core\Enum\CibleDeReference;
use App\Module\Core\Service\ReleveurDeReferences;

/**
 * `prp_change_request`, désignée par `Media` (une preuve jointe à une demande) et par
 * `Question` (la demande née d'une réponse).
 *
 * Une demande **n'est jamais supprimée** (ADR 0004 : elle est l'historique de la
 * discussion) : une référence pendante vers elle n'a donc aucune explication métier, et
 * `heritage:verifier-integrite` la compte comme une violation, sans tolérance.
 */
final readonly class DemandesCommeCibles implements CiblesDeReferenceInterface
{
    public function __construct(private ReleveurDeReferences $releveur)
    {
    }

    public function cibles(): array
    {
        return [CibleDeReference::Demande];
    }

    public function existants(CibleDeReference $cible, array $identifiants): array
    {
        return $this->releveur->existants($cible->value, $identifiants);
    }
}
