<?php

declare(strict_types=1);

namespace App\Tests\Tree;

use App\Module\Tree\Entity\Child;
use App\Module\Tree\Entity\Family;
use App\Module\Tree\Entity\Person;
use App\Module\Tree\Service\TreeFingerprint;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * **La même donnée, deux moteurs, la même empreinte.**.
 *
 * C'est l'exigence qui a fait sortir le calcul de la base : la première version portait
 * le `sha256(convert_to(string_agg(p::text, …)))` du § 2.3, du PostgreSQL pur, et il
 * aurait fallu en écrire une seconde pour SQLite. **Deux implémentations qui doivent
 * produire le même haché finissent par diverger**, et la divergence ne se verrait qu'en
 * service — un cache qui ne s'invalide jamais, ou qui s'invalide toujours. C'est le même
 * raisonnement qui a fait écarter le compteur au profit de l'empreinte.
 *
 * Il n'y a donc qu'une implémentation, en PHP. Reste à prouver qu'elle est réellement
 * indifférente au moteur — et c'est tout sauf évident, parce que les pilotes ne rendent
 * pas les mêmes valeurs pour la même donnée :
 *
 * | Colonne | PostgreSQL | SQLite |
 * |---|---|---|
 * | `deceased` (booléen) | `t` / `f` | `1` / `0` |
 * | `created_at` (horodatage daté) | `2026-09-15 18:24:05+00` | `2026-09-15 18:24:05` |
 * | `birth_min` (date nue) | `1901-01-01` | `1901-01-01` |
 *
 * Le test lit les **274 personnes, 103 familles et 174 filiations réellement importées**
 * depuis PostgreSQL, les recopie dans une base SQLite en mémoire **en les écrivant à la
 * façon de SQLite** — booléens en `0`/`1`, horodatages sans décalage —, puis compare les
 * deux empreintes. C'est le scénario réel : la même donnée logique, stockée par chaque
 * moteur selon ses conventions.
 *
 * Écrire les valeurs verbatim n'aurait rien prouvé : SQLite les aurait rendues telles
 * quelles, et on aurait comparé PostgreSQL à lui-même.
 *
 * **PostgreSQL est lu, jamais écrit.** Les seules écritures vont dans le SQLite en
 * mémoire, qui disparaît avec le test.
 */
#[Group('donnees-reelles')]
final class EmpreinteEntreMoteursTest extends TestCase
{
    /** @var list<class-string> */
    private const array TABLES = [Person::class, Family::class, Child::class];

    private EntityManagerInterface $postgres;
    private EntityManagerInterface $sqlite;

    protected function setUp(): void
    {
        if (!\extension_loaded('pdo_pgsql')) {
            self::markTestSkipped('pdo_pgsql non chargé — relancer avec `php -d extension=pdo_pgsql`.');
        }

        try {
            $this->postgres = FabriqueDEntityManager::versDsn(self::dsn());
            $personnes = (int) $this->postgres->getConnection()->fetchOne('SELECT count(*) FROM tree_person');
        } catch (\Throwable $e) {
            self::markTestSkipped(\sprintf('Base réelle injoignable (%s) : %s', self::dsn(), $e->getMessage()));
        }

        if (0 === $personnes) {
            self::markTestSkipped('Base réelle vide : lancer `bin/console heritage:import-gedcom` d\'abord.');
        }

        $this->sqlite = FabriqueDEntityManager::enMemoire();
        (new SchemaTool($this->sqlite))->createSchema($this->sqlite->getMetadataFactory()->getAllMetadata());
    }

    private static function dsn(): string
    {
        $configure = $_SERVER[PlacementBaseImporteeTest::VARIABLE_ENV] ?? $_ENV[PlacementBaseImporteeTest::VARIABLE_ENV] ?? null;

        return \is_string($configure) && '' !== $configure
            ? $configure
            : 'postgresql://heritage:heritage_dev@127.0.0.1:5432/heritage';
    }

    public function testLesDeuxMoteursRendentLaMemeEmpreinte(): void
    {
        $this->recopieVersSqlite();

        $cotePostgres = new TreeFingerprint($this->postgres);
        $coteSqlite = new TreeFingerprint($this->sqlite);

        // On compare d'abord la projection : quand deux hachés diffèrent, ils ne disent
        // pas où. La projection, elle, montre la ligne et la colonne fautives.
        self::assertSame(
            $cotePostgres->projectionCanonique(),
            $coteSqlite->projectionCanonique(),
            'La forme canonique doit être identique sur les deux moteurs.',
        );

        self::assertSame($cotePostgres->empreinte(), $coteSqlite->empreinte());

        echo \sprintf("\n  Empreinte — PostgreSQL et SQLite : %s\n", $cotePostgres->empreinte());
    }

