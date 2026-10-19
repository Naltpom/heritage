<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * **Retrait du module `Tirage`** (tirages au sort récurrents, ADR 0009, remplacé) : ses cinq
 * tables `trg_*` partent, données comprises — décision du propriétaire. Les tirages de
 * Noël (`noel_*`, site noel2026) les remplacent, et heritage les montre dans « Mes
 * événements » par `GET /api/noel/tirages`.
 *
 * ## Les notifications d'abord
 *
 * `core_notification.kind` se lit par `NotificationKind::from()` (colonne `enumType`) : une
 * ligne `tirage_effectue` restée en base ferait tomber l'hydratation de toute la liste de
 * son destinataire, maintenant que le cas n'existe plus. Elles ne nommaient personne et ne
 * menaient qu'à des routes disparues : rien à garder.
 *
 * ## L'ordre des `DROP`
 *
 * Des feuilles vers la racine — `trg_attribution` et `trg_presence` pendent à
 * `trg_edition`, qui pend avec `trg_participant` à `trg_tirage` — pour que chaque `DROP`
 * réussisse sans `CASCADE`. Un `CASCADE` emporterait en silence une clé étrangère qu'un
 * autre module aurait posée sur ces tables ; sans lui, une telle clé ferait échouer la
 * migration, et c'est ce qu'on veut savoir. `IF EXISTS` n'y est pas non plus : une base où
 * ces tables manqueraient n'est pas celle qu'on croit migrer.
 *
 * Les lignes d'audit qui citent un `Tirage\…` restent : le journal est un historique, et
 * son `subject_type` n'est qu'une chaîne.
 */
final class Version20261020090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Retrait du module Tirage : notifications tirage_effectue supprimées, tables trg_* supprimées (ADR 0009 remplacé).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("DELETE FROM core_notification WHERE kind = 'tirage_effectue'");
        $this->addSql('DROP TABLE trg_attribution');
        $this->addSql('DROP TABLE trg_presence');
        $this->addSql('DROP TABLE trg_edition');
        $this->addSql('DROP TABLE trg_participant');
        $this->addSql('DROP TABLE trg_tirage');
    }

    /**
     * Irréversible, comme toute reprise qui détruit des données : recréer des tables vides
     * rendrait un schéma sans ses lignes, et sans le code qui les lit. Revenir en arrière,
     * c'est restaurer la sauvegarde d'avant la livraison.
     */
    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException(
            'Les tirages au sort récurrents et leurs notifications sont supprimés avec leurs données : seule une sauvegarde les rend.',
        );
    }
}
