<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `core_audit_log` : qui **incarnait** l'acteur (ADR 0010).
 *
 * Sous une incarnation, `actor_user_id` est le compte incarné, et ces deux colonnes disent
 * quel administrateur tenait réellement le clavier. `NULL` partout ailleurs — y compris sur
 * toutes les lignes antérieures, où il n'y avait pas d'incarnation possible.
 *
 * Deux colonnes et pas une clé étrangère : le journal est en insertion seule et doit survivre
 * au compte qu'il nomme, comme `actor_user_id` + `actor_email` avant elles.
 */
final class Version20260928090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'core_audit_log : impersonator_user_id et impersonator_email — l’administrateur derrière une incarnation (ADR 0010).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_audit_log ADD impersonator_user_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE core_audit_log ADD impersonator_email VARCHAR(180) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // Ce qui est perdu : la trace de qui, parmi les actions passées, a été faite par un
        // administrateur au nom d'un membre.
        $this->addSql('ALTER TABLE core_audit_log DROP impersonator_user_id');
        $this->addSql('ALTER TABLE core_audit_log DROP impersonator_email');
    }
}
