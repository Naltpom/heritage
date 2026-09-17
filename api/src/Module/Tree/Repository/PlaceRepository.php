<?php

declare(strict_types=1);

namespace App\Module\Tree\Repository;

use App\Module\Tree\Entity\Place;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Dépôt de {@see Place}.
 *
 * Déclaré vide, comme les sept autres dépôts de ce module : les requêtes métier
 * appartiennent aux services qui en ont besoin. Les deux lectures de cette table
 * — « toutes les lignes », pour l'adaptateur de la carte, et « celle-ci par sa clé
 * repliée », pour la commande de résolution — tiennent en un `findAll()` et un
 * `findOneBy()`, et ne méritent pas d'être enveloppées.
 *
 * @extends ServiceEntityRepository<Place>
 */
class PlaceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Place::class);
    }
}
