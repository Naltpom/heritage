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
 * Ce que le schéma des tests ne pouvait pas montrer du retrait d'un brouillon (il est construit
 * depuis le mapping, qui ne porte pas les `CHECK`) : `ck_prp_request_soumission`
 * refusait qu'un brouillon jamais soumis passe à `annulee`, et le retrait rendait une `500`
 * en production (`Version20261028183417`). `RefusEtRepriseTest` couvre le service.
 *
 * Il lit la base **migrée** et n'y laisse rien : chaque test écrit dans une transaction qu'il
 * annule. En local, sans PostgreSQL, il se saute ; en CI, `HERITAGE_EXIGE_BASE_REELLE=1`
 * transforme ce saut en échec.
 */
#[Group('donnees-reelles')]
final class RetraitDUnBrouillonPostgresTest extends TestCase
{
    private const string INSERTION = <<<'SQL'
        INSERT INTO prp_change_request (kind, status, author_user_id, author_label, auto_validated, created_at, updated_at)
        VALUES ('ajout_note', ?, 999001, 'Essai retrait', false, now(), now())
        RETURNING id
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

    public function testUnBrouillonJamaisSoumisPasseAAnnulee(): void
    {
        $this->connexion->beginTransaction();

        try {
            $id = $this->connexion->fetchOne(self::INSERTION, ['brouillon']);
            $this->connexion->executeStatement("UPDATE prp_change_request SET status = 'annulee' WHERE id = ?", [$id]);

            self::assertSame(
                ['status' => 'annulee', 'submitted_at' => null],
                $this->connexion->fetchAssociative('SELECT status, submitted_at FROM prp_change_request WHERE id = ?', [$id]),
            );
        } finally {
            $this->connexion->rollBack();
        }
    }

    /** Ce que la contrainte protège toujours : la file et le calque lisent `submitted_at`. */
    public function testUneDemandeEnAttenteOuCaduqueADateDeSoumission(): void
    {
        foreach (['en_attente', 'caduque'] as $statut) {
            $this->connexion->beginTransaction();

            try {
                $this->connexion->fetchOne(self::INSERTION, [$statut]);
                self::fail(\sprintf('Une demande « %s » sans date de soumission aurait dû être refusée par ck_prp_request_soumission.', $statut));
            } catch (DriverException $e) {
                // 23514 : check_violation.
                self::assertSame('23514', $e->getSQLState(), $statut);
                self::assertStringContainsString('ck_prp_request_soumission', $e->getMessage(), $statut);
            } finally {
                $this->connexion->rollBack();
            }
        }
    }
}
