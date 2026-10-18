<?php

declare(strict_types=1);

namespace App\Module\Noel\Repository;

use App\Module\Core\Entity\User;
use App\Module\Noel\Entity\Participation;
use App\Module\Noel\Entity\TirageNoel;
use App\Module\Noel\Enum\StatutDuTirage;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TirageNoel>
 */
class TirageNoelRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TirageNoel::class);
    }

    public function parCode(string $code): ?TirageNoel
    {
        return $this->findOneBy(['code' => $code]);
    }

    /**
     * Les tirages qu'un compte a créés ou auxquels il a répondu, le plus proche d'abord.
     *
     * @return list<TirageNoel>
     */
    public function duCompte(User $compte): array
    {
        /** @var list<TirageNoel> $tirages */
        $tirages = $this->createQueryBuilder('t')
            ->leftJoin(Participation::class, 'p', 'WITH', 'p.tirage = t AND p.user = :compte')
            ->andWhere('t.createur = :compte OR p.id IS NOT NULL')
            ->setParameter('compte', $compte)
            ->orderBy('t.dateTirage', 'ASC')
            ->getQuery()
            ->getResult();

        return $tirages;
    }

    /**
     * Les tirages encore ouverts dont la date est passée : la commande d'échéance les tire.
     *
     * @return list<int>
     */
    public function echus(\DateTimeImmutable $maintenant): array
    {
        /** @var list<int> $ids */
        $ids = $this->createQueryBuilder('t')
            ->select('t.id')
            ->andWhere('t.statut = :ouvert')
            ->andWhere('t.dateTirage <= :maintenant')
            ->setParameter('ouvert', StatutDuTirage::Ouvert)
            ->setParameter('maintenant', $maintenant)
            ->getQuery()
            ->getSingleColumnResult();

        return array_map('intval', $ids);
    }
}
