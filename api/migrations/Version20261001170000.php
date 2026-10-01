<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Deux suites à la page « Actes demandés » — `dmr_demarche`. Voir l'ADR 0011, révision
 * du 2026-10-01, et `docs/api-contract.md` § « Actes demandés ».
 *
 * ## Le statut `expediee`
 *
 * « Traité, l'acte part par la poste » : la mairie a répondu, la pièce n'est pas encore
 * là. La démarche reste **en attente** — de la pièce —, mais n'est plus **à relancer** :
 * on attend le facteur, pas la mairie. La contrainte `ck_dmr_demarche_statut` posée par
 * `Version20260930140000` énumère les valeurs admises ; elle est reposée avec la nouvelle.
 * Huit signes : le `VARCHAR(12)` de la colonne suffit.
 *
 * ## `precedente_id` : « suite de »
 *
 * Une mairie refuse les demandes par courriel ; on lui écrit par la poste. C'est une
 * nouvelle démarche, qui dit laquelle elle reprend. Une **auto-référence** — même table,
 * même module : la clé étrangère est interne (ADR 0002, règle 2) —, en
 * **`ON DELETE SET NULL`** : supprimer la démarche reprise ne supprime pas celle qui la
 * reprend, qui perd seulement le lien.
 *
 * L'index `IDX_…` est celui que Doctrine pose de lui-même sur la colonne de jointure ; il
 * sert aussi la question inverse — « qui reprend celle-ci ? » —, posée à chaque lecture.
 *
 * ## Pas de table de plus
 *
 * Une colonne et une valeur d'énumération : `SchemaSynchroniseTest::NOMBRE_DE_TABLES` et
 * `LongueurDesEnumerationsTest` ne bougent pas.
 */
final class Version20261001170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'dmr_demarche : statut « expediee » (expédiée par courrier) et lien « suite de » (precedente_id).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE dmr_demarche DROP CONSTRAINT ck_dmr_demarche_statut');
        $this->addSql("ALTER TABLE dmr_demarche ADD CONSTRAINT ck_dmr_demarche_statut CHECK (statut IN ('envoyee', 'relancee', 'expediee', 'recue', 'refusee', 'sans_suite'))");

        $this->addSql('ALTER TABLE dmr_demarche ADD precedente_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE dmr_demarche ADD CONSTRAINT FK_12DA7749562D9ABA FOREIGN KEY (precedente_id) REFERENCES dmr_demarche (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_12DA7749562D9ABA ON dmr_demarche (precedente_id)');
        // Une démarche ne se reprend pas elle-même.
        $this->addSql('ALTER TABLE dmr_demarche ADD CONSTRAINT ck_dmr_demarche_precedente CHECK (precedente_id IS NULL OR precedente_id <> id)');
    }

    public function down(Schema $schema): void
    {
        // Le lien « suite de » est perdu ; les démarches, elles, restent. Une démarche
        // expédiée redevient « envoyée » : c'est la valeur sûre — elle attend toujours.
        $this->addSql('ALTER TABLE dmr_demarche DROP CONSTRAINT ck_dmr_demarche_precedente');
        $this->addSql('ALTER TABLE dmr_demarche DROP CONSTRAINT FK_12DA7749562D9ABA');
        $this->addSql('DROP INDEX IDX_12DA7749562D9ABA');
        $this->addSql('ALTER TABLE dmr_demarche DROP precedente_id');

        $this->addSql("UPDATE dmr_demarche SET statut = 'envoyee' WHERE statut = 'expediee'");
        $this->addSql('ALTER TABLE dmr_demarche DROP CONSTRAINT ck_dmr_demarche_statut');
        $this->addSql("ALTER TABLE dmr_demarche ADD CONSTRAINT ck_dmr_demarche_statut CHECK (statut IN ('envoyee', 'relancee', 'recue', 'refusee', 'sans_suite'))");
    }
}
