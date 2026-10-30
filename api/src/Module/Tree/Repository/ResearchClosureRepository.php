<?php

declare(strict_types=1);

namespace App\Module\Tree\Repository;

use App\Module\Core\Enum\CoteDeRecherche;
use App\Module\Tree\Entity\Person;
use App\Module\Tree\Entity\ResearchClosure;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Dépôt de {@see ResearchClosure} — requêtes DQL portables (SQLite des tests, PostgreSQL).
 *
 * @extends ServiceEntityRepository<ResearchClosure>
 */
class ResearchClosureRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ResearchClosure::class);
    }

    public function duCote(Person $personne, CoteDeRecherche $cote): ?ResearchClosure
    {
        return $this->findOneBy(['person' => $personne, 'side' => $cote]);
    }

    /** @return list<ResearchClosure> */
    public function dePersonne(Person $personne): array
    {
        /** @var list<ResearchClosure> $lignes */
        $lignes = $this->findBy(['person' => $personne], ['side' => 'ASC']);

        return $lignes;
    }

    /**
     * Les fins de recherche de plusieurs personnes, en **une** requête.
     *
     * @param list<int> $personneIds
     *
     * @return array<int, array{pere?: ResearchClosure, mere?: ResearchClosure}>
     */
    public function desPersonnes(array $personneIds): array
    {
        if ([] === $personneIds) {
            return [];
        }

        /** @var list<ResearchClosure> $lignes */
        $lignes = $this->createQueryBuilder('c')
            ->addSelect('p')
            ->join('c.person', 'p')
            ->where('p.id IN (:ids)')
            ->setParameter('ids', array_values(array_unique($personneIds)))
            ->getQuery()
            ->getResult();

        $parPersonne = [];
        foreach ($lignes as $ligne) {
            $id = (int) $ligne->getPerson()->getId();
            $parPersonne[$id][$ligne->getSide()->value] = $ligne;
        }

        return $parPersonne;
    }

    /**
     * Toutes les fins de recherche de l'arbre, en une requête — le graphe.
     *
     * @return array<int, array{pere?: ResearchClosure, mere?: ResearchClosure}>
     */
    public function toutes(): array
    {
        /** @var list<ResearchClosure> $lignes */
        $lignes = $this->createQueryBuilder('c')->addSelect('p')->join('c.person', 'p')->getQuery()->getResult();

        $parPersonne = [];
        foreach ($lignes as $ligne) {
            $parPersonne[(int) $ligne->getPerson()->getId()][$ligne->getSide()->value] = $ligne;
        }

        return $parPersonne;
    }
}
