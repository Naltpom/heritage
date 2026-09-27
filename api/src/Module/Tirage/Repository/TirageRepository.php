<?php

declare(strict_types=1);

namespace App\Module\Tirage\Repository;

use App\Module\Tirage\Entity\Tirage;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Tirage>
 */
class TirageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Tirage::class);
    }

    /**
     * Tous les tirages, le plus récent d'abord.
     *
     * On ne filtre pas en SQL ce qu'un compte a le droit de voir : la règle vit à un seul
     * endroit, {@see \App\Module\Tirage\Service\ServiceDesTirages}. Une famille compte
     * quelques tirages, pas des milliers.
     *
     * @return list<Tirage>
     */
    public function tous(): array
    {
        /** @var list<Tirage> $tirages */
        $tirages = $this->createQueryBuilder('t')
            ->orderBy('t.createdAt', 'DESC')
            ->addOrderBy('t.id', 'DESC')
            ->getQuery()
            ->getResult();

        return $tirages;
    }
}
