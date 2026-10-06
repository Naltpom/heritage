<?php

declare(strict_types=1);

namespace App\Module\Tree\Service;

use App\Module\Statistiques\Contract\PortPointDeVue;
use App\Module\Tree\Serializer\FabriqueDuLecteur;
use App\Module\Tree\Serializer\PersonneDuLecteur;

/**
 * L'implémentation, côté `Tree`, de {@see PortPointDeVue} : la fiche depuis laquelle le
 * lecteur regarde l'arbre, telle que la parenté et le graphe la lisent déjà
 * ({@see PersonneDuLecteur}, bascule « voir comme » comprise).
 */
final readonly class PointDeVuePourStatistiques implements PortPointDeVue
{
    public function __construct(
        private FabriqueDuLecteur $lecteurs,
        private PersonneDuLecteur $pointDeVue,
    ) {
    }

    public function ficheDuLecteur(): ?int
    {
        return $this->pointDeVue->pour($this->lecteurs->courant());
    }
}
