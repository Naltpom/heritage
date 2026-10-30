<?php

declare(strict_types=1);

namespace App\Tests\Proposal;

use App\Tests\Tree\EmpreinteEntreMoteursTest;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Tools\DsnParser;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Ce que le schéma des tests ne pouvait pas montrer d'un dépôt à l'aveugle (il est construit
 * depuis le mapping, qui ne porte pas les `CHECK`) : à l'aveugle, `old_value` est la valeur
 * courante lue par le serveur, et elle peut égaler `new_value` quand l'auteur a deviné juste.
 * `ck_prp_op_changement` refusait cet INSERT (SQLSTATE 23514, une `500`) : un oracle d'égalité.
 * Le correctif est la colonne `blind` (`Version20261030131846`) ; `DepotDeDemandeTest`
 * et `FinDeRechercheTest` couvrent le service.
 *
 * Il lit la base **migrée** et n'y laisse rien : chaque test écrit dans une transaction qu'il
 * annule. En local, sans PostgreSQL, il se saute ; en CI, `HERITAGE_EXIGE_BASE_REELLE=1`
 * transforme ce saut en échec.
 */
#[Group('donnees-reelles')]
final class OpALAveuglePostgresTest extends TestCase
{
    private const string DEMANDE = <<<'SQL'
        INSERT INTO prp_change_request (kind, status, author_user_id, author_label, auto_validated, created_at, updated_at)
        VALUES ('modification_personne', 'brouillon', 999004, 'Essai op à l’aveugle', false, now(), now())
        RETURNING id
        SQL;

    /** Un op de champ dont l'ancienne et la nouvelle valeur sont égales ; le dernier paramètre est `blind`. */
    private const string OP_EGAL = <<<'SQL'
        INSERT INTO prp_change_op (request_id, seq, op_kind, subject_kind, subject_id, field, value_kind, old_value, new_value, base_version, blind)
        VALUES (?, 1, 'champ', 'personne', 999005, 'nickname', 'texte', '"Mimi"'::jsonb, '"Mimi"'::jsonb, 7, ?::boolean)
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
            $this->connexion->fetchOne('SELECT count(*) FROM prp_change_op');
        } catch (\Throwable $e) {
            self::absente(\sprintf('Base réelle injoignable (%s) : %s', self::dsn(), $e->getMessage()));
        }
    }

    /** Un op à l'aveugle peut avoir `old_value` égale à `new_value` : c'est ce qui ferme l'oracle. */
    public function testUnOpALAveuglePeutAvoirDesValeursEgales(): void
    {
        $this->connexion->beginTransaction();

        try {
            $demande = $this->connexion->fetchOne(self::DEMANDE);
            $this->connexion->executeStatement(self::OP_EGAL, [$demande, 'true']);

            self::assertSame(
                1,
                (int) $this->connexion->fetchOne('SELECT count(*) FROM prp_change_op WHERE request_id = ? AND blind', [$demande]),
            );
        } finally {
            $this->connexion->rollBack();
        }
    }

    /** Hors de ce cas, la contrainte protège toujours : une modification qui ne modifie rien est refusée. */
    public function testUnOpOrdinaireDeValeursEgalesEstRefuse(): void
    {
        $this->connexion->beginTransaction();

        try {
            $demande = $this->connexion->fetchOne(self::DEMANDE);
            $this->connexion->executeStatement(self::OP_EGAL, [$demande, 'false']);
            self::fail('Un op ordinaire dont les deux valeurs sont égales aurait dû être refusé par ck_prp_op_changement.');
        } catch (DriverException $e) {
            // 23514 : check_violation.
            self::assertSame('23514', $e->getSQLState());
            self::assertStringContainsString('ck_prp_op_changement', $e->getMessage());
        } finally {
            $this->connexion->rollBack();
        }
    }
}
