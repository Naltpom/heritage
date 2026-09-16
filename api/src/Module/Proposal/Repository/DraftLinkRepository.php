<?php

declare(strict_types=1);

namespace App\Module\Proposal\Repository;

use App\Module\Proposal\Entity\ChangeRequest;
use App\Module\Proposal\Entity\DraftLink;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Dépôt de {@see DraftLink}.
 *
 * @extends ServiceEntityRepository<DraftLink>
 */
class DraftLinkRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DraftLink::class);
    }

    /**
     * Les liens d'une demande, dans l'ordre de `seq`.
     *
     * @return list<DraftLink>
     */
    public function pourDemande(ChangeRequest $demande): array
    {
        return $this->createQueryBuilder('l')
            ->andWhere('l.request = :demande')
            ->setParameter('demande', $demande)
            ->orderBy('l.seq', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Combien de liens porte une demande — le contrôle du § 6.3 : « au moins un lien,
     * sinon la personne flotterait hors de l'arbre », vérifié **à la soumission** et
     * non à la création, parce qu'on peut enregistrer un brouillon incomplet.
     */
    public function compterPourDemande(ChangeRequest $demande): int
    {
        return (int) $this->createQueryBuilder('l')
            ->select('COUNT(l.id)')
            ->andWhere('l.request = :demande')
            ->setParameter('demande', $demande)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
