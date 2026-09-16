<?php

declare(strict_types=1);

namespace App\Module\Timeline\Repository;

use App\Module\Timeline\Entity\Preference;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Dépôt de {@see Preference}.
 *
 * Déclaré vide, et c'est voulu : les requêtes métier appartiennent aux agents qui
 * écriront les services de ce module. Ce fichier existe pour qu'ils aient un endroit
 * où les poser, et pour que l'entité nomme son dépôt dès maintenant.
 *
 * @extends ServiceEntityRepository<Preference>
 */
class PreferenceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Preference::class);
    }
}
