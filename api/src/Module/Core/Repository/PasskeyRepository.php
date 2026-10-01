<?php

declare(strict_types=1);

namespace App\Module\Core\Repository;

use App\Module\Core\Entity\Passkey;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Dépôt de {@see Passkey}.
 *
 * Deux questions seulement : « les clés de ce compte » (Mon compte, et la liste
 * d'exclusion à l'enregistrement) et « à qui est cette clé » (la connexion).
 *
 * @extends ServiceEntityRepository<Passkey>
 */
class PasskeyRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Passkey::class);
    }

    /** @return list<Passkey> */
    public function duCompte(int $userId): array
    {
        /** @var list<Passkey> $lignes */
        $lignes = $this->createQueryBuilder('p')
            ->andWhere('IDENTITY(p.user) = :compte')
            ->setParameter('compte', $userId)
            ->orderBy('p.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $lignes;
    }

    /**
     * La clé `$id` **si elle appartient à ce compte**, sinon `null`. Le contrôleur en
     * fait un `404` indistinct : une clé d'autrui et une clé inexistante se ressemblent.
     */
    public function duCompteParId(int $userId, int $id): ?Passkey
    {
        $cle = $this->find($id);

        return null !== $cle && $cle->getUser()->getId() === $userId ? $cle : null;
    }

    public function parCredentialId(string $credentialId): ?Passkey
    {
        return $this->findOneBy(['credentialId' => $credentialId]);
    }
}
