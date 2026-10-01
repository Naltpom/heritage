<?php

declare(strict_types=1);

namespace App\Module\Core\Repository;

use App\Module\Core\Entity\PushSubscription;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Dépôt de {@see PushSubscription}.
 *
 * Les deux écritures faites **pendant un envoi** — « remis à telle heure », « cet
 * appareil n'existe plus » — passent par des requêtes DQL directes, hors unité de
 * travail. Deux raisons : elles ne doivent pas laisser une ligne d'audit par
 * notification (le journal deviendrait illisible là où il sert), et elles s'exécutent
 * dans `NotificationService::notifier()`, qui ne doit pas embarquer dans un `flush()`
 * des entités que l'appelant aurait laissées en suspens.
 *
 * @extends ServiceEntityRepository<PushSubscription>
 */
class PushSubscriptionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PushSubscription::class);
    }

    /** @return list<PushSubscription> */
    public function duCompte(int $userId): array
    {
        /** @var list<PushSubscription> $lignes */
        $lignes = $this->createQueryBuilder('a')
            ->andWhere('IDENTITY(a.user) = :compte')
            ->setParameter('compte', $userId)
            ->orderBy('a.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $lignes;
    }

    public function parEndpoint(string $endpoint): ?PushSubscription
    {
        return $this->findOneBy(['endpoint' => $endpoint]);
    }

    /** @param list<string> $endpoints */
    public function marquerUtilises(array $endpoints, \DateTimeImmutable $le): void
    {
        if ([] === $endpoints) {
            return;
        }

        $this->createQueryBuilder('a')
            ->update()
            ->set('a.lastUsedAt', ':le')
            ->andWhere('a.endpoint IN (:endpoints)')
            ->setParameter('le', $le, Types::DATETIMETZ_IMMUTABLE)
            ->setParameter('endpoints', $endpoints, ArrayParameterType::STRING)
            ->getQuery()
            ->execute();
    }

    /**
     * Le service push a répondu `404`/`410` : l'abonnement est mort pour de bon (appli
     * désinstallée, permission retirée, navigateur réinitialisé). Le garder ferait un
     * appel inutile à chaque notification, pour toujours.
     *
     * @param list<string> $endpoints
     */
    public function supprimerExpires(array $endpoints): int
    {
        if ([] === $endpoints) {
            return 0;
        }

        $supprimes = $this->createQueryBuilder('a')
            ->delete()
            ->andWhere('a.endpoint IN (:endpoints)')
            ->setParameter('endpoints', $endpoints, ArrayParameterType::STRING)
            ->getQuery()
            ->execute();

        return \is_int($supprimes) ? $supprimes : 0;
    }
}
