<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Une extrémité de lien peut désigner une fiche proposée par **une autre demande**.
 *
 * ## Le défaut réparé, dans les mots où il a été signalé
 *
 * > « Si je propose une personne, elle apparaît dans mon arbre et celui de l'admin ;
 * > mais si je propose quelqu'un d'autre et que je cherche le père que j'ai proposé
 * > juste avant, non validé encore mais proposé par moi, il ne m'est pas proposé. »
 *
 * Une fiche proposée n'a pas d'identifiant de personne — c'est la prémisse du § 5.3 du
 * modèle — et les deux formes d'extrémité existantes ne suffisaient donc pas :
 *
 * | Forme | Ce qu'elle désigne | Sa portée |
 * |---|---|---|
 * | `*_person_id` | une ligne `tree_person` | l'arbre validé |
 * | `*_draft_ref` | `#p1`, `#p2`… | **la demande courante seulement** (`uniq_prp_draft_person_ref`) |
 *
 * Décrire une branche demandait donc de tout saisir dans **une** demande. Dès la
 * deuxième, la personne de la première restait hors de portée jusqu'à sa validation —
 * et la seule issue offerte au contributeur était de la ressaisir, c'est-à-dire de
 * fabriquer le doublon que le § 9.5 passe son temps à écarter.
 *
 * Les trois colonnes ajoutées ici désignent la **ligne** `prp_draft_person`, qui est
 * unique en base, elle.
 *
 * ## Aucune clé étrangère, et c'est une décision
 *
 * `prp_draft_person.request_id` est en `ON DELETE CASCADE` : supprimer une demande
 * emporte ses brouillons. Une clé étrangère en cascade depuis `prp_draft_link`
 * emporterait alors le **lien d'une autre demande** — elle l'amputerait en silence, et
 * la demande survivante décrirait une parenté dont une extrémité a disparu sans que
 * personne ne l'ait décidé. `ON DELETE SET NULL` serait pire encore : il laisserait un
 * lien sans aucune extrémité, ce que `ck_prp_link_sujet_unique` refuse.
 *
 * La référence est donc vérifiée **au point d'entrée** — `ConstructeurDeCreation` ne
 * laisse entrer que le brouillon d'une demande `en_attente` que le lecteur a le droit
 * de voir — et **relue au contrôle préalable** : `AnalyseDeDemande` traite une ligne
 * disparue en caducité, exactement comme une personne supprimée entre le dépôt et la
 * validation. C'est le régime de l'ADR 0002 pour les références inter-modules, appliqué
 * ici pour une raison de cascade plutôt que de frontière, et le motif est écrit pour
 * qu'on ne « répare » pas l'absence de contrainte un an plus tard.
 *
 * ## Aucun index non plus
 *
 * La question « quelles demandes citent ce brouillon ? » se poserait sur trois colonnes
 * à la fois, donc en `OR`, donc sans qu'un index simple serve. Et elle se pose sur une
 * file de quelques dizaines de demandes, pas sur les 274 personnes de l'arbre : le
 * balayage est mesuré en microsecondes. Trois index partiels de plus coûteraient à
 * chaque écriture ce qu'ils ne rapporteraient jamais en lecture.
 *
 * ## Les quatre `CHECK` réécrites
 *
 * Doctrine ne sait pas exprimer une contrainte `CHECK` et DBAL ne l'introspecte pas :
 * elles vivent dans les migrations et nulle part ailleurs. Les quatre reprises ici sont
 * celles de `Version20260915170602` **avec la troisième colonne ajoutée à chaque
 * `num_nonnulls`**. Elles sont donc `DROP` puis `ADD` : PostgreSQL ne sait pas modifier
 * une `CHECK` en place, et les laisser telles quelles interdirait en base la seule
 * chose que cette migration ajoute.
 */
