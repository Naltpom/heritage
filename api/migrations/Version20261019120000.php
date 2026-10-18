<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * **Le texte de présentation d'un tirage de Noël**, écrit par son organisateur :
 * `noel_tirage.presentation`. Chaque organisateur s'adresse à son public (ses cousins,
 * ses enfants…) ; `NULL` garde le texte par défaut du site noel2026.
 *
 * Additive : l'ancienne version du code ignore la colonne.
 */
final class Version20261019120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Tirage de Noël : texte de présentation propre à chaque tirage (noel_tirage.presentation).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE noel_tirage ADD presentation TEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE noel_tirage DROP presentation');
    }
}
