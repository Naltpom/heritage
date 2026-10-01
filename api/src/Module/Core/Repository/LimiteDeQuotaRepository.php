<?php

declare(strict_types=1);

namespace App\Module\Core\Repository;

use App\Module\Core\Entity\LimiteDeQuota;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Dépôt de {@see LimiteDeQuota}. Une seule lecture de masse — toute la table, qui ne
 * compte que les exceptions posées à la main — et `Core\Service\QuotasDUsage` la range.
 *
 * @extends ServiceEntityRepository<LimiteDeQuota>
 */
class LimiteDeQuotaRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LimiteDeQuota::class);
    }
}
