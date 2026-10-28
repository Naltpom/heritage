<?php

declare(strict_types=1);

namespace App\Tests\Proposal;

use App\Tests\Tree\EmpreinteEntreMoteursTest;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Tools\DsnParser;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Ce que SQLite ne peut pas montrer de l'idempotence du dépôt (ADR 0022) : l'index est
 * **partiel** sur PostgreSQL, et le `CHECK` `ck_prp_request_idempotence` existe. Les tests
 * de `IndexDIdempotenceTest` couvrent le comportement commun aux deux moteurs.
 *
 * Il lit la base **migrée** (celle de la CI, ou celle de `compose.yaml`) et n'y laisse
 * rien : chaque test écrit dans une transaction qu'il annule. En local, sans PostgreSQL,
 * il se saute ; en CI, `HERITAGE_EXIGE_BASE_REELLE=1` transforme ce saut en échec.
 */
#[Group('donnees-reelles')]
final class IndexDIdempotencePostgresTest extends TestCase
{
    private const string CLE = 'cle-de-test-0123456789';
    private const string EMPREINTE = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

    private const string INSERTION = <<<'SQL'
        INSERT INTO prp_change_request (kind, status, author_user_id, author_label, auto_validated, created_at, updated_at, idempotency_key, idempotency_fingerprint)
        VALUES ('ajout_note', 'brouillon', ?, 'Essai B2', false, now(), now(), ?, ?)
        SQL;

    private Connection $connexion;

    /** Même interrupteur que `EmpreinteEntreMoteursTest` : la CI interdit de sauter. */
    private static function exigeLaBaseReelle(): bool
    {
        $valeur = $_SERVER[EmpreinteEntreMoteursTest::VARIABLE_EXIGENCE] ?? $_ENV[EmpreinteEntreMoteursTest::VARIABLE_EXIGENCE] ?? null;

        return \is_string($valeur) && '' !== $valeur && '0' !== $valeur;
    }

    /** Sauter, ou échouer si l'environnement a déclaré que la base est exigée. */
    private static function absente(string $motif): never
    {
        if (self::exigeLaBaseReelle()) {
            self::fail($motif.\sprintf(' — et %s interdit de sauter ici.', EmpreinteEntreMoteursTest::VARIABLE_EXIGENCE));
        }

        self::markTestSkipped($motif);
    }

    private static function dsn(): string
    {
        $configure = $_SERVER[EmpreinteEntreMoteursTest::VARIABLE_ENV] ?? $_ENV[EmpreinteEntreMoteursTest::VARIABLE_ENV] ?? null;

        return \is_string($configure) && '' !== $configure
            ? $configure
            : 'postgresql://heritage:heritage_dev@127.0.0.1:5432/heritage';
    }

    protected function setUp(): void
    {
        if (!\extension_loaded('pdo_pgsql')) {
            self::absente('pdo_pgsql non chargé — relancer avec `php -d extension=pdo_pgsql`.');
        }

        try {
            $this->connexion = DriverManager::getConnection((new DsnParser(['postgresql' => 'pdo_pgsql']))->parse(self::dsn()));
            $this->connexion->fetchOne('SELECT count(*) FROM prp_change_request');
        } catch (\Throwable $e) {
            self::absente(\sprintf('Base réelle injoignable (%s) : %s', self::dsn(), $e->getMessage()));
        }
    }

    public function testLIndexEstUniqueEtPartiel(): void
    {
        $definition = $this->connexion->fetchOne(
            'SELECT indexdef FROM pg_indexes WHERE schemaname = current_schema() AND indexname = ?',
            ['uniq_prp_request_idempotence'],
        );

        self::assertSame(
            'CREATE UNIQUE INDEX uniq_prp_request_idempotence ON public.prp_change_request USING btree (author_user_id, idempotency_key) WHERE (idempotency_key IS NOT NULL)',
            $definition,
        );
    }

    public function testLaBaseRefuseLaMemeCleDeuxFoisChezLeMemeAuteur(): void
    {
        $this->connexion->beginTransaction();

        try {
            // Sans clé, autant de demandes qu'on veut ; la même clé chez un autre compte passe.
            $this->connexion->executeStatement(self::INSERTION, [999001, null, null]);
            $this->connexion->executeStatement(self::INSERTION, [999001, null, null]);
            $this->connexion->executeStatement(self::INSERTION, [999001, self::CLE, self::EMPREINTE]);
            $this->connexion->executeStatement(self::INSERTION, [999002, self::CLE, self::EMPREINTE]);

            $this->expectException(UniqueConstraintViolationException::class);

            $this->connexion->executeStatement(self::INSERTION, [999001, self::CLE, self::EMPREINTE]);
        } finally {
            $this->connexion->rollBack();
        }
    }

    public function testLaCleNeVaPasSansSonEmpreinte(): void
    {
        $this->connexion->beginTransaction();

        try {
            try {
                $this->connexion->executeStatement(self::INSERTION, [999001, self::CLE, null]);
                self::fail('Une clé sans empreinte aurait dû être refusée par ck_prp_request_idempotence.');
            } catch (DriverException $e) {
                // 23514 : check_violation.
                self::assertSame('23514', $e->getSQLState());
            }
        } finally {
            $this->connexion->rollBack();
        }
    }
}
