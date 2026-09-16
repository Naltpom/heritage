<?php

declare(strict_types=1);

namespace App\Module\Tree\Repository;

use App\Module\Tree\Entity\Fact;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Dépôt de {@see Fact}.
 *
 * Déclaré vide, et c'est voulu : les requêtes métier appartiennent aux agents qui
 * écriront les services de ce module. Ce fichier existe pour qu'ils aient un endroit
 * où les poser, et pour que l'entité nomme son dépôt dès maintenant.
 *
 * @extends ServiceEntityRepository<Fact>
 */
class FactRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Fact::class);
    }
}
