<?php

declare(strict_types=1);

namespace App\Module\Demarche\Repository;

use App\Module\Demarche\Entity\Demarche;
use App\Module\Demarche\Entity\PersonneConcernee;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Demarche>
 */
class DemarcheRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Demarche::class);
    }

    /**
     * Toutes les démarches, les plus récemment touchées d'abord. Le filtre de visibilité
     * se fait **après**, dans le service : il dépend du cercle du lecteur, que la base ne
     * connaît pas.
     *
     * @return list<Demarche>
     */
    public function toutes(): array
    {
        /** @var list<Demarche> */
        return $this->createQueryBuilder('d')
            ->orderBy('d.updatedAt', 'DESC')
            ->addOrderBy('d.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Celles qui concernent une personne donnée.
     *
     * @return list<Demarche>
     */
    public function concernant(int $personId): array
    {
        /** @var list<Demarche> */
        return $this->createQueryBuilder('d')
            ->where(\sprintf('d.id IN (SELECT IDENTITY(pc.demarche) FROM %s pc WHERE pc.personId = :personne)', PersonneConcernee::class))
            ->setParameter('personne', $personId)
            ->orderBy('d.updatedAt', 'DESC')
            ->addOrderBy('d.id', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