final class Version20260917190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'prp_draft_link : une extrémité peut désigner la fiche proposée par une autre demande en attente.';
    }

    public function up(Schema $schema): void
    {
        // ------------------------------------------------------------------
        // 1. LES TROIS COLONNES — une instruction par colonne.
        //
        // Doctrine en produirait une seule portant les trois ; on les sépare parce que
        // `SchemaSynchroniseTest` rejoue l'historique colonne par colonne, et qu'il lit
        // un `ALTER TABLE … ADD` comme **une** colonne. Trois instructions disent la
        // même chose à PostgreSQL et restent comparables au mapping.
        // ------------------------------------------------------------------
        $this->addSql('ALTER TABLE prp_draft_link ADD subject_draft_person_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE prp_draft_link ADD other_draft_person_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE prp_draft_link ADD other2_draft_person_id INT DEFAULT NULL');

        // ------------------------------------------------------------------
        // 2. LES QUATRE CHECK — PostgreSQL seulement (voir l'en-tête).
        // ------------------------------------------------------------------

        // Le sujet reste **exactement une** des trois formes. C'est cette contrainte qui
        // interdit un lien sans sujet, et c'est elle qui rendrait impossible un
        // `ON DELETE SET NULL` sur la colonne qu'on vient d'ajouter.
        $this->addSql('ALTER TABLE prp_draft_link DROP CONSTRAINT ck_prp_link_sujet_unique');
        $this->addSql(<<<'SQL'
            ALTER TABLE prp_draft_link ADD CONSTRAINT ck_prp_link_sujet_unique
              CHECK (num_nonnulls(subject_person_id, subject_draft_ref, subject_draft_person_id) = 1)
            SQL);

        // Soit un couple existant, soit un ou deux parents — jamais les deux, « le
        // service ne saurait pas laquelle des deux intentions respecter ». La règle ne
        // change pas ; elle compte une colonne de plus de chaque côté.
        $this->addSql('ALTER TABLE prp_draft_link DROP CONSTRAINT ck_prp_link_cible');
        $this->addSql(<<<'SQL'
            ALTER TABLE prp_draft_link ADD CONSTRAINT ck_prp_link_cible
              CHECK (
                (family_id IS NOT NULL AND other_person_id IS NULL AND other_draft_ref IS NULL
                                      AND other_draft_person_id IS NULL
                                      AND other2_person_id IS NULL AND other2_draft_ref IS NULL
                                      AND other2_draft_person_id IS NULL)
                OR
                (family_id IS NULL AND num_nonnulls(other_person_id, other_draft_ref, other_draft_person_id) = 1)
              )
            SQL);

        // Un second parent ne se désigne que sur une filiation, et une seule fois.
        $this->addSql('ALTER TABLE prp_draft_link DROP CONSTRAINT ck_prp_link_second_parent');
        $this->addSql('ALTER TABLE prp_draft_link DROP CONSTRAINT ck_prp_link_second_parent_unique');
        $this->addSql(<<<'SQL'
            ALTER TABLE prp_draft_link
              ADD CONSTRAINT ck_prp_link_second_parent
                CHECK (num_nonnulls(other2_person_id, other2_draft_ref, other2_draft_person_id) = 0
                       OR kind = 'enfant_de'),
              ADD CONSTRAINT ck_prp_link_second_parent_unique
                CHECK (num_nonnulls(other2_person_id, other2_draft_ref, other2_draft_person_id) <= 1)
            SQL);
    }

    public function down(Schema $schema): void
    {
        // Les contraintes d'abord, les colonnes ensuite : une `CHECK` qui nomme une
        // colonne empêche de la retirer. Et les trois formes d'origine sont remises
        // telles qu'elles étaient dans `Version20260915170602` — une migration
        // descendante qui laisserait la forme élargie accepterait des lignes que la
        // version précédente du code ne sait pas lire.
        $this->addSql('ALTER TABLE prp_draft_link DROP CONSTRAINT ck_prp_link_second_parent');
        $this->addSql('ALTER TABLE prp_draft_link DROP CONSTRAINT ck_prp_link_second_parent_unique');
        $this->addSql('ALTER TABLE prp_draft_link DROP CONSTRAINT ck_prp_link_cible');
        $this->addSql('ALTER TABLE prp_draft_link DROP CONSTRAINT ck_prp_link_sujet_unique');

        $this->addSql('ALTER TABLE prp_draft_link DROP subject_draft_person_id');
        $this->addSql('ALTER TABLE prp_draft_link DROP other_draft_person_id');
        $this->addSql('ALTER TABLE prp_draft_link DROP other2_draft_person_id');

        $this->addSql(<<<'SQL'
            ALTER TABLE prp_draft_link ADD CONSTRAINT ck_prp_link_sujet_unique
              CHECK (num_nonnulls(subject_person_id, subject_draft_ref) = 1)
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE prp_draft_link ADD CONSTRAINT ck_prp_link_cible
              CHECK (
                (family_id IS NOT NULL AND other_person_id IS NULL AND other_draft_ref IS NULL
                                      AND other2_person_id IS NULL AND other2_draft_ref IS NULL)
                OR
                (family_id IS NULL AND num_nonnulls(other_person_id, other_draft_ref) = 1)
              )
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE prp_draft_link
              ADD CONSTRAINT ck_prp_link_second_parent
                CHECK (num_nonnulls(other2_person_id, other2_draft_ref) = 0 OR kind = 'enfant_de'),
              ADD CONSTRAINT ck_prp_link_second_parent_unique
                CHECK (num_nonnulls(other2_person_id, other2_draft_ref) <= 1)
            SQL);
    }
}
