<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * **Les cartes vues** : `noel_attribution.vue_le`, la première fois que le donneur a ouvert
 * sa carte « à qui j'offre ». L'organisateur voit qui l'a ouverte, jamais le nom tiré.
 */
final class Version20261028090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'noel_attribution.vue_le : qui a ouvert sa carte de Noël.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE noel_attribution ADD vue_le TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE noel_attribution DROP vue_le');
    }
}
