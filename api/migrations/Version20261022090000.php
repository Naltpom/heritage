<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * **La nature d'une union** (ADR 0020) : `tree_family.married` et `prp_draft_link.married`,
 * `BOOLEAN NULL` — `true` mariage attesté, daté ou non ; `false` union sans mariage (union
 * libre, PACS) ; `NULL` on ne sait pas.
 *
 * ## La reprise : un mariage daté ou situé est un mariage, rien de plus
 *
 * `married = true` là où la base porte une date (`qualifier <> 'inconnue'` ou une chaîne
 * `raw`) ou un lieu de mariage ; `NULL` partout ailleurs. Le prédicat est **le même** que
 * celui de la contrainte posée juste après — c'est ce qui garantit qu'elle passe sur les
 * lignes existantes, au lieu de l'espérer.
 *
 * Ce que la reprise ne fait **pas**, et pourquoi :
 *
 * - elle ne déduit rien de `divorced`. « Divorcés » suppose un mariage en français courant,
 *   mais `divorced` couvre ici **toute** rupture — séparation comprise (§ 3.2 du modèle) :
 *   une rupture sans trace de mariage reste de nature inconnue, et s'affiche « séparés » ;
 * - elle ne touche pas `prp_draft_link` : les liens des demandes en attente naissent
 *   `NULL`, et un lien qui porte une date de mariage sera normalisé **à sa validation**,
 *   par l'entité `Family`. Réécrire une demande déposée, c'est changer ce que son auteur a
 *   écrit — et l'empreinte que tient l'écran de qui la complète.
 *
 * ## Les contraintes
 *
 * - `ck_tree_family_mariage_coherent` — `married IS TRUE OR (aucune date, aucun lieu)`.
 *   **`IS TRUE` et non `= true`** : un `CHECK` passe quand son expression vaut `NULL`, et
 *   `married = true OR …` laisserait donc passer `married = NULL` avec une date — c'est-à-
 *   dire exactement la ligne que la contrainte existe pour refuser.
 * - `ck_prp_link_dates_union` est redéfinie : la nature, comme les dates, ne vaut que sur
 *   `conjoint_de`. Toutes les lignes existantes ont `married IS NULL`, elle passe.
 *
 * Pas de `CHECK` de cohérence sur `prp_draft_link` : sans reprise, il refuserait les liens
 * datés des demandes en attente. La règle y est tenue au dépôt (`ConstructeurDeCreation`).
 *
 * Additive pour la lecture : l'ancienne version du code ignore les colonnes. Elle ne sait
 * pas, en revanche, poser `married` en écrivant une date — pendant la bascule d'un
 * déploiement, une validation qui daterait un mariage par l'ancien code serait refusée par
 * la contrainte, en transaction, sans rien écrire.
 */
final class Version20261022090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Nature de l’union : tree_family.married et prp_draft_link.married (ADR 0020), reprise sur les mariages datés ou situés.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tree_family ADD married BOOLEAN DEFAULT NULL');
        $this->addSql(<<<'SQL'
            UPDATE tree_family
               SET married = true
             WHERE NOT (marriage_qualifier = 'inconnue' AND marriage_raw IS NULL AND marriage_place IS NULL)
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE tree_family ADD CONSTRAINT ck_tree_family_mariage_coherent
              CHECK (married IS TRUE OR (marriage_qualifier = 'inconnue' AND marriage_raw IS NULL AND marriage_place IS NULL))
            SQL);

        $this->addSql('ALTER TABLE prp_draft_link ADD married BOOLEAN DEFAULT NULL');
        $this->addSql('ALTER TABLE prp_draft_link DROP CONSTRAINT ck_prp_link_dates_union');
        $this->addSql(<<<'SQL'
            ALTER TABLE prp_draft_link ADD CONSTRAINT ck_prp_link_dates_union
              CHECK (kind = 'conjoint_de' OR (marriage_raw IS NULL AND marriage_min IS NULL
                                              AND divorce_raw IS NULL AND divorce_min IS NULL
                                              AND divorced = false AND married IS NULL))
            SQL);
    }

    public function down(Schema $schema): void
    {
        // Ce qui est perdu : les natures posées à la main — « mariés, date inconnue » et
        // « union libre ». Celles que la reprise avait déduites se redéduiraient d'elles-mêmes.
        $this->addSql('ALTER TABLE prp_draft_link DROP CONSTRAINT ck_prp_link_dates_union');
        $this->addSql(<<<'SQL'
            ALTER TABLE prp_draft_link ADD CONSTRAINT ck_prp_link_dates_union
              CHECK (kind = 'conjoint_de' OR (marriage_raw IS NULL AND marriage_min IS NULL
                                              AND divorce_raw IS NULL AND divorce_min IS NULL
                                              AND divorced = false))
            SQL);
        $this->addSql('ALTER TABLE prp_draft_link DROP married');

        $this->addSql('ALTER TABLE tree_family DROP CONSTRAINT ck_tree_family_mariage_coherent');
        $this->addSql('ALTER TABLE tree_family DROP married');
    }
}
