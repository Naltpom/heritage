<?php

declare(strict_types=1);

namespace App\Tests\Question;

use App\Module\Question\Enum\EtatDeQuestion;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;

/**
 * Les trois contraintes `CHECK` de `qst_question` **mordent-elles vraiment** ?
 *
 * ═══════════════════════════════════════════════════════════════════════════════
 *  CE QUE CE FICHIER MESURE, ET CE QU'IL NE MESURE PAS — à lire avant d'en tirer
 *  une conclusion.
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * Il **mesure** la sémantique des trois expressions, telles qu'elles sont écrites dans
 * `migrations/Version20260923153000.php` : elles sont extraites du fichier de migration,
 * posées sur une table SQLite en mémoire, et confrontées à la table de vérité des états.
 * Un `=` affaibli en implication simple fait tomber ce test.
 *
 * Il **ne mesure pas** que les contraintes sont installées sur le serveur du
 * déploiement. Cette moitié-là est couverte ailleurs, et il faut le savoir pour ne pas
 * se croire couvert deux fois :
 *
 * - l'intégration continue joue `doctrine:migrations:migrate` sur un vrai PostgreSQL
 *   avant la suite de tests : une expression syntaxiquement invalide y tombe ;
 * - le schéma des tests, lui, est reconstruit depuis le **mapping Doctrine**
 *   (`tests/ReinitialisationDuSchema.php`), qui ne porte aucune contrainte `CHECK`.
 *   **Aucun test fonctionnel du projet ne les exerce**, ni pour ce module ni pour les
 *   autres — `ck_prp_op_champ` et `ck_tree_person_deces_coherent` sont dans le même cas.
 *
 * Pourquoi SQLite plutôt qu'un vrai PostgreSQL, comme le groupe `donnees-reelles` : ces
 * trois expressions n'emploient que de la comparaison et `IS NOT NULL`, que les deux
 * moteurs évaluent identiquement. Un test qui exige un service tourne rarement ; celui-ci
 * tourne partout, y compris sur un poste sans `pdo_pgsql` — et **un test qui se saute est
 * vert sans rien prouver**.
 */
final class ContraintesDuModeleTest extends TestCase
{
    private const string MIGRATION = __DIR__.'/../../migrations/Version20260923153000.php';

    /**
     * `relancer_apres` est renseigné **si et seulement si** l'état est `differee`.
     *
     * Le « seulement si » est la moitié qu'une implication simple laisserait passer, et
     * c'est la moitié muette : une question fermée qui garderait une date de relance
     * d'une vie antérieure ne se voit nulle part — sauf le jour où une requête de réveil
     * la ramasse et repose une question déjà répondue.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('casDeRelance')]
    public function testLaRelanceNExisteQuePourUneQuestionDifferee(EtatDeQuestion $etat, bool $avecRelance, bool $acceptee): void
    {
        $this->assertLigne(
            $etat,
            $avecRelance ? '2026-10-23 08:00:00+00' : null,
            $etat->estDefinitif() ? '2026-09-23 08:00:00+00' : null,
            0,
            $acceptee,
        );
    }

    /** @return iterable<string, array{EtatDeQuestion, bool, bool}> */
    public static function casDeRelance(): iterable
    {
        yield 'différée avec relance' => [EtatDeQuestion::Differee, true, true];
        yield 'différée sans relance' => [EtatDeQuestion::Differee, false, false];
        yield 'posée sans relance' => [EtatDeQuestion::Posee, false, true];
        yield 'posée AVEC relance' => [EtatDeQuestion::Posee, true, false];
        yield 'fermée AVEC relance' => [EtatDeQuestion::Inconnue, true, false];
        yield 'répondue AVEC relance' => [EtatDeQuestion::Repondue, true, false];
    }

    /**
     * `repondu_le` est renseigné **si et seulement si** l'état est `inconnue` ou
     * `repondue`.
     *
     * Le cas qui compte est `inconnue` **avec** horodatage : « je ne sais pas » est une
     * réponse, elle n'écrit rien dans l'arbre, et sans son horodatage rien ne
     * distinguerait « personne n'a jamais regardé » de « quelqu'un a regardé et ne
     * savait pas ».
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('casDeReponse')]
    public function testLHorodatageDeReponseSuitLesDeuxEtatsDeReponse(EtatDeQuestion $etat, bool $avecHorodatage, bool $acceptee): void
    {
        $this->assertLigne(
            $etat,
            EtatDeQuestion::Differee === $etat ? '2026-10-23 08:00:00+00' : null,
            $avecHorodatage ? '2026-09-23 08:00:00+00' : null,
            0,
            $acceptee,
        );
    }

    /** @return iterable<string, array{EtatDeQuestion, bool, bool}> */
    public static function casDeReponse(): iterable
    {
        yield '« je ne sais pas » horodaté' => [EtatDeQuestion::Inconnue, true, true];
        yield '« je ne sais pas » SANS horodatage' => [EtatDeQuestion::Inconnue, false, false];
        yield 'répondue horodatée' => [EtatDeQuestion::Repondue, true, true];
        yield 'répondue SANS horodatage' => [EtatDeQuestion::Repondue, false, false];
        yield 'posée sans horodatage' => [EtatDeQuestion::Posee, false, true];
        yield 'posée AVEC horodatage' => [EtatDeQuestion::Posee, true, false];
        yield 'différée AVEC horodatage' => [EtatDeQuestion::Differee, true, false];
    }

