<?php

declare(strict_types=1);

namespace App\Module\Assistant\Repository;

use App\Module\Assistant\Entity\Conversation;
use App\Module\Assistant\Entity\Echange;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Conversation>
 */
class ConversationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Conversation::class);
    }

    /**
     * Les conversations d'un compte, la plus récemment poursuivie d'abord.
     *
     * Bornées : la liste est un menu, pas une archive. Au-delà, les plus anciennes restent
     * en base et s'ouvrent par leur lien, mais ne chargent pas chaque ouverture de l'écran.
     *
     * @return list<Conversation>
     */
    public function duCompte(int $userId, int $limite): array
    {
        /** @var list<Conversation> */
        return $this->createQueryBuilder('c')
            ->where('c.userId = :compte')
            ->setParameter('compte', $userId)
            ->orderBy('c.updatedAt', 'DESC')
            ->addOrderBy('c.id', 'DESC')
            ->setMaxResults($limite)
            ->getQuery()
            ->getResult();
    }

    /**
     * Une conversation **de ce compte**, ou `null` — celle d'un autre n'existe pas pour
     * lui (`404`, jamais `403` : on ne confirme pas qu'elle existe).
     */
    public function duCompteParId(int $userId, int $id): ?Conversation
    {
        return $this->findOneBy(['id' => $id, 'userId' => $userId]);
    }

    /**
     * Le nombre d'échanges de chaque conversation, en une requête.
     *
     * @param list<int> $ids
     *
     * @return array<int, int>
     */
    public function nombresDEchanges(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        /** @var list<array{id: int|string, nombre: int|string}> $lignes */
        $lignes = $this->getEntityManager()->createQuery(
            'SELECT IDENTITY(e.conversation) AS id, COUNT(e.id) AS nombre FROM '.Echange::class.' e
             WHERE e.conversation IN (:ids) GROUP BY e.conversation',
        )->setParameter('ids', $ids)->getArrayResult();

        $nombres = [];
        foreach ($lignes as $ligne) {
            $nombres[(int) $ligne['id']] = (int) $ligne['nombre'];
        }

        return $nombres;
    }
}
