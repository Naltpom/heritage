<?php

declare(strict_types=1);

namespace App\Module\Proposal\Repository;

use App\Module\Core\Enum\SubjectKind;
use App\Module\Proposal\Entity\ChangeRequest;
use App\Module\Proposal\Entity\ChangeRequestOp;
use App\Module\Proposal\Enum\OpKind;
use App\Module\Proposal\Enum\RequestStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Dépôt de {@see ChangeRequestOp}.
 *
 * @extends ServiceEntityRepository<ChangeRequestOp>
 */
class ChangeRequestOpRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ChangeRequestOp::class);
    }

    /**
     * Les ops d'une demande, **dans l'ordre de `seq`**.
     *
     * L'ordre n'est pas décoratif : les ops se rejouent dans cet ordre, toujours. « Un
     * diff qui s'applique dans un ordre variable n'est pas reproductible, donc pas
     * auditable. »
     *
     * @return list<ChangeRequestOp>
     */
    public function pourDemande(ChangeRequest $demande): array
    {
        return $this->createQueryBuilder('o')
            ->andWhere('o.request = :demande')
            ->setParameter('demande', $demande)
            ->orderBy('o.seq', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * **Le calque « en attente », côté requête** : les ops de champ des demandes en
     * attente qui touchent une ligne donnée.
     *
     * Les deux index nommés au § 13.3 du contrat d'API s'enchaînent ici :
     * `idx_prp_request_cible` trouve les demandes en cours sur la personne,
     * `idx_prp_op_sujet` charge les ops. Le tri par `submittedAt` porte la règle de
     * l'entrée retenue quand deux demandes visent le même champ (§ 13.1) : **la plus
     * ancienne gagne**, les autres vont dans `autresDemandes`.
     *
     * `$auteurUserId` restreint aux demandes d'un auteur — c'est **toute** la
     * différence entre ce que voit un administrateur et ce que voit l'auteur, et c'est
     * pour cela que ce paramètre est obligatoire à l'appel plutôt que nullable par
     * défaut : un appelant qui l'oublierait servirait le calque complet à n'importe
     * qui.
     *
     * @param list<int> $ids
     *
     * @return list<ChangeRequestOp>
     */
    public function champsEnAttente(SubjectKind $kind, array $ids, ?int $auteurUserId): array
    {
        if ([] === $ids) {
            return [];
        }

        $qb = $this->createQueryBuilder('o')
            ->innerJoin('o.request', 'd')
            ->addSelect('d')
            ->andWhere('o.subjectKind = :kind')
            ->andWhere('o.subjectId IN (:ids)')
            ->andWhere('o.opKind = :champ')
            ->andWhere('d.status = :statut')
            ->setParameter('kind', $kind)
            ->setParameter('ids', $ids)
            ->setParameter('champ', OpKind::Champ)
            ->setParameter('statut', RequestStatus::EnAttente)
            ->addOrderBy('d.submittedAt', 'ASC')
            ->addOrderBy('d.id', 'ASC')
            ->addOrderBy('o.seq', 'ASC');

        if (null !== $auteurUserId) {
            $qb->andWhere('d.authorUserId = :auteur')->setParameter('auteur', $auteurUserId);
        }

        return $qb->getQuery()->getResult();
    }
}
