<?php

declare(strict_types=1);

namespace App\Module\Soutien\Repository;

use App\Module\Soutien\Entity\Releve;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Releve>
 */
class ReleveRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Releve::class);
    }

    public function duJour(string $pseudo, \DateTimeImmutable $jour): ?Releve
    {
        return $this->findOneBy(['pseudo' => $pseudo, 'jour' => $jour->setTime(0, 0)]);
    }

    /**
     * Tous les relevés d'un pseudo, du plus ancien au plus récent.
     *
     * Une ligne par jour au plus : dix ans de relevés font moins de quatre mille lignes,
     * et le bilan les parcourt une fois. Pas de fenêtre en SQL.
     *
     * @return list<Releve>
     */
    public function duPseudo(string $pseudo): array
    {
        /** @var list<Releve> $releves */
        $releves = $this->createQueryBuilder('r')
            ->where('r.pseudo = :pseudo')
            ->setParameter('pseudo', $pseudo)
            ->orderBy('r.jour', 'ASC')
            ->getQuery()
            ->getResult();

        return $releves;
    }
}
