<?php

declare(strict_types=1);

namespace App\Module\Tree\Service;

use App\Module\Tirage\Contract\PortArbrePourTirages;
use App\Module\Tree\Entity\Family;
use App\Module\Tree\Entity\Person;
use App\Module\Tree\Serializer\EtatVivant;
use Doctrine\ORM\EntityManagerInterface;

/**
 * L'adaptateur de `Tree` pour le module `Tirage` (ADR 0009) : des noms, et des couples.
 *
 * Il ne lit que la liste blanche — le nom affiché et le lien d'union —, ce que l'arbre
 * sert déjà à tout compte connecté pour une personne vivante hors cercle (ADR 0003). Il
 * n'a donc rien à filtrer, et ne doit rien lire de plus.
 *
 * L'état « vivante ou non » s'y ajoute, **sans la date** qui l'établit : c'est la valeur
 * que la recherche rend déjà à côté de chaque nom. Il passe par {@see EtatVivant}, qui
 * applique la règle unique du produit — un tirage n'en invente pas une seconde.
 */
final readonly class VueDeLArbrePourTirages implements PortArbrePourTirages
{
    public function __construct(
        private EntityManagerInterface $em,
        private EtatVivant $vivants,
    ) {
    }

    public function libelles(array $personIds): array
    {
        if ([] === $personIds) {
            return [];
        }

        /** @var list<array{id: int, nom: string}> $lignes */
        $lignes = $this->em->createQueryBuilder()
            ->select('p.id AS id', 'p.displayName AS nom')
            ->from(Person::class, 'p')
            ->where('p.id IN (:ids)')
            ->setParameter('ids', $personIds)
            ->getQuery()
            ->getArrayResult();

        $libelles = [];

        foreach ($lignes as $ligne) {
            $libelles[(int) $ligne['id']] = $ligne['nom'];
        }

        return $libelles;
    }

    /**
     * Une union divorcée ne compte pas : deux ex-conjoints peuvent se tirer.
     */
    public function couples(array $personIds): array
    {
        if ([] === $personIds) {
            return [];
        }

        /** @var list<array{mari: int|string, epouse: int|string}> $lignes */
        $lignes = $this->em->createQueryBuilder()
            ->select('IDENTITY(f.husband) AS mari', 'IDENTITY(f.wife) AS epouse')
            ->from(Family::class, 'f')
            ->where('f.husband IN (:ids)')
            ->andWhere('f.wife IN (:ids)')
            ->andWhere('f.divorced = false')
            ->setParameter('ids', $personIds)
            ->getQuery()
            ->getArrayResult();

        return array_map(
            static fn (array $ligne): array => [(int) $ligne['mari'], (int) $ligne['epouse']],
            $lignes,
        );
    }

    public function nonVivantes(array $personIds): array
    {
        if ([] === $personIds) {
            return [];
        }

        /** @var list<Person> $personnes */
        $personnes = $this->em->getRepository(Person::class)->findBy(['id' => $personIds]);
        $nonVivantes = [];

        foreach ($personnes as $personne) {
            $id = $personne->getId();

            if (null !== $id && !$this->vivants->estVivante($personne)) {
                $nonVivantes[] = $id;
            }
        }

        return $nonVivantes;
    }
}
