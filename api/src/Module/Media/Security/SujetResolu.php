<?php

declare(strict_types=1);

namespace App\Module\Media\Security;

/**
 * Ce qu'un sujet de média devient une fois traduit en vocabulaire du noyau : un libellé,
 * et **la liste des personnes qu'il engage**.
 *
 * C'est exactement la traduction que `Core\Security\CercleDeVisibiliteInterface` demande
 * aux modules de faire chez eux : « ce média porte sur une union », « cette citation
 * étaye une fiche » n'a pas de sens pour le noyau, qui ne connaît que des identifiants de
 * personnes.
 *
 * `personIds` compte **plusieurs** entrées pour une union — les deux époux — et c'est la
 * raison d'être du pluriel : une preuve de mariage en dit autant sur l'un que sur
 * l'autre, donc elle se masque dès que l'un des deux est masqué. La règle est appliquée
 * par {@see VisibiliteParLeCercle}, pas ici : cet objet ne décide de rien, il décrit.
 */
final readonly class SujetResolu
{
    /** @param list<int> $personIds les personnes dont dépend la visibilité du sujet */
    public function __construct(
        public string $libelle,
        public array $personIds,
    ) {
    }
}