    /** Un compteur de reports négatif n'a pas de sens ; `ck_qst_question_reports` le refuse. */
    public function testLeCompteurDeReportsNeDescendPasSousZero(): void
    {
        $this->assertLigne(EtatDeQuestion::Posee, null, null, 0, true);
        $this->assertLigne(EtatDeQuestion::Posee, null, null, 3, true);
        $this->assertLigne(EtatDeQuestion::Posee, null, null, -1, false);
    }

    /**
     * **Le témoin.** Sans lui, une extraction qui rendrait zéro expression donnerait une
     * table sans contrainte, et tous les cas ci-dessus passeraient en acceptant tout —
     * un vert parfait qui ne mesure rien.
     *
     * On affirme donc le nombre d'expressions trouvées **et** leurs noms.
     */
    public function testLesTroisContraintesSontBienExtraitesDeLaMigration(): void
    {
        $contraintes = self::contraintesDeLaMigration();

        self::assertSame(
            ['ck_qst_question_differee', 'ck_qst_question_repondue', 'ck_qst_question_reports'],
            array_keys($contraintes),
        );

        foreach ($contraintes as $nom => $expression) {
            self::assertNotSame('', trim($expression), \sprintf('L\'expression de %s est vide.', $nom));
        }
    }

    /**
     * Pose les trois contraintes sur une table SQLite jetable et tente l'insertion.
     */
    private function assertLigne(
        EtatDeQuestion $etat,
        ?string $relancerApres,
        ?string $reponduLe,
        int $reports,
        bool $acceptee,
    ): void {
        $connexion = $this->tableSousContraintes();

        $inserer = static fn (): int => $connexion->executeStatement(
            'INSERT INTO qst_question (etat, relancer_apres, repondu_le, reports) VALUES (?, ?, ?, ?)',
            [$etat->value, $relancerApres, $reponduLe, $reports],
        );

        if ($acceptee) {
            self::assertSame(1, $inserer(), \sprintf(
                'La combinaison (etat=%s, relance=%s, repondu=%s, reports=%d) est légitime et a été refusée.',
                $etat->value,
                var_export($relancerApres, true),
                var_export($reponduLe, true),
                $reports,
            ));

            return;
        }

        try {
            $inserer();
            self::fail(\sprintf(
                'La combinaison (etat=%s, relance=%s, repondu=%s, reports=%d) doit être refusée par une contrainte CHECK.',
                $etat->value,
                var_export($relancerApres, true),
                var_export($reponduLe, true),
                $reports,
            ));
        } catch (\Doctrine\DBAL\Exception $e) {
            self::assertStringContainsStringIgnoringCase('constraint', $e->getMessage());
        }
    }

    private function tableSousContraintes(): Connection
    {
        $connexion = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        $clauses = '';

        foreach (self::contraintesDeLaMigration() as $nom => $expression) {
            $clauses .= \sprintf(",\n    CONSTRAINT %s CHECK (%s)", $nom, $expression);
        }

        $connexion->executeStatement(<<<SQL
            CREATE TABLE qst_question (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                etat VARCHAR(12) NOT NULL,
                relancer_apres TEXT DEFAULT NULL,
                repondu_le TEXT DEFAULT NULL,
                reports INTEGER NOT NULL DEFAULT 0{$clauses}
            )
            SQL);

        return $connexion;
    }

    /**
     * Les expressions `CHECK`, **lues dans le fichier de migration** et non recopiées
     * ici.
     *
     * Recopier les expressions dans le test les aurait comparées à elles-mêmes :
     * quelqu'un affaiblirait la contrainte en base, le test garderait sa copie forte, et
     * resterait vert en ne gardant plus rien.
     *
     * @return array<string, string> indexé par nom de contrainte, dans l'ordre du fichier
     */
    private static function contraintesDeLaMigration(): array
    {
        $source = file_get_contents(self::MIGRATION);
        self::assertIsString($source, 'Migration introuvable : '.self::MIGRATION);

        // `CONSTRAINT <nom> ... CHECK (<expression>)` — l'expression court jusqu'à la
        // parenthèse fermante qui équilibre l'ouvrante, d'où le décompte manuel : une
        // expression contient elle-même des parenthèses, et un `[^)]*` s'arrêterait à la
        // première.
        preg_match_all('/ADD CONSTRAINT\s+(\w+)\s*\n?\s*CHECK\s*\(/i', $source, $entetes, \PREG_OFFSET_CAPTURE);

        $contraintes = [];

        foreach ($entetes[1] as $index => [$nom, $_]) {
            $debut = $entetes[0][$index][1] + \strlen($entetes[0][$index][0]);
            $profondeur = 1;
            $position = $debut;

            while ($profondeur > 0 && $position < \strlen($source)) {
                $profondeur += match ($source[$position]) {
                    '(' => 1,
                    ')' => -1,
                    default => 0,
                };
                ++$position;
            }

            $contraintes[$nom] = trim(substr($source, $debut, $position - $debut - 1));
        }

        return $contraintes;
    }
}
