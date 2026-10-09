<?php

declare(strict_types=1);

namespace App\Module\Core\Contract;

use App\Module\Core\Enum\DepotSurFicheProposee;

/**
 * L'implémentation **par défaut** de {@see FichesProposeesInterface} : aucune personne
 * proposée n'accepte de pièce.
 *
 * Même arbitrage que `Core\Security\CercleFerme` et {@see CalqueFerme} — et ici la panne
 * évitée n'est pas une hypothèse, c'est celle que le contrat a été écrit pour fermer. Un
 * repli ouvert (`Joignable` à tout) remettrait la faille en service le jour où un alias
 * sauterait : chacun pourrait de nouveau glisser une pièce dans la proposition d'un autre,
 * sans erreur, sans journal et sans écran pour le montrer. Le repli fermé rend `404` au
 * premier essai de dépôt sur une personne proposée : visible, gênant, corrigé dans
 * l'heure.
 *
 * **Une fonctionnalité qui disparaît, jamais une faille qui revient.**
 *
 * `Proposal` prend la main dans `config/services/proposal.yaml`, chargé après
 * `core.yaml` — voir l'en-tête de ce dernier.
 */
final class FichesProposeesFermees implements FichesProposeesInterface
{
    public function depot(int $demandeId, string $localRef, int $userId, bool $administrateur): DepotSurFicheProposee
    {
        return DepotSurFicheProposee::Introuvable;
    }
}
