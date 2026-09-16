<?php

declare(strict_types=1);

namespace App\Module\Proposal\Service\Port;

/**
 * Ce que `Tree` rend après avoir appliqué les champs d'une ligne.
 *
 * `versionAvant` et `versionApres` ne sont pas décoratives : elles partent dans
 * `prp_revision`, et c'est le couple qui permet de rejouer l'historique d'une fiche
 * sans ambiguïté — et surtout **d'y repérer un trou** (§ 5.5 du modèle). Un historique
 * dont les versions ne s'enchaînent pas signale une écriture qui a contourné le
 * mécanisme ; sans ces deux colonnes, il n'y aurait rien à enchaîner.
 *
 * `libelle` est rendu **après** écriture, parce qu'une modification de nom change le
 * `display_name` : la révision doit porter le libellé d'après, sinon l'historique
 * nomme la fiche par un nom qu'elle n'a plus.
 */
final readonly class EcritureAppliquee
{
    public function __construct(
        public int $versionAvant,
        public int $versionApres,
        public string $libelle,
    ) {
    }
}
