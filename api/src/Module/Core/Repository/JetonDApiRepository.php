<?php

declare(strict_types=1);

namespace App\Module\Core\Repository;

use App\Module\Core\Entity\JetonDApi;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Dépôt de {@see JetonDApi}.
 *
 * Deux questions : « à qui est ce jeton » (le pare-feu, par empreinte) et « les jetons de
 * ce compte » (la commande `heritage:jeton-api:lister`).
 *
 * @extends ServiceEntityRepository<JetonDApi>
 */
class JetonDApiRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, JetonDApi::class);
    }

    public function parEmpreinte(string $empreinte): ?JetonDApi
    {
        return $this->findOneBy(['tokenHash' => $empreinte]);
    }

    /** @return list<JetonDApi> */
    public function duCompte(int $userId): array
    {
        /** @var list<JetonDApi> $lignes */
        $lignes = $this->createQueryBuilder('j')
            ->andWhere('IDENTITY(j.user) = :compte')
            ->setParameter('compte', $userId)
            ->orderBy('j.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $lignes;
    }
}
