<?php

declare(strict_types=1);

namespace App\Module\Proposal\Repository;

use App\Module\Proposal\Entity\ChangeRequest;
use App\Module\Proposal\Entity\DraftPerson;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Dépôt de {@see DraftPerson}.
 *
 * @extends ServiceEntityRepository<DraftPerson>
 */
class DraftPersonRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DraftPerson::class);
    }

    /**
     * Les brouillons d'une demande, dans l'ordre de leur référence provisoire.
     *
     * @return list<DraftPerson>
     */
    public function pourDemande(ChangeRequest $demande): array
    {
        return $this->createQueryBuilder('b')
            ->andWhere('b.request = :demande')
            ->setParameter('demande', $demande)
            ->orderBy('b.localRef', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Un brouillon par sa référence provisoire, **dans sa demande**.
     *
     * L'unicité de `local_ref` est **par demande** (`uniq_prp_draft_person_ref`) : `#p1`
     * de la demande 12 et `#p1` de la demande 13 n'ont aucun rapport. Une recherche par
     * référence seule n'aurait donc pas de sens, et c'est pour cela que cette méthode
     * exige la demande.
     */
    public function parReference(ChangeRequest $demande, string $localRef): ?DraftPerson
    {
        return $this->findOneBy(['request' => $demande, 'localRef' => $localRef]);
    }
}
