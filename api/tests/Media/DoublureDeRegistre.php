<?php

declare(strict_types=1);

namespace App\Tests\Media;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\AbstractManagerRegistry;

/**
 * Le minimum de `ManagerRegistry` pour qu'un `ServiceEntityRepository` fonctionne hors
 * conteneur Symfony.
 *
 * `MediaRepository` étend `ServiceEntityRepository`, qui demande un registre pour
 * retrouver le gestionnaire d'une classe. Dans l'application, DoctrineBundle le fournit ;
 * dans ces tests, il n'y a pas de conteneur. Six lignes de registre coûtent moins cher
 * que de faire dépendre les tests de médias du démarrage du noyau entier — voir la note
 * de {@see CasDeTestMedia}.
 */
final class DoublureDeRegistre extends AbstractManagerRegistry
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
        parent::__construct('test', ['default' => 'default'], ['default' => 'default'], 'default', 'default', null);
    }

    protected function getService(string $name): object
    {
        return $this->em;
    }

    protected function resetService(string $name): void
    {
    }
}
