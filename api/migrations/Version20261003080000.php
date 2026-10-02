<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * La transcription du message vocal **corrigée à la main** (ADR 0013, addendum du
 * 3 octobre 2026).
 *
 * Le propriétaire veut pouvoir relire et corriger la transcription automatique avant de
 * lancer la recherche de rattachement : Gemini écrit les noms propres « tels
 * qu'entendus », et un « Dupond » pour « Dupont » égare l'analyse. Une colonne :
 *
 * - `voice_message_transcript_edited_at` — la date de la dernière correction par un
 *   administrateur ; `null` tant que le texte est celui du modèle. Une nouvelle
 *   transcription automatique (« Retranscrire ») la remet à `null` : le texte n'est plus
 *   celui qui a été relu.
 *
 * C'est elle qui dit à l'analyse que les noms ont été relus — la consigne change
 * d'étiquette (« relue et corrigée par l'administrateur » au lieu de « telle
 * qu'entendue »). Le texte, lui, reste dans `voice_message_transcript`.
 *
 * Un `CHECK` :
 *
 * - `ck_mbr_member_transcription_corrigee` — pas de date de correction sans texte : une
 *   correction est un texte. Avec `ck_mbr_member_transcription_avec_vocal`, elle part
 *   avec le vocal, au rattachement comme au refus.
 */
final class Version20261003080000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'mbr_member : date de correction manuelle de la transcription du message vocal (ADR 0013, addendum).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mbr_member ADD voice_message_transcript_edited_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');

        $this->addSql(<<<'SQL'
            ALTER TABLE mbr_member
              ADD CONSTRAINT ck_mbr_member_transcription_corrigee
                CHECK (voice_message_transcript_edited_at IS NULL OR voice_message_transcript IS NOT NULL)
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mbr_member DROP CONSTRAINT ck_mbr_member_transcription_corrigee');
        $this->addSql('ALTER TABLE mbr_member DROP voice_message_transcript_edited_at');
    }
}
