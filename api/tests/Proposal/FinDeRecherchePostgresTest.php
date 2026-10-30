<?php

declare(strict_types=1);

namespace App\Tests\Proposal;

use App\Module\Core\Enum\MotifDeFinDeRecherche;
use App\Module\Core\ValueObject\FinDeRecherche;
use App\Tests\Tree\EmpreinteEntreMoteursTest;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Ce que SQLite ne peut pas montrer d'une fin de recherche dans une demande (ADR 0023) :
 * l'`old_value` d'un op est une colonne **`JSONB`**, et PostgreSQL y range les clés à sa
 * façon (les plus courtes d'abord), pas dans l'ordre d'écriture `reason, beforeYear, body`.
 * La modification et la levée se comparent à la valeur courante sur le tableau entier :
 * sans le détour par {@see FinDeRecherche::depuisJson()}, toute demande serait caduque
 * d'office sur la base réelle, et SQLite ne le verrait jamais.
 *
 * Il lit la base **migrée** et n'y laisse rien : chaque test écrit dans une transaction qu'il
 * annule. En local, sans PostgreSQL, il se saute ; en CI, `HERITAGE_EXIGE_BASE_REELLE=1`
 * transforme ce saut en échec.
 */
#[Group('donnees-reelles')]
final class FinDeRecherchePostgresTest extends TestCase
{
    private const string DEMANDE = <<<'SQL'
        INSERT INTO prp_change_request (kind, status, author_user_id, author_label, auto_validated, created_at, updated_at)
        VALUES ('modification_personne', 'brouillon', 999002, 'Essai fin de recherche', false, now(), now())
        RETURNING id
        SQL;

    private const string OP = <<<'SQL'
        INSERT INTO prp_change_op (request_id, seq, op_kind, subject_kind, subject_id, field, value_kind, old_value, new_value, base_version)
        VALUES (?, 1, 'champ', 'personne', 999003, 'finDeRechercheMere', 'fin_de_recherche', ?::jsonb, ?::jsonb, 7)
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

    /**
     * Une levée (`new_value` à `null`) ou une modification : l'`old_value` relue de `JSONB`
     * redonne, une fois repassée par son constructeur, la forme canonique du dépôt.
     */
    public function testLOldValueFaitLAllerRetourJsonb(): void
    {
        $avant = new FinDeRecherche(MotifDeFinDeRecherche::RegistresInexistants, 1668, 'Saint-Martin commence en 1668.');

        $this->connexion->beginTransaction();

        try {
            $demande = $this->connexion->fetchOne(self::DEMANDE);
            $this->connexion->executeStatement(self::OP, [$demande, json_encode($avant->versJson(), \JSON_THROW_ON_ERROR), null]);

            $lue = $this->connexion->fetchOne('SELECT old_value FROM prp_change_op WHERE request_id = ?', [$demande]);
            self::assertIsString($lue);
            $tableau = json_decode($lue, true, flags: \JSON_THROW_ON_ERROR);
            self::assertIsArray($tableau);

            // Les mêmes trois clés, rangées par PostgreSQL : l'ordre d'écriture n'est pas garanti.
            $cles = array_keys($tableau);
            sort($cles);
            self::assertSame(['beforeYear', 'body', 'reason'], $cles);

            // Le détour de la comparaison (`AnalyseDeDemande::normaliserStocke()`).
            $relue = FinDeRecherche::depuisJson($tableau);
            self::assertNotNull($relue);
            self::assertSame($avant->versJson(), $relue->versJson(), 'La levée se compare à la valeur courante après l’aller-retour.');
            self::assertEquals($avant, $relue);
        } finally {
            $this->connexion->rollBack();
        }
    }

    /** La levée écrit `null` : `JSONB` le garde comme un `NULL` SQL, pas comme la chaîne `null`. */
    public function testLaNouvelleValeurDUneLeveeResteNulle(): void
    {
        $avant = new FinDeRecherche(MotifDeFinDeRecherche::Lacune, null, null);

        $this->connexion->beginTransaction();

        try {
            $demande = $this->connexion->fetchOne(self::DEMANDE);
            $this->connexion->executeStatement(self::OP, [$demande, json_encode($avant->versJson(), \JSON_THROW_ON_ERROR), null]);

            self::assertNull($this->connexion->fetchOne('SELECT new_value FROM prp_change_op WHERE request_id = ?', [$demande]));
        } finally {
            $this->connexion->rollBack();
        }
    }
}
