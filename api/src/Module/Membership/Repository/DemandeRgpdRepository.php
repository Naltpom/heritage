<?php

declare(strict_types=1);

namespace App\Module\Membership\Repository;

use App\Module\Membership\Entity\DemandeRgpd;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DemandeRgpd>
 */
class DemandeRgpdRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DemandeRgpd::class);
    }

    /** @return list<DemandeRgpd> les demandes d'un compte, la plus récente d'abord */
    public function duCompte(int $userId): array
    {
        /** @var list<DemandeRgpd> $liste */
        $liste = $this->findBy(['userId' => $userId], ['createdAt' => 'DESC', 'id' => 'DESC']);

        return $liste;
    }

    /**
     * La file d'administration : les demandes en attente d'abord (la plus ancienne en tête),
     * puis les traitées, les plus récentes d'abord.
     *
     * @return list<DemandeRgpd>
     */
    public function file(int $limite = 200): array
    {
        /** @var list<DemandeRgpd> $attente */
        $attente = $this->createQueryBuilder('d')
            ->andWhere('d.traiteeAt IS NULL')
            ->orderBy('d.createdAt', 'ASC')
            ->getQuery()
            ->getResult();

        /** @var list<DemandeRgpd> $traitees */
        $traitees = $this->createQueryBuilder('d')
            ->andWhere('d.traiteeAt IS NOT NULL')
            ->orderBy('d.traiteeAt', 'DESC')
            ->setMaxResults($limite)
            ->getQuery()
            ->getResult();

        return [...$attente, ...$traitees];
    }
}
