<?php

declare(strict_types=1);

namespace App\Module\Demarche\Service;

use App\Module\Demarche\Entity\Demarche;
use App\Module\Demarche\Enum\StatutDeDemarche;
use App\Module\Statistiques\Contract\PortActesDemandes;
use Doctrine\ORM\EntityManagerInterface;

/**
 * L'implémentation, côté `Demarche`, de {@see PortActesDemandes} : le nombre d'actes
 * demandés qui ne sont pas encore arrivés. « En attente » a une seule définition,
 * {@see StatutDeDemarche::enAttente()} — la liste des statuts est lue d'elle, pas recopiée.
 */
final class ActesDemandesPourStatistiques implements PortActesDemandes
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function enAttente(): int
    {
        $statuts = array_values(array_map(
            static fn (StatutDeDemarche $statut): string => $statut->value,
            array_filter(StatutDeDemarche::cases(), static fn (StatutDeDemarche $statut): bool => $statut->enAttente()),
        ));

        return (int) $this->em->createQueryBuilder()
            ->select('COUNT(d.id)')
            ->from(Demarche::class, 'd')
            ->where('d.statut IN (:statuts)')
            ->setParameter('statuts', $statuts)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
