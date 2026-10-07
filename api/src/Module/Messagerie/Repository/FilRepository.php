<?php

declare(strict_types=1);

namespace App\Module\Messagerie\Repository;

use App\Module\Messagerie\Entity\Fil;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Fil>
 */
class FilRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Fil::class);
    }

    /**
     * Les fils d'un membre, le plus récemment écrit d'abord.
     *
     * @return list<Fil>
     */
    public function duMembre(int $userId): array
    {
        /** @var list<Fil> */
        return $this->createQueryBuilder('f')
            ->where('f.membreUserId = :membre')
            ->setParameter('membre', $userId)
            ->orderBy('f.updatedAt', 'DESC')
            ->addOrderBy('f.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Tous les fils, pour l'administration, le plus récemment écrit d'abord. L'application
     * compte quelques dizaines de comptes : pas de pagination.
     *
     * @return list<Fil>
     */
    public function tous(): array
    {
        /** @var list<Fil> */
        return $this->createQueryBuilder('f')
            ->orderBy('f.updatedAt', 'DESC')
            ->addOrderBy('f.id', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
