<?php

declare(strict_types=1);

namespace App\Module\Tree\Service;

use App\Module\Evenement\Contract\PortArbrePourEvenements;
use App\Module\Tree\Entity\Person;
use App\Module\Tree\Serializer\EtatVivant;
use Doctrine\ORM\EntityManagerInterface;

/**
 * L'adaptateur de `Tree` pour le module `Evenement` : des noms, et qui est encore en vie.
 *
 * Il ne filtre
 * **rien** — c'est `Evenement` qui passe la liste au cercle du lecteur avant d'appeler
 * (`ServiceDesEvenements::dansLeCercle()`) —, et l'état « vivante » passe par
 * {@see EtatVivant}, la règle unique du produit, sans la date qui l'établit.
 *
 * Un adaptateur par module consommateur plutôt qu'un seul qui implémenterait tous les
 * contrats : chacun répond à son module, et le jour où l'un demande une chose de plus,
 * l'autre n'en hérite pas.
 */
final readonly class VueDeLArbrePourEvenements implements PortArbrePourEvenements
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
