<?php

declare(strict_types=1);

namespace App\Module\Membership\Repository;

use App\Module\Membership\Entity\ClaimAttempt;
use App\Module\Membership\Entity\Invitation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Dépôt de {@see ClaimAttempt}.
 *
 * **Cette table est le journal, pas le compteur** (§ 4.3 du modèle). Le comptage qui
 * décide du plafond reste au `RateLimiter`, atomique et cadencé ; ce qu'on lit ici sert à
 * répondre, trois semaines plus tard, à « qu'est-ce que ce jeton a essayé de chercher ».
 *
 * @extends ServiceEntityRepository<ClaimAttempt>
 */
class ClaimAttemptRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ClaimAttempt::class);
    }

    /**
     * Le décompte des tentatives de l'heure écoulée, servi par `idx_mbr_claim_invitation`.
     *
     * C'est la colonne `tentatives` de la liste d'administration (§ 3.3) : elle permet à
     * l'administrateur de **voir qu'un lien est en train d'être sondé**. Les critères
     * soumis, eux, ne sont jamais rendus — `query_hash` est une empreinte, pas un texte.
     */
    public function compterDepuis(Invitation $invitation, \DateTimeImmutable $depuis): int
    {
        return (int) $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->andWhere('t.invitation = :invitation')
            ->andWhere('t.occurredAt >= :depuis')
            ->setParameter('invitation', $invitation)
            ->setParameter('depuis', $depuis)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
