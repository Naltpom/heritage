<?php

declare(strict_types=1);

namespace App\Module\Proposal\Service;

use App\Module\Proposal\Entity\ChangeRequestOp;

/**
 * Un op confronté à l'état courant de l'arbre.
 *
 * Trois faits, qui n'ont pas la même portée et qu'il ne faut surtout pas confondre :
 *
 * - **`versionABouge`** : la ligne a été touchée depuis le dépôt. Cela ne condamne
 *   rien — le verrou porte sur la ligne, pas sur le champ, et il part systématiquement
 *   en conflit même sur des champs disjoints.
 * - **`aBouge`** : la valeur **de ce champ** a changé depuis le dépôt. Cela condamne
 *   la demande.
 * - **`valeurCourante`** : ce que la base porte aujourd'hui, pour l'afficher à côté du
 *   diff, calculé à la lecture et jamais stocké (§ 6.7).
 *
 * > **La version qui bouge ne condamne pas la demande ; la valeur qui bouge, si.**
 */
final readonly class EtatDOp
{
    public function __construct(
        public ChangeRequestOp $op,
        public mixed $valeurCourante,
        public bool $aBouge,
        public bool $versionABouge,
        public string $libelleDiff,
    ) {
    }
}
