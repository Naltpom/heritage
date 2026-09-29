<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `mbr_invitation` : un lien d'invitation peut servir **plusieurs fois**.
 *
 * Jusqu'ici, le premier compte créé consommait le lien. L'administrateur veut pouvoir
 * poster un seul lien dans le groupe de la famille : illimité, ou plafonné à N comptes,
 * avec ou sans échéance.
 *
 * - `max_uses` : le plafond, `NULL` = illimité.
 * - `use_count` : les comptes nés du lien, comparé au plafond sous verrou de ligne à
 *   l'inscription.
 * - `expires_at` devient facultatif : `NULL` = n'expire jamais.
 *
 * ## Les lignes existantes gardent leur sens
 *
 * `max_uses = 1`, et `use_count` vaut 1 si `used_at` est posé : une invitation nominative
 * d'hier reste une invitation nominative, consommée ou non. Rien ne s'ouvre en silence.
 *
 * ## `ck_mbr_invitation_exclusif` tombe
 *
 * Elle interdisait `used_at` et `revoked_at` ensemble. Un lien collectif utilisé trois
 * fois puis révoqué porte légitimement les deux : la révocation arrête les inscriptions
 * à venir, elle ne défait pas les comptes créés. Ce que la contrainte gardait vraiment —
 * « on ne révoque pas un lien épuisé » — est une règle du service (`409
 * invitation_deja_consommee`), pas une propriété de la ligne.
 */
final class Version20260929161700 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'mbr_invitation : liens multi-usages (max_uses, use_count), échéance facultative.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mbr_invitation ADD max_uses INT DEFAULT NULL');
        $this->addSql('ALTER TABLE mbr_invitation ADD use_count INT DEFAULT 0 NOT NULL');
        $this->addSql(<<<'SQL'
            UPDATE mbr_invitation
               SET max_uses = 1,
                   use_count = CASE WHEN used_at IS NOT NULL THEN 1 ELSE 0 END
            SQL);
        $this->addSql('ALTER TABLE mbr_invitation ALTER expires_at DROP NOT NULL');

        $this->addSql('ALTER TABLE mbr_invitation DROP CONSTRAINT ck_mbr_invitation_exclusif');
        $this->addSql(<<<'SQL'
            ALTER TABLE mbr_invitation
              ADD CONSTRAINT ck_mbr_invitation_plafond
                CHECK (max_uses IS NULL OR max_uses >= 1),
              ADD CONSTRAINT ck_mbr_invitation_usages
                CHECK (use_count >= 0 AND (max_uses IS NULL OR use_count <= max_uses))
            SQL);
    }

    public function down(Schema $schema): void
    {
        // Le retour à l'usage unique ne peut pas tout représenter. Les lignes sans échéance
        // reçoivent « maintenant » — un lien sans fin redevient un lien mort, ce qui est le
        // sens prudent. Un lien utilisé puis révoqué perd sa révocation, que l'ancienne
        // contrainte interdisait à côté de `used_at` ; son statut est ramené à `utilisee`,
        // ce qu'il était du point de vue de l'ancien modèle. Les comptes créés restent :
        // `mbr_member.invitation_id` n'est pas touché.
        $this->addSql('ALTER TABLE mbr_invitation DROP CONSTRAINT ck_mbr_invitation_usages');
        $this->addSql('ALTER TABLE mbr_invitation DROP CONSTRAINT ck_mbr_invitation_plafond');

        $this->addSql('UPDATE mbr_invitation SET expires_at = now() WHERE expires_at IS NULL');
        $this->addSql('ALTER TABLE mbr_invitation ALTER expires_at SET NOT NULL');

        $this->addSql(<<<'SQL'
            UPDATE mbr_invitation
               SET revoked_at = NULL, revoked_by_user_id = NULL, revoked_by_email = NULL,
                   status = 'utilisee'
             WHERE used_at IS NOT NULL AND revoked_at IS NOT NULL
            SQL);
        // Un lien multi-usages encore ouvert mais déjà utilisé serait, pour l'ancien
        // modèle, consommé : l'y ramener évite qu'il se rouvre à la redescente.
        $this->addSql("UPDATE mbr_invitation SET status = 'utilisee' WHERE used_at IS NOT NULL AND status = 'active'");

        $this->addSql(<<<'SQL'
            ALTER TABLE mbr_invitation
              ADD CONSTRAINT ck_mbr_invitation_exclusif
                CHECK (used_at IS NULL OR revoked_at IS NULL)
            SQL);

        $this->addSql('ALTER TABLE mbr_invitation DROP use_count');
        $this->addSql('ALTER TABLE mbr_invitation DROP max_uses');
    }
}
