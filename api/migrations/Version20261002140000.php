<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `core_user.telephone` — le téléphone **facultatif** donné à l'inscription, pour
 * l'annuaire des proches de l'administrateur. Nullable, sans défaut : les comptes
 * existants n'en ont pas, et n'en auront que s'ils le donnent.
 */
final class Version20261002140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'core_user.telephone : téléphone facultatif, donné à l’inscription.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_user ADD telephone VARCHAR(32) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_user DROP telephone');
    }
}
