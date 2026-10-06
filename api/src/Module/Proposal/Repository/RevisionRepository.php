<?php

declare(strict_types=1);

namespace App\Module\Proposal\Repository;

use App\Module\Core\Enum\SubjectKind;
use App\Module\Proposal\Entity\ChangeRequest;
use App\Module\Proposal\Entity\Revision;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Dépôt de {@see Revision}.
 *
 * @extends ServiceEntityRepository<Revision>
 */
class RevisionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Revision::class);
    }

    /**
     * L'historique d'une fiche, du plus récent au plus ancien (§ 6.11).
     *
     * Index : `idx_prp_revision_sujet`.
     *
     * @return array{elements: list<Revision>, total: int}
     */
    public function historique(SubjectKind $kind, int $id, int $page, int $parPage): array
    {
        $requete = $this->createQueryBuilder('r')
            ->andWhere('r.subjectKind = :kind')
            ->andWhere('r.subjectId = :id')
            ->setParameter('kind', $kind)
            ->setParameter('id', $id)
            ->addOrderBy('r.appliedAt', 'DESC')
            ->addOrderBy('r.id', 'DESC')
            ->setFirstResult(($page - 1) * $parPage)
            ->setMaxResults($parPage)
            ->getQuery();

        $paginateur = new Paginator($requete, false);

        return [
            'elements' => array_values(iterator_to_array($paginateur)),
            'total' => \count($paginateur),
        ];
    }

    /**
     * Les **cibles des demandes** qui ont écrit l'historique de ce sujet — une personne ou
     * une famille chacune. C'est ce qui rattache à l'arbre une citation, une note, un fait
     * ou un média : la demande qui les a ajoutés portait sur une fiche (révision du
     * 2026-10-04 de l'ADR 0003, « un arbre par famille »).
     *
     * @return list<array{kind: SubjectKind, id: int}>
     */
    public function ciblesDesDemandes(SubjectKind $kind, int $id): array
    {
        /** @var list<array{targetKind: SubjectKind|null, targetId: int|null}> $lignes */
        $lignes = $this->createQueryBuilder('r')
            ->select('DISTINCT d.targetKind, d.targetId')
            ->join('r.request', 'd')
            ->andWhere('r.subjectKind = :kind')
            ->andWhere('r.subjectId = :id')
            ->setParameter('kind', $kind)
            ->setParameter('id', $id)
            ->getQuery()
            ->getArrayResult();

        $cibles = [];

        foreach ($lignes as $ligne) {
            if (null !== $ligne['targetKind'] && null !== $ligne['targetId']) {
                $cibles[] = ['kind' => $ligne['targetKind'], 'id' => (int) $ligne['targetId']];
            }
        }

        return $cibles;
    }

    /**
     * « Qu'est-ce que cette validation a réellement produit ? ».
     *
     * Une création en génère plusieurs — la personne, la famille, les filiations —, et
     * c'est pour cela que la question se pose (§ 6.5).
     *
     * Index : `idx_prp_revision_request`.
     *
     * @return list<Revision>
     */
    public function pourDemande(ChangeRequest $demande): array
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.request = :demande')
            ->setParameter('demande', $demande)
            ->addOrderBy('r.id', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
