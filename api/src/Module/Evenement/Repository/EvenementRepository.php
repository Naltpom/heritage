<?php

declare(strict_types=1);

namespace App\Module\Evenement\Repository;

use App\Module\Evenement\Entity\Evenement;
use App\Module\Evenement\Entity\Invitation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Evenement>
 */
class EvenementRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Evenement::class);
    }

    /**
     * Tous les événements, le plus proche d'abord.
     *
     * On ne filtre pas en SQL ce qu'un compte a le droit de voir : la règle vit à un seul
     * endroit, {@see \App\Module\Evenement\Service\ServiceDesEvenements} — comme pour les
     * tirages. Une famille organise quelques événements par an, pas des milliers.
     *
     * @return list<Evenement>
     */
    public function tous(): array
    {
        /** @var list<Evenement> $evenements */
        $evenements = $this->createQueryBuilder('e')
            ->orderBy('e.debut', 'ASC')
            ->addOrderBy('e.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $evenements;
    }

    /**
     * L'invitation dont le lien personnel a cette empreinte — par l'index unique
     * `uniq_evt_invitation_lien`, jamais par une comparaison en PHP.
     */
    public function invitationParEmpreinte(string $empreinte): ?Invitation
    {
        /** @var Invitation|null $invitation */
        $invitation = $this->getEntityManager()->createQueryBuilder()
            ->select('i', 'e')
            ->from(Invitation::class, 'i')
            ->join('i.evenement', 'e')
            ->where('i.tokenHash = :empreinte')
            ->setParameter('empreinte', $empreinte)
            ->getQuery()
            ->getOneOrNullResult();

        return $invitation;
    }
}
