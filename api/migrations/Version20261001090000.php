<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Le lien personnel d'un participant de tirage : deux colonnes sur `trg_participant`.
 *
 * `lien_token_hash` porte le `sha256` hexadécimal de la clé du lien `/t/<clé>` — jamais la
 * clé elle-même, comme `mbr_invitation.token_hash`. `lien_cree_le` date la création ou la
 * dernière régénération. Les deux sont `NULL` sans lien actif.
 *
 * ## Deux colonnes, pas une table
 *
 * Un seul lien actif par participant, qui vaut pour toutes les éditions et meurt avec le
 * participant : ce sont les règles de vie de la ligne `trg_participant` elle-même. Une table
 * à part demanderait un index unique pour « un seul lien », une clé étrangère en cascade
 * pour « meurt avec lui », et n'apporterait rien — voir l'en-tête de
 * `Tirage\Entity\Participant`.
 *
 * ## L'index unique
 *
 * Il porte la recherche par empreinte (aucune comparaison de secret en PHP), et PostgreSQL
 * y admet autant de `NULL` que de participants sans lien. Le `CHECK` tient l'équivalence :
 * une empreinte a toujours sa date, et une date n'existe pas sans empreinte.
 */
final class Version20261001090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'trg_participant : le lien personnel d’un participant (empreinte de la clé, date de création).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE trg_participant ADD lien_token_hash VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE trg_participant ADD lien_cree_le TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_trg_participant_lien ON trg_participant (lien_token_hash)');
        $this->addSql(<<<'SQL'
            ALTER TABLE trg_participant ADD CONSTRAINT ck_trg_participant_lien
                CHECK ((lien_token_hash IS NULL) = (lien_cree_le IS NULL))
            SQL);
    }

    public function down(Schema $schema): void
    {
        // Tous les liens transmis cessent de valoir : il faudra les régénérer.
        $this->addSql('ALTER TABLE trg_participant DROP CONSTRAINT ck_trg_participant_lien');
        $this->addSql('DROP INDEX uniq_trg_participant_lien');
        $this->addSql('ALTER TABLE trg_participant DROP lien_cree_le');
        $this->addSql('ALTER TABLE trg_participant DROP lien_token_hash');
    }
}