    /**
     * Le garde-fou du test lui-même : il faut que les valeurs recopiées soient
     * réellement stockées **à la façon de SQLite**, sinon on comparerait PostgreSQL à
     * lui-même et le test passerait quoi qu'il arrive.
     */
    public function testLesDeuxMoteursStockentBienDesRepresentationsDifferentes(): void
    {
        $this->recopieVersSqlite();

        $dePostgres = $this->postgres->getConnection()->fetchAssociative(
            'SELECT deceased, created_at FROM tree_person ORDER BY id ASC LIMIT 1',
        );
        $deSqlite = $this->sqlite->getConnection()->fetchAssociative(
            'SELECT deceased, created_at FROM tree_person ORDER BY id ASC LIMIT 1',
        );

        self::assertIsArray($dePostgres);
        self::assertIsArray($deSqlite);

        self::assertNotSame(
            (string) $dePostgres['created_at'],
            (string) $deSqlite['created_at'],
            "Les deux moteurs doivent stocker l'horodatage différemment, sinon ce test ne prouve rien.",
        );
        self::assertStringContainsString('+00', (string) $dePostgres['created_at'], 'PostgreSQL porte le décalage.');
        self::assertStringNotContainsString('+00', (string) $deSqlite['created_at'], 'SQLite ne le porte pas.');
    }

    /** Les trois tables structurantes, et le compte attendu de l'arbre. */
    public function testLaRecopiePorteLArbreEntier(): void
    {
        $this->recopieVersSqlite();

        $connexion = $this->sqlite->getConnection();

        self::assertSame(274, (int) $connexion->fetchOne('SELECT count(*) FROM tree_person'));
        self::assertSame(103, (int) $connexion->fetchOne('SELECT count(*) FROM tree_family'));
        self::assertSame(174, (int) $connexion->fetchOne('SELECT count(*) FROM tree_child'));
    }

    /**
     * Recopie les trois tables de PostgreSQL vers SQLite, en réécrivant chaque valeur
     * dans la représentation que SQLite emploierait.
     */
    private function recopieVersSqlite(): void
    {
        $source = $this->postgres->getConnection();
        $cible = $this->sqlite->getConnection();

        foreach (self::TABLES as $entite) {
            $meta = $this->postgres->getClassMetadata($entite);
            $table = $meta->getTableName();
            $types = $this->typesDe($entite);

            foreach ($source->fetchAllAssociative(\sprintf('SELECT * FROM "%s" ORDER BY id ASC', $table)) as $ligne) {
                $valeurs = [];

                foreach ($ligne as $colonne => $valeur) {
                    $valeurs[$colonne] = $this->versSqlite($valeur, $types[$colonne] ?? Types::STRING);
                }

                $cible->insert($table, $valeurs);
            }
        }
    }

    /**
     * La traduction PostgreSQL → SQLite, limitée aux trois types qui divergent.
     *
     * C'est ce que ferait Doctrine à l'écriture : `SQLitePlatform` déclare
     * `getDateTimeTzFormatString()` à `Y-m-d H:i:s`, **sans décalage** — l'information
     * est perdue, et c'est pourquoi l'empreinte lit ces horodatages comme de l'UTC.
     */
    private function versSqlite(mixed $valeur, string $type): mixed
    {
        if (null === $valeur) {
            return null;
        }

        return match ($type) {
            Types::BOOLEAN => \in_array(strtolower(trim((string) $valeur)), ['1', 't', 'true'], true) ? 1 : 0,
            Types::DATETIME_MUTABLE, Types::DATETIME_IMMUTABLE,
            Types::DATETIMETZ_MUTABLE, Types::DATETIMETZ_IMMUTABLE => (new \DateTimeImmutable((string) $valeur))
                ->setTimezone(new \DateTimeZone('UTC'))
                ->format('Y-m-d H:i:s'),
            default => $valeur,
        };
    }

    /**
     * @param class-string $entite
     *
     * @return array<string, string>
     */
    private function typesDe(string $entite): array
    {
        $meta = $this->postgres->getClassMetadata($entite);
        $types = [];

        foreach ($meta->getFieldNames() as $champ) {
            $types[$meta->getColumnName($champ)] = (string) $meta->getTypeOfField($champ);
        }

        return $types;
    }
}
