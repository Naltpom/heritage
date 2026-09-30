<?php

declare(strict_types=1);

namespace App\Module\Tree\Repository;

use App\Module\Tree\Entity\Citation;
use App\Module\Tree\Entity\Source;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Dépôt de {@see Source}.
 *
 * Sa lectrice est l'écran d'administration des sources
 * ({@see \App\Module\Tree\Service\ServiceDesSources}, ADR 0012) ; l'import GEDCOM passe,
 * lui, par l'`EntityManager`.
 *
 * @extends ServiceEntityRepository<Source>
 */
class SourceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Source::class);
    }

    /**
     * Toutes les sources, par titre.
     *
     * Le fonds est borné par nature — 28 lignes à l'import, une poignée ajoutée à la main
     * depuis — : la recherche se fait donc en PHP, avec le repliement d'accents du projet,
     * plutôt qu'en `LIKE`, qui ne trouverait pas « État civil » en tapant « etat ».
     *
     * @return list<Source>
     */
    public function toutesParTitre(): array
    {
        /** @var list<Source> $sources */
        $sources = $this->createQueryBuilder('s')
            ->orderBy('s.title', 'ASC')
            ->addOrderBy('s.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $sources;
    }

    /**
     * Le nombre de citations de chaque source citée, en une requête. Une source absente
     * du tableau n'est citée nulle part.
     *
     * @return array<int, int> `source_id => nombre`
     */
    public function nombresDeCitations(): array
    {
        /** @var list<array{sourceId: int|string, nombre: int|string}> $lignes */
        $lignes = $this->getEntityManager()->createQueryBuilder()
            ->select('IDENTITY(c.source) AS sourceId', 'COUNT(c.id) AS nombre')
            ->from(Citation::class, 'c')
            ->groupBy('c.source')
            ->getQuery()
            ->getArrayResult();

        $nombres = [];
        foreach ($lignes as $ligne) {
            $nombres[(int) $ligne['sourceId']] = (int) $ligne['nombre'];
        }

        return $nombres;
    }

    public function nombreDeCitations(Source $source): int
    {
        return (int) $this->getEntityManager()->createQueryBuilder()
            ->select('COUNT(c.id)')
            ->from(Citation::class, 'c')
            ->where('c.source = :source')
            ->setParameter('source', $source)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
