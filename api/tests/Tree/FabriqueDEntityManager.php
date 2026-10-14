<?php

declare(strict_types=1);

namespace App\Tests\Tree;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\UnderscoreNamingStrategy;
use Doctrine\ORM\ORMSetup;

/**
 * Monte un `EntityManager` autonome sur les seules entités de `Tree`.
 *
 * Deux usages : une base SQLite jetable en mémoire ({@see BaseDArbreEnMemoire}) et une
 * base PostgreSQL réellement peuplée ({@see EmpreinteEntreMoteursTest}).
 * Les deux partagent exactement la même configuration Doctrine — c'est ce qui rend les
 * deux mesures comparables.
 *
 * **Sans noyau Symfony, volontairement.** Le conteneur de test pointe sur une base
 * SQLite partagée entre processus, source d'erreurs fantômes ; un test de placement doit
 * pouvoir accuser le code sans qu'on se demande si c'est l'infrastructure.
 *
 * Seuls `Tree\Entity` et `Core\ValueObject` sont déclarés — ce dernier pour l'embeddable
 * `UncertainDate`. Aucune entité de `Core` n'est nécessaire, et c'est une conséquence
 * observable de la frontière du § 0.4 : si `Tree` avait une relation Doctrine vers
 * `Core`, cette fabrique ne pourrait pas exister.
 */
final class FabriqueDEntityManager
{
    public static function enMemoire(): EntityManagerInterface
    {
        return self::pour(['driver' => 'pdo_sqlite', 'memory' => true]);
    }

    public static function versDsn(string $dsn): EntityManagerInterface
    {
        return self::pour((new DsnParser(['postgresql' => 'pdo_pgsql']))->parse($dsn));
    }

    /** @param array<string, mixed> $parametres */
    private static function pour(array $parametres): EntityManagerInterface
    {
        $racine = \dirname(__DIR__, 2).'/src/Module';

        $configuration = ORMSetup::createAttributeMetadataConfiguration(
            paths: [$racine.'/Tree/Entity', $racine.'/Core/ValueObject'],
            isDevMode: true,
        );

        // Les objets paresseux natifs de PHP 8.4, comme les active DoctrineBundle en
        // temps normal. `ORMSetup` ne le fait pas, et sans ça le premier chargement
        // d'une association lève « Symfony LazyGhost is not available ».
        $configuration->enableNativeLazyObjects(true);

        // La même stratégie de nommage que `config/packages/doctrine.yaml`. Sans elle,
        // `siblingOrder` resterait `siblingOrder` alors que les index des entités nomment
        // `sibling_order` : sur SQLite le schéma ne se crée même pas, et sur PostgreSQL
        // les requêtes viseraient des colonnes inexistantes. C'est le genre d'écart qui
        // ferait tester autre chose que ce que l'application exécute.
        $configuration->setNamingStrategy(new UnderscoreNamingStrategy(\CASE_LOWER));

        /* @var array<string, mixed> $parametres */
        return new EntityManager(DriverManager::getConnection($parametres), $configuration);
    }
}
