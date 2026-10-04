<?php

declare(strict_types=1);

namespace App\Module\Membership\Service;

use App\Module\Membership\Entity\Invitation;
use App\Module\Membership\Entity\Member;
use App\Module\Membership\Enum\InvitationStatus;
use App\Module\Membership\Service\Port\RattachementsAuxArbres;
use Doctrine\ORM\EntityManagerInterface;

/**
 * {@see RattachementsAuxArbres} lu dans `mbr_member` et `mbr_invitation` : deux requêtes
 * de projection, une fois par requête HTTP au plus ({@see CercleDeVisibilite} mémorise).
 */
final readonly class RattachementsParLesComptes implements RattachementsAuxArbres
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function personnes(): array
    {
        /** @var list<int|string> $membres */
        $membres = $this->em->createQueryBuilder()
            ->select('DISTINCT m.personId')
            ->from(Member::class, 'm')
            ->where('m.personId IS NOT NULL')
            ->getQuery()
            ->getSingleColumnResult();

        /** @var list<int|string> $invitations */
        $invitations = $this->em->createQueryBuilder()
            ->select('DISTINCT i.personId')
            ->from(Invitation::class, 'i')
            ->where('i.personId IS NOT NULL')
            ->andWhere('i.status = :active')
            ->setParameter('active', InvitationStatus::Active)
            ->getQuery()
            ->getSingleColumnResult();

        return array_values(array_unique(array_map(intval(...), [...$membres, ...$invitations])));
    }
}
