<?php

declare(strict_types=1);

namespace App\Module\Tree\Service;

use App\Module\Demarche\Contract\PortArbrePourDemarches;
use App\Module\Tree\Entity\Person;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Le nom affiché des personnes qu'une démarche concerne.
 *
 * Aucune décision de visibilité ici : `Demarche` a déjà passé la liste au cercle avant
 * d'appeler (`ServiceDesDemarches::personnesAutorisees()`). Ce filtre est **nécessaire** :
 * depuis la révision du 2026-10-01 de l'ADR 0003, le nom d'une personne vivante hors
 * cercle ne fait plus partie de la liste blanche, et ce port rend le vrai nom.
 */
final readonly class VueDeLArbrePourDemarches implements PortArbrePourDemarches
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function libelles(array $personIds): array
    {
        if ([] === $personIds) {
            return [];
        }

        $lignes = $this->em->createQueryBuilder()
            ->select('p.id AS id', 'p.displayName AS nom')
            ->from(Person::class, 'p')
            ->where('p.id IN (:ids)')
            ->setParameter('ids', $personIds)
            ->getQuery()
            ->getArrayResult();

        $libelles = [];

        foreach ($lignes as $ligne) {
            $libelles[(int) $ligne['id']] = (string) $ligne['nom'];
        }

        return $libelles;
    }
}
