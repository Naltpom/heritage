<?php

declare(strict_types=1);

namespace App\Module\Membership\Repository;

use App\Module\Membership\Entity\AutorisationDeNaissance;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AutorisationDeNaissance>
 */
class AutorisationDeNaissanceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AutorisationDeNaissance::class);
    }

    public function activePour(int $personId): ?AutorisationDeNaissance
    {
        return $this->findOneBy(['personId' => $personId, 'archivedAt' => null]);
    }

    /**
     * Toutes les autorisations d'une personne, la plus récente d'abord — l'historique que
     * l'administrateur relit.
     *
     * @return list<AutorisationDeNaissance>
     */
    public function historiquePour(int $personId): array
    {
        /** @var list<AutorisationDeNaissance> $liste */
        $liste = $this->findBy(['personId' => $personId], ['grantedAt' => 'DESC', 'id' => 'DESC']);

        return $liste;
    }
}
