<?php

declare(strict_types=1);

namespace App\Module\Core\Repository;

use App\Module\Core\Entity\NoteAdministrateur;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<NoteAdministrateur>
 */
class NoteAdministrateurRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, NoteAdministrateur::class);
    }

    /**
     * Le carnet d'un compte : les notes épinglées d'abord, puis la plus récemment
     * modifiée.
     *
     * @return list<NoteAdministrateur>
     */
    public function duCompte(int $userId): array
    {
        /** @var list<NoteAdministrateur> */
        return $this->createQueryBuilder('n')
            ->andWhere('n.authorUserId = :auteur')
            ->setParameter('auteur', $userId)
            ->orderBy('n.pinned', 'DESC')
            ->addOrderBy('n.updatedAt', 'DESC')
            ->addOrderBy('n.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /** `null` pour une note absente **ou** d'un autre compte : les deux se lisent pareil. */
    public function duCompteParId(int $userId, int $id): ?NoteAdministrateur
    {
        return $this->findOneBy(['id' => $id, 'authorUserId' => $userId]);
    }
}
