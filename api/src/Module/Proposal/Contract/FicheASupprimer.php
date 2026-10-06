<?php

declare(strict_types=1);

namespace App\Module\Proposal\Contract;

/**
 * La fiche qu'une suppression vise, et ce qui part avec elle dans l'arbre.
 *
 * `famillesEmportees` porte les unions dont elle est le seul époux (ou dont le couple
 * restant existe déjà) : `Tree` les efface avec elle (`unions[].supprimee` de
 * l'inventaire). Un module qui désigne une **famille** — une question sur une union — doit
 * donc les connaître aussi : la fiche n'est pas la seule ligne qui disparaît.
 */
final readonly class FicheASupprimer
{
    /** @param list<int> $famillesEmportees */
    public function __construct(
        public int $personneId,
        public array $famillesEmportees = [],
    ) {
    }
}
