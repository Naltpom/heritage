<?php

declare(strict_types=1);

namespace App\Module\Question\Repository;

use App\Module\Question\Entity\QuestionDemande;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Dépôt de {@see QuestionDemande}.
 *
 * @extends ServiceEntityRepository<QuestionDemande>
 */
class QuestionDemandeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, QuestionDemande::class);
    }

    /**
     * « De quelle question vient cette demande ? » — pour donner son contexte à une
     * contribution dans la file d'administration.
     *
     * Rend `null` quand la demande n'est venue d'aucune question, ce qui est le cas de
     * toutes celles déposées par les écrans de proposition ordinaires. **Ce n'est pas
     * une anomalie** : la page « Questions » est un chemin d'entrée de plus vers le
     * circuit de l'ADR 0004, pas le seul.
     */
    public function pourDemande(int $demandeId): ?QuestionDemande
    {
        return $this->createQueryBuilder('l')
            ->andWhere('l.demandeId = :demande')
            ->setParameter('demande', $demandeId)
            ->orderBy('l.id', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
