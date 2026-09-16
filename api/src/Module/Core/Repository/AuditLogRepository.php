<?php

declare(strict_types=1);

namespace App\Module\Core\Repository;

use App\Module\Core\Entity\AuditLog;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Dépôt de {@see AuditLog}.
 *
 * Déclaré vide, et c'est voulu : les requêtes métier appartiennent aux agents qui
 * écriront les services de ce module. Ce fichier existe pour qu'ils aient un endroit
 * où les poser, et pour que l'entité nomme son dépôt dès maintenant.
 *
 * @extends ServiceEntityRepository<AuditLog>
 */
class AuditLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AuditLog::class);
    }
}
