<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * La **transcription** du message vocal de la déclaration (ADR 0013, addendum du 2 octobre
 * 2026, soir ; ADR 0008, addendum du même jour).
 *
 * Le message vocal n'était qu'écouté par l'administrateur ; il est désormais **transcrit**
 * par Gemini, après coup, et la transcription nourrit l'analyse de rattachement comme la
 * description écrite. Trois colonnes sur la même ligne — c'est une partie de la
 * déclaration, elle en suit les règles :
 *
 * - `voice_message_transcript` — le texte rendu par le modèle, verbatim ; **des données de
 *   vivants**, comme la description (colonne sensible du journal mécanique) ;
 * - `voice_message_transcript_error` — le motif du dernier échec, écrit par le serveur
 *   (« délai dépassé », « quota »…), jamais une réponse du modèle ;
 * - `voice_message_transcribed_at` — la date de la dernière tentative, réussie ou non.
 *
 * Trois `CHECK` :
 *
 * - `ck_mbr_member_transcription_avec_vocal` — pas de transcription (ni d'échec, ni de
 *   date) sans message vocal : elle part avec lui, au rattachement comme au refus ;
 * - `ck_mbr_member_transcription_ou_echec` — un texte **ou** un échec, jamais les deux :
 *   une nouvelle tentative remplace la précédente ;
 * - `ck_mbr_member_transcription_datee` — un texte ou un échec porte sa date, et une date
 *   seule ne veut rien dire.
 */
final class Version20261002220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'mbr_member : transcription automatique du message vocal de la déclaration (ADR 0013, addendum).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mbr_member ADD voice_message_transcript TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE mbr_member ADD voice_message_transcript_error VARCHAR(300) DEFAULT NULL');
        $this->addSql('ALTER TABLE mbr_member ADD voice_message_transcribed_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');

        $this->addSql(<<<'SQL'
            ALTER TABLE mbr_member
              ADD CONSTRAINT ck_mbr_member_transcription_avec_vocal
                CHECK (voice_message_name IS NOT NULL
                       OR (voice_message_transcript IS NULL AND voice_message_transcript_error IS NULL
                           AND voice_message_transcribed_at IS NULL)),
              ADD CONSTRAINT ck_mbr_member_transcription_ou_echec
                CHECK (voice_message_transcript IS NULL OR voice_message_transcript_error IS NULL),
              ADD CONSTRAINT ck_mbr_member_transcription_datee
                CHECK ((voice_message_transcribed_at IS NULL)
                       = (voice_message_transcript IS NULL AND voice_message_transcript_error IS NULL))
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mbr_member DROP CONSTRAINT ck_mbr_member_transcription_datee');
        $this->addSql('ALTER TABLE mbr_member DROP CONSTRAINT ck_mbr_member_transcription_ou_echec');
        $this->addSql('ALTER TABLE mbr_member DROP CONSTRAINT ck_mbr_member_transcription_avec_vocal');
        $this->addSql('ALTER TABLE mbr_member DROP voice_message_transcribed_at');
        $this->addSql('ALTER TABLE mbr_member DROP voice_message_transcript_error');
        $this->addSql('ALTER TABLE mbr_member DROP voice_message_transcript');
    }
}
