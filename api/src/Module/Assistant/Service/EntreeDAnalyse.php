<?php

declare(strict_types=1);

namespace App\Module\Assistant\Service;

/**
 * Ce que le membre soumet à l'analyse, déjà validé par le contrôleur.
 */
final readonly class EntreeDAnalyse
{
    /**
     * @param list<DocumentJoint> $documents
     * @param int|null            $personneDeContexte la fiche d'où le membre vient (`personId`)
     * @param int|null            $personneDuMembre   la personne de l'arbre à laquelle son compte est
     *                                                rattaché — c'est elle que « ma grand-mère » désigne
     */
    public function __construct(
        public string $texte,
        public array $documents,
        public ?int $personneDeContexte = null,
        public ?int $personneDuMembre = null,
    ) {
    }
}
