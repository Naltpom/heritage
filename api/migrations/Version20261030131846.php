<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `prp_change_op.blind` : un op proposé à l'aveugle peut avoir `old_value` égale à `new_value`
 * (ADR 0016, ADR 0023).
 *
 * ## Le défaut
 *
 * À l'aveugle, le serveur pose lui-même `old_value` (la valeur courante, que l'auteur ne voit
 * pas) et saute le refus « ne modifie rien ». Si la valeur proposée égalait la valeur
 * courante, l'INSERT violait `ck_prp_op_changement` : SQLSTATE 23514, que DBAL rend en
 * `DriverException` générique, donc une `500`. C'était un oracle d'égalité : `201` quand la
 * valeur devinée est fausse, `500` quand elle est juste — jusque dans un brouillon.
 * Le schéma des tests est construit depuis le mapping, qui ne porte pas les `CHECK` :
 * SQLite laissait passer ce que PostgreSQL refusait.
 *
 * ## Le correctif
 *
 * L'op est toujours écrit, et une colonne dit pourquoi l'égalité y est permise. Les deux
 * réponses (valeur juste ou fausse) sont ainsi indiscernables.
 *
 * ## Rien à reprendre
 *
 * La colonne naît à `false` et le nouveau `CHECK` est plus large que l'ancien : toute ligne
 * existante le respecte. `down()` supprime d'abord les ops à l'aveugle devenus incompatibles
 * avec l'ancien `CHECK`, c'est-à-dire ceux dont les deux valeurs sont égales : ils ne
 * changent rien à l'arbre.
 *
 * *Horodatage volontairement non rond : voir `Version20261028183417`.*
 */
final class Version20261030131846 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'prp_change_op.blind : un op proposé à l\'aveugle peut avoir une ancienne valeur égale à la nouvelle.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE prp_change_op ADD blind BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE prp_change_op DROP CONSTRAINT ck_prp_op_changement');
        $this->addSql(<<<'SQL'
            ALTER TABLE prp_change_op ADD CONSTRAINT ck_prp_op_changement
                CHECK (op_kind <> 'champ' OR blind OR old_value IS DISTINCT FROM new_value)
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM prp_change_op WHERE blind AND op_kind = 'champ' AND NOT (old_value IS DISTINCT FROM new_value)");
        $this->addSql('ALTER TABLE prp_change_op DROP CONSTRAINT ck_prp_op_changement');
        $this->addSql(<<<'SQL'
            ALTER TABLE prp_change_op ADD CONSTRAINT ck_prp_op_changement
                CHECK (op_kind <> 'champ' OR old_value IS DISTINCT FROM new_value)
            SQL);
        $this->addSql('ALTER TABLE prp_change_op DROP COLUMN blind');
    }
}
