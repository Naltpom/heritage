<?php

declare(strict_types=1);

namespace App\Module\Gedcom\Dto;

/**
 * Le fichier GEDCOM une fois lu : ses enregistrements de niveau 0, dans l'ordre.
 *
 * L'ordre est porteur de sens et doit survivre à la lecture : c'est lui qui donne
 * `tree_child.sibling_order`, et c'est lui qui rend **déterministe** le calcul des
 * branches — la boucle d'héritage de `graphe.py` parcourt les individus dans l'ordre du
 * fichier, et un autre ordre donnerait une autre répartition.
 */
final class FichierGedcom
{
    /**
     * @param list<NoeudGedcom> $enregistrements
     */
    public function __construct(
        public readonly array $enregistrements,
        /** Le `CHAR` de l'en-tête : ce que le fichier **annonçait**, pas ce qu'on a lu. */
        public readonly ?string $charset,
        public readonly int $lignesPhysiques,
    ) {
    }

    /**
     * Les enregistrements d'un type donné, indexés par `xref`, **dans l'ordre du fichier**.
     *
     * @return array<string, NoeudGedcom>
     */
    public function parType(string $type): array
    {
        $trouves = [];

        foreach ($this->enregistrements as $enregistrement) {
            if ($enregistrement->tag === $type && null !== $enregistrement->xref) {
                $trouves[$enregistrement->xref] = $enregistrement;
            }
        }

        return $trouves;
    }

    /** L'en-tête, qui n'a pas d'`xref` et ne se trouve donc pas par {@see parType()}. */
    public function entete(): ?NoeudGedcom
    {
        foreach ($this->enregistrements as $enregistrement) {
            if ('HEAD' === $enregistrement->tag) {
                return $enregistrement;
            }
        }

        return null;
    }
}
