<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Le **message vocal** de la déclaration de famille (ADR 0013, addendum du 2 octobre 2026).
 *
 * Le candidat qui ne s'est pas trouvé peut désormais **dire** sa famille au lieu de
 * l'écrire — ou en plus de l'écrire. Le fichier vit hors racine web, dans le volume des
 * téléversements (`messages-vocaux/`), sous un nom aléatoire ; la base n'en garde que ce
 * qu'il faut pour le servir à un administrateur :
 *
 * - `voice_message_name` — le nom sur disque, 32 hexadécimaux et l'extension du type réel ;
 * - `voice_message_mime` — le type **reniflé sur les octets** (`audio/webm`, `audio/ogg`,
 *   `audio/mp4`), jamais celui qu'annonçait le navigateur ;
 * - `voice_message_size` — en octets ;
 * - `voice_message_seconds` — la durée **mesurée par le chrono du navigateur**, indicative :
 *   un WebM produit par `MediaRecorder` n'annonce aucune durée, et l'écran de
 *   l'administrateur afficherait sinon « 0:00 ». Nulle si le client ne l'a pas donnée.
 *
 * Pas de nouvelle table, pour les raisons de l'ADR 0013 (« Pourquoi des colonnes et pas une
 * table ») : ces colonnes obéissent aux mêmes règles que la déclaration, et se purgent
 * avec elle.
 *
 * Trois `CHECK` :
 *
 * - `ck_mbr_member_message_vocal_complet` — nom, type et taille vont ensemble ;
 * - `ck_mbr_member_message_vocal_a_rattacher` — un message vocal n'existe que sur un compte
 *   `a_rattacher` **en attente**, exactement comme la déclaration : il part au rattachement
 *   comme au refus ;
 * - `ck_mbr_member_declaration_texte_ou_voix` — une déclaration a **un texte ou un vocal**
 *   (ou les deux). `family_description` n'est plus obligatoire quand un vocal la remplace.
 *   Le `declared_surname IS NULL` couvre la déclaration purgée.
 */
final class Version20261002190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'mbr_member : message vocal facultatif de la déclaration de famille (ADR 0013, addendum).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mbr_member ADD voice_message_name VARCHAR(40) DEFAULT NULL');
        $this->addSql('ALTER TABLE mbr_member ADD voice_message_mime VARCHAR(24) DEFAULT NULL');
        $this->addSql('ALTER TABLE mbr_member ADD voice_message_size INT DEFAULT NULL');
        $this->addSql('ALTER TABLE mbr_member ADD voice_message_seconds SMALLINT DEFAULT NULL');

        $this->addSql(<<<'SQL'
            ALTER TABLE mbr_member
              ADD CONSTRAINT ck_mbr_member_message_vocal_complet
                CHECK ((voice_message_name IS NULL) = (voice_message_mime IS NULL)
                       AND (voice_message_name IS NULL) = (voice_message_size IS NULL)
                       AND (voice_message_name IS NOT NULL OR voice_message_seconds IS NULL)),
              ADD CONSTRAINT ck_mbr_member_message_vocal_a_rattacher
                CHECK (voice_message_name IS NULL OR (match_kind = 'a_rattacher' AND status = 'en_attente')),
              ADD CONSTRAINT ck_mbr_member_declaration_texte_ou_voix
                CHECK (declared_surname IS NULL OR family_description IS NOT NULL OR voice_message_name IS NOT NULL)
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mbr_member DROP CONSTRAINT ck_mbr_member_declaration_texte_ou_voix');
        $this->addSql('ALTER TABLE mbr_member DROP CONSTRAINT ck_mbr_member_message_vocal_a_rattacher');
        $this->addSql('ALTER TABLE mbr_member DROP CONSTRAINT ck_mbr_member_message_vocal_complet');
        $this->addSql('ALTER TABLE mbr_member DROP voice_message_seconds');
        $this->addSql('ALTER TABLE mbr_member DROP voice_message_size');
        $this->addSql('ALTER TABLE mbr_member DROP voice_message_mime');
        $this->addSql('ALTER TABLE mbr_member DROP voice_message_name');
    }
}
