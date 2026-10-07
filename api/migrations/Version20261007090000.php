<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * **Trois niveaux de partage** (ADR 0016, révision du 2026-10-07) — l'année, le mois et le
 * jour de naissance se partagent désormais avec `tous` les vivants de l'arbre, avec ses
 * `proches`, ou avec `personne`.
 *
 * - `mbr_member.birth_share_year|month|day` et `mbr_autorisation_naissance.share_*`
 *   passent de `BOOLEAN` à `VARCHAR(8)`. **Ce qui était partagé passe à `tous`, ce qui ne
 *   l'était pas passe à `proches`** : personne ne perd ce qu'il voyait, et les proches se
 *   voient entre eux. Le défaut des nouvelles lignes est `proches`.
 * - Le verrou de 30 jours (`birth_share_*_since`) ne porte plus que sur `tous` : les
 *   colonnes ne changent pas, leur sens non plus pour ce qui était partagé.
 * - Chaque compte rattaché reçoit une **notification** (`partage_naissance_revu`), et le
 *   journal d'audit une ligne `partage_naissance` de source `migration` : le changement
 *   de défaut se dit, et se prouve.
 *
 * Les `CHECK` : `ck_mbr_member_partage_niveaux`, `ck_mbr_autorisation_niveaux`.
 */
final class Version20261007090000 extends AbstractMigration
{
    private const array COLONNES_MEMBRE = ['birth_share_year', 'birth_share_month', 'birth_share_day'];

    private const array COLONNES_AUTORISATION = ['share_year', 'share_month', 'share_day'];

    public function getDescription(): string
    {
        return 'Réciprocité des vivants : trois niveaux de partage (tous, proches, personne), proches par défaut (ADR 0016, révision du 2026-10-07).';
    }

    public function up(Schema $schema): void
    {
        // La trace, avant que les booléens ne disparaissent : ce qui change pour chacun.
        $this->addSql(<<<'SQL'
            INSERT INTO core_audit_log (occurred_at, action, subject_type, subject_id, subject_label, changes)
            SELECT NOW(), 'partage_naissance', 'membre', m.id::text, m.user_email,
                   jsonb_build_object(
                     'source', 'migration',
                     'avant', jsonb_build_object('annee', m.birth_share_year, 'mois', m.birth_share_month, 'jour', m.birth_share_day),
                     'apres', jsonb_build_object(
                       'annee', CASE WHEN m.birth_share_year THEN 'tous' ELSE 'proches' END,
                       'mois', CASE WHEN m.birth_share_month THEN 'tous' ELSE 'proches' END,
                       'jour', CASE WHEN m.birth_share_day THEN 'tous' ELSE 'proches' END))
            FROM mbr_member m
            WHERE m.person_id IS NOT NULL
            SQL);

        foreach (self::COLONNES_MEMBRE as $colonne) {
            $this->versNiveau('mbr_member', $colonne, true);
        }
        foreach (self::COLONNES_AUTORISATION as $colonne) {
            $this->versNiveau('mbr_autorisation_naissance', $colonne, false);
        }

        $this->addSql(<<<'SQL'
            ALTER TABLE mbr_member
              ADD CONSTRAINT ck_mbr_member_partage_niveaux CHECK (
                birth_share_year IN ('tous', 'proches', 'personne')
                AND birth_share_month IN ('tous', 'proches', 'personne')
                AND birth_share_day IN ('tous', 'proches', 'personne'))
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE mbr_autorisation_naissance
              ADD CONSTRAINT ck_mbr_autorisation_niveaux CHECK (
                share_year IN ('tous', 'proches', 'personne')
                AND share_month IN ('tous', 'proches', 'personne')
                AND share_day IN ('tous', 'proches', 'personne'))
            SQL);

        $this->addSql(<<<'SQL'
            INSERT INTO core_notification (recipient_user_id, kind, title, body, link_route, created_at)
            SELECT m.user_id, 'partage_naissance_revu',
                   'Vos proches voient désormais votre date de naissance',
                   'Pour l’année, le mois et le jour, vous choisissez maintenant entre tous les vivants de l’arbre, vos proches seulement (parents, enfants, frères et sœurs, conjoint, beaux-parents…) ou personne. Ce que vous ne partagiez pas est passé à « vos proches ». Vous pouvez changer ce choix dans votre compte.',
                   'compte', NOW()
            FROM mbr_member m
            WHERE m.person_id IS NOT NULL
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM core_notification WHERE kind = 'partage_naissance_revu'");
        $this->addSql('ALTER TABLE mbr_autorisation_naissance DROP CONSTRAINT ck_mbr_autorisation_niveaux');
        $this->addSql('ALTER TABLE mbr_member DROP CONSTRAINT ck_mbr_member_partage_niveaux');

        // Seul `tous` redevient un partage : `proches` n'existait pas, il redevient « rien ».
        foreach (self::COLONNES_MEMBRE as $colonne) {
            $this->versBooleen('mbr_member', $colonne, true);
        }
        foreach (self::COLONNES_AUTORISATION as $colonne) {
            $this->versBooleen('mbr_autorisation_naissance', $colonne, false);
        }
    }

    private function versNiveau(string $table, string $colonne, bool $avecDefaut): void
    {
        if ($avecDefaut) {
            $this->addSql(\sprintf('ALTER TABLE %s ALTER %s DROP DEFAULT', $table, $colonne));
        }
        $this->addSql(\sprintf(
            "ALTER TABLE %s ALTER %s TYPE VARCHAR(8) USING (CASE WHEN %s THEN 'tous' ELSE 'proches' END)",
            $table,
            $colonne,
            $colonne,
        ));
        if ($avecDefaut) {
            $this->addSql(\sprintf("ALTER TABLE %s ALTER %s SET DEFAULT 'proches'", $table, $colonne));
        }
    }

    private function versBooleen(string $table, string $colonne, bool $avecDefaut): void
    {
        if ($avecDefaut) {
            $this->addSql(\sprintf('ALTER TABLE %s ALTER %s DROP DEFAULT', $table, $colonne));
        }
        $this->addSql(\sprintf(
            "ALTER TABLE %s ALTER %s TYPE BOOLEAN USING (%s = 'tous')",
            $table,
            $colonne,
            $colonne,
        ));
        if ($avecDefaut) {
            $this->addSql(\sprintf('ALTER TABLE %s ALTER %s SET DEFAULT false', $table, $colonne));
        }
    }
}
