<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * L'inscription **sans fiche** — ADR 0013.
 *
 * Un lien d'invitation illimité circule dans le groupe de la famille ; un cousin qui ne se
 * trouve pas dans l'arbre (ou pas avec la bonne date) ne pouvait pas créer de compte, parce
 * que `mbr_member.person_id` était obligatoire. Désormais il décrit sa famille en texte
 * libre, son compte naît `en_attente` **sans fiche**, et un administrateur le rattache —
 * à une fiche existante ou à une fiche qu'il crée pour lui.
 *
 * ## `mbr_member`
 *
 * - `person_id` et `person_label` deviennent facultatifs, **pour la seule nature
 *   `a_rattacher`** : `ck_mbr_member_fiche` lie les deux, et `ck_mbr_member_libelle` interdit
 *   une fiche sans libellé ou l'inverse.
 * - Un compte sans fiche n'est jamais actif (`ck_mbr_member_sans_fiche_inactif`) : il n'a pas
 *   d'ancre de cercle, et un compte actif sans cercle est exactement ce que la fabrique du
 *   lecteur refuse d'imaginer.
 * - Six colonnes portent la **déclaration** — identité saisie, description de la famille — et
 *   la **suggestion** de l'assistant, mise en cache. `ck_mbr_member_declaration` les réserve
 *   aux comptes `a_rattacher` : ce sont des données de vivants, purgées au rattachement comme
 *   au refus, et la base refuse qu'elles survivent à un changement de nature.
 *   `ck_mbr_member_declaration_en_attente` dit la même chose du **statut** : un compte refusé
 *   reste `a_rattacher` (suspendu), et la nature seule laissait une analyse qui se termine
 *   après le refus réécrire sa suggestion — défaire la purge.
 * - `uniq_mbr_member_person` ne change pas : PostgreSQL admet plusieurs `NULL` dans un index
 *   unique, et SQLite aussi.
 *
 * ## `med_media`
 *
 * `subject_user_id` est le troisième sujet possible d'un média : la photo qu'un compte sans
 * fiche validée dépose pour lui-même. Identifiant nu vers `core_user`, sans clé étrangère —
 * ADR 0002, règle 2. `ck_med_media_sujet_unique` passe de deux sujets à trois ;
 * `ck_med_media_compte` borne ce sujet au portrait d'une personne, jamais actif ; l'index
 * unique partiel garde **au plus un** portrait en attente par compte.
 *
 * ## Aucune table
 *
 * Pas de nouvelle table, et c'est voulu : `SchemaSynchroniseTest` compte les tables, et la
 * branche `notifications-push` en ajoute de son côté.
 *
 * ## Redescente — réversible sous conditions, et irréversible après un refus
 *
 * `down()` s'arrête, sans rien toucher, tant qu'il reste une inscription **en attente** sans
 * fiche (à rattacher ou refuser d'abord) — et, définitivement, dès qu'un compte a été
 * **refusé** : il reste suspendu et sans fiche, l'ancien schéma ne sait pas le représenter,
 * et la migration ne le supprime pas à la place de l'exploitant. Le détail est dans `down()`.
 *
 * ## Forme des instructions
 *
 * `ALTER person_id DROP NOT NULL` sans le mot `COLUMN` : c'est la forme que Doctrine produit
 * et que `SchemaSynchroniseTest` sait rejouer — même geste que `Version20260929161700`.
 */
final class Version20261001120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'mbr_member : comptes sans fiche (a_rattacher), déclaration et suggestion ; med_media : portrait en attente du compte.';
    }

    public function up(Schema $schema): void
    {
        // -- mbr_member --------------------------------------------------------
        $this->addSql('ALTER TABLE mbr_member ALTER person_id DROP NOT NULL');
        $this->addSql('ALTER TABLE mbr_member ALTER person_label DROP NOT NULL');
        $this->addSql('ALTER TABLE mbr_member ADD declared_surname VARCHAR(120) DEFAULT NULL');
        $this->addSql('ALTER TABLE mbr_member ADD declared_given_names VARCHAR(160) DEFAULT NULL');
        $this->addSql('ALTER TABLE mbr_member ADD declared_birth_date DATE DEFAULT NULL');
        $this->addSql('ALTER TABLE mbr_member ADD family_description TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE mbr_member ADD attachment_suggestion JSONB DEFAULT NULL');
        $this->addSql('ALTER TABLE mbr_member ADD attachment_suggestion_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');

        $this->addSql(<<<'SQL'
            ALTER TABLE mbr_member
              ADD CONSTRAINT ck_mbr_member_fiche
                CHECK ((person_id IS NULL) = (match_kind = 'a_rattacher')),
              ADD CONSTRAINT ck_mbr_member_libelle
                CHECK ((person_id IS NULL) = (person_label IS NULL)),
              ADD CONSTRAINT ck_mbr_member_sans_fiche_inactif
                CHECK (person_id IS NOT NULL OR status <> 'actif'),
              ADD CONSTRAINT ck_mbr_member_declaration
                CHECK (match_kind = 'a_rattacher' OR (family_description IS NULL AND declared_surname IS NULL
                       AND declared_given_names IS NULL AND declared_birth_date IS NULL
                       AND attachment_suggestion IS NULL)),
              ADD CONSTRAINT ck_mbr_member_declaration_en_attente
                CHECK (status = 'en_attente' OR (family_description IS NULL AND declared_surname IS NULL
                       AND declared_given_names IS NULL AND declared_birth_date IS NULL
                       AND attachment_suggestion IS NULL AND attachment_suggestion_at IS NULL))
            SQL);

        // -- med_media ---------------------------------------------------------
        $this->addSql('ALTER TABLE med_media ADD subject_user_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE med_media DROP CONSTRAINT ck_med_media_sujet_unique');
        $this->addSql(<<<'SQL'
            ALTER TABLE med_media
              ADD CONSTRAINT ck_med_media_sujet_unique
                CHECK (num_nonnulls(subject_id, subject_draft_ref, subject_user_id) = 1),
              ADD CONSTRAINT ck_med_media_compte
                CHECK (subject_user_id IS NULL OR (kind = 'portrait' AND subject_kind = 'personne' AND status IN ('en_attente', 'refuse')))
            SQL);
        $this->addSql("CREATE UNIQUE INDEX uniq_med_media_portrait_de_compte ON med_media (subject_user_id) WHERE (subject_user_id IS NOT NULL AND (status)::text = 'en_attente'::text)");
    }

    public function down(Schema $schema): void
    {
        // La redescente ne sait représenter ni un compte sans fiche (`person_id` redevient
        // obligatoire) ni une photo posée sur un compte. Les effacer en silence détruirait
        // des données ; on refuse donc, avec un message qui dit **quoi faire** — et il y a
        // deux populations, qui n'appellent pas le même geste.
        //
        // 1. Les comptes **en attente** sans fiche : des inscriptions en cours. Le geste
        //    existe — les rattacher (l'activation pose la fiche) ou les refuser.
        $enAttente = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM mbr_member WHERE person_id IS NULL AND status = 'en_attente'",
        );
        $this->abortIf($enAttente > 0, \sprintf(
            '%d inscription(s) en attente sans fiche : rattachez-les (onglet « À rattacher ») ou refusez-les, puis relancez la redescente.',
            $enAttente,
        ));

        // 2. Les comptes **refusés** — suspendus, `a_rattacher`, sans fiche pour toujours.
        //    Les refuser ne sert plus à rien : ils le sont. Aucun geste du produit ne leur
        //    donne une fiche, et un refus n'est pas une suppression (`core_user` reste
        //    référencé par le journal, les notifications, les sessions — ADR 0013).
        //
        //    **La migration est donc irréversible tant qu'il en existe un**, et elle ne les
        //    supprime pas à votre place : supprimer une ligne `mbr_member` rendrait le
        //    courriel du compte réutilisable à l'inscription — l'inverse de ce que le refus
        //    a décidé. Redescendre malgré tout est une décision d'exploitant, à prendre en
        //    connaissance de cause (supprimer à la main ces lignes `mbr_member`, en sachant
        //    ce que cela rouvre), pas un effet de bord d'une commande de migration.
        $refuses = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM mbr_member WHERE person_id IS NULL AND status <> 'en_attente'",
        );
        $this->abortIf($refuses > 0, \sprintf(
            '%d compte(s) refusé(s) sans fiche : l’ancien schéma ne sait pas les représenter, et aucun geste du produit ne leur donne de fiche. '
            .'Cette migration est irréversible tant qu’ils existent (voir le commentaire de down()).',
            $refuses,
        ));

        // Une photo posée sur un compte n'existe que pour un compte en attente : le refus
        // l'efface, le rattachement la déplace sur la fiche. Les comptes en attente étant
        // déjà exclus plus haut, il ne devrait en rester aucune — mais une ligne écrite à la
        // main ne se perd pas sans qu'on le dise.
        $photos = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM med_media WHERE subject_user_id IS NOT NULL');
        $this->abortIf($photos > 0, \sprintf(
            '%d photo(s) encore posée(s) sur un compte, sans inscription en attente : rattachez-les à une fiche ou supprimez-les avant de redescendre.',
            $photos,
        ));

        $this->addSql('DROP INDEX uniq_med_media_portrait_de_compte');
        $this->addSql('ALTER TABLE med_media DROP CONSTRAINT ck_med_media_compte');
        $this->addSql('ALTER TABLE med_media DROP CONSTRAINT ck_med_media_sujet_unique');
        $this->addSql(<<<'SQL'
            ALTER TABLE med_media
              ADD CONSTRAINT ck_med_media_sujet_unique
                CHECK (num_nonnulls(subject_id, subject_draft_ref) = 1)
            SQL);
        $this->addSql('ALTER TABLE med_media DROP subject_user_id');

        $this->addSql('ALTER TABLE mbr_member DROP CONSTRAINT ck_mbr_member_declaration_en_attente');
        $this->addSql('ALTER TABLE mbr_member DROP CONSTRAINT ck_mbr_member_declaration');
        $this->addSql('ALTER TABLE mbr_member DROP CONSTRAINT ck_mbr_member_sans_fiche_inactif');
        $this->addSql('ALTER TABLE mbr_member DROP CONSTRAINT ck_mbr_member_libelle');
        $this->addSql('ALTER TABLE mbr_member DROP CONSTRAINT ck_mbr_member_fiche');
        $this->addSql('ALTER TABLE mbr_member DROP attachment_suggestion_at');
        $this->addSql('ALTER TABLE mbr_member DROP attachment_suggestion');
        $this->addSql('ALTER TABLE mbr_member DROP family_description');
        $this->addSql('ALTER TABLE mbr_member DROP declared_birth_date');
        $this->addSql('ALTER TABLE mbr_member DROP declared_given_names');
        $this->addSql('ALTER TABLE mbr_member DROP declared_surname');
        $this->addSql('ALTER TABLE mbr_member ALTER person_label SET NOT NULL');
        $this->addSql('ALTER TABLE mbr_member ALTER person_id SET NOT NULL');
    }
}
