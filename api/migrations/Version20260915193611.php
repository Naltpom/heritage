<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `prp_change_request.reprise_de_id` — la seconde auto-référence de la table.
 *
 * **Pourquoi une migration séparée plutôt qu'une ligne de plus dans la migration
 * initiale.** La colonne avait d'abord été ajoutée en éditant `Version20260915170602`,
 * qui était déjà jouée sur une base de développement à volume persistant. Or une version
 * inscrite dans `doctrine_migration_versions` **ne se rejoue jamais** : Doctrine lit la
 * ligne, pas le fichier. La colonne serait donc apparue sur toute base créée à neuf et
 * jamais sur les autres — et le défaut était invisible chez nous, parce que les bases de
 * test se construisent par `schema:create`, qui lit les **entités** et non les migrations.
 * `phpunit` vert, `schema:validate` vert, et la seule base qui diverge est celle qui porte
 * les données.
 *
 * La démonstration a été faite sur deux piles, même version enregistrée :
 * volume persistant (274 personnes) → colonne **absente** ; volume neuf → colonne
 * **présente**.
 *
 * **La règle, désormais** : une migration jouée quelque part est figée. Ce qui change
 * après elle est une migration de plus, jamais une retouche.
 *
 * Ce que la colonne porte est décrit au § 5.1 du modèle de données, dans l'encadré qui la
 * distingue de `duplicate_of_id` : celle-ci rapproche **deux auteurs** proposant la même
 * personne, celle-là relie **un auteur à sa propre demande refaite** après un refus ou une
 * caducité. Sans elle, une demande dont la reprise a emporté les médias affiche un dossier
 * amputé de ses pièces, sans explication.
 */
final class Version20260915193611 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute prp_change_request.reprise_de_id : la demande refaite garde le lien vers son origine.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE prp_change_request ADD reprise_de_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE prp_change_request ADD CONSTRAINT FK_1A7C5F666B02171 FOREIGN KEY (reprise_de_id) REFERENCES prp_change_request (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_1A7C5F666B02171 ON prp_change_request (reprise_de_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE prp_change_request DROP CONSTRAINT FK_1A7C5F666B02171');
        $this->addSql('DROP INDEX IDX_1A7C5F666B02171');
        $this->addSql('ALTER TABLE prp_change_request DROP reprise_de_id');
    }
}
