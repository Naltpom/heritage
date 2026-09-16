<?php

declare(strict_types=1);

namespace App\Module\Proposal\Service\Port;

/**
 * Une ligne que `Tree` vient d'insérer — ou qu'il a retrouvée.
 *
 * `creee` distingue les deux, et la distinction n'est pas décorative : **une famille
 * retrouvée ne produit pas de révision**. Écrire « famille 104 créée » alors qu'elle
 * existait depuis l'import mettrait dans l'historique d'une fiche un événement qui n'a
 * pas eu lieu, et l'historique d'une fiche est ce sur quoi on s'appuie six mois plus
 * tard pour comprendre d'où vient une donnée.
 *
 * C'est le cas courant du § 9.5, passe 4 : « désigner deux parents qui n'ont pas de
 * famille commune en crée une » — mais s'ils en ont une, on la réutilise, et
 * `uniq_tree_family_couple` est là pour que la question ne se pose pas deux fois.
 */
final readonly class LigneCreee
{
    public function __construct(
        public int $id,
        public string $libelle,
        public bool $creee = true,
    ) {
    }
}
