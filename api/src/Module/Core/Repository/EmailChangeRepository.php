<?php

declare(strict_types=1);

namespace App\Module\Core\Repository;

use App\Module\Core\Entity\EmailChange;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EmailChange>
 */
final class EmailChangeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registre)
    {
        parent::__construct($registre, EmailChange::class);
    }

    /** La demande derrière une empreinte, consommée ou non : c'est l'appelant qui tranche. */
    public function parEmpreinte(string $empreinte): ?EmailChange
    {
        return $this->findOneBy(['tokenHash' => $empreinte]);
    }

    /**
     * Les demandes non consommées d'un compte — celles qu'une nouvelle demande périme.
     *
     * @return list<EmailChange>
     */
    public function enCoursPour(int $userId): array
    {
        return $this->findBy(['userId' => $userId, 'usedAt' => null]);
    }
}
