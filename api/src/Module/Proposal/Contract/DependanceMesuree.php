<?php

declare(strict_types=1);

namespace App\Module\Proposal\Contract;

/**
 * Une ligne de l'inventaire venue d'un autre module : « 2 démarches », « 3 questions ».
 *
 * Un **compte** et une nature, rien d'autre. Ni identifiant ni libellé : l'inventaire
 * part sur une route ouverte à tout membre, et il est comparé en entier à la validation
 * — un libellé renommé entre le dépôt et la validation rendrait caduque une demande que
 * rien ne contredit.
 */
final readonly class DependanceMesuree
{
    public function __construct(
        public DependanceDeFiche $nature,
        public int $nombre,
    ) {
    }
}
