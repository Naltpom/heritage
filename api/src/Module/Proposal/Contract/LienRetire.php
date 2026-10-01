<?php

declare(strict_types=1);

namespace App\Module\Proposal\Contract;

/**
 * Ce que `Tree` a réellement fait en défaisant un lien — rendu par
 * {@see \App\Module\Proposal\Service\Port\PortArbre::retirerLien()}.
 *
 * `retrait` est la mesure prise **dans la transaction**, juste avant d'écrire : c'est elle
 * que l'historique garde, pas celle du dépôt. Les deux coïncident — un écart aurait rendu
 * la demande caduque au contrôle préalable —, mais le journal ne se fie pas à cette
 * coïncidence, pour la raison que donne déjà la suppression d'une fiche.
 *
 * `versionApres` vaut `null` quand la famille a été supprimée : il n'y a plus de ligne à
 * versionner. `filiation` est posée quand un enfant qui ne perdait qu'un parent a été
 * rattaché à l'autre — la famille d'arrivée a alors sa propre révision.
 */
final readonly class LienRetire
{
    public function __construct(
        public RetraitDeLien $retrait,
        public int $versionAvant,
        public ?int $versionApres,
        public ?FiliationEtablie $filiation = null,
    ) {
    }
}
