<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `ck_prp_request_soumission` admet une demande `annulee` sans `submitted_at` : retirer
 * son brouillon ne rend plus une `500`.
 *
 * ## Le défaut
 *
 * `POST /api/demandes/{id}/retirer` accepte toute demande qui n'est pas tranchée, brouillon
 * compris (§ 6 du contrat d'API). Mais la contrainte posée par `Version20260915170602`
 * exigeait une date de soumission dès que le statut n'est plus `brouillon` : un brouillon
 * jamais soumis passait à `annulee` avec `submitted_at` nul, et PostgreSQL refusait l'écriture
 * (incident e2e50cf19cabf322, 28/10/2026, sur la demande 7932). Les tests tournent sur
 * SQLite, qui ignore les `CHECK` : rien ne l'avait montré.
 *
 * ## Pourquoi assouplir la contrainte, et non le retrait
 *
 * - **Poser une date de soumission au retrait** mentirait : la demande n'a jamais été
 *   soumise, et les statistiques la compteraient parmi les dépôts du mois ;
 * - **refuser le retrait d'un brouillon** laisserait le membre sans aucun moyen de s'en
 *   défaire, puisqu'une demande n'est jamais supprimée (ADR 0004) ;
 * - **supprimer le brouillon** contredirait la même ADR.
 *
 * Ce que la contrainte protégeait tient toujours : une demande `en_attente` ou tranchée par
 * un administrateur a une date de soumission. C'est elle que lisent la file, triée par
 * `submittedAt`, et le calque, qui ne contient que des demandes `en_attente`.
 *
 * ## Rien à reprendre
 *
 * La nouvelle contrainte est plus large que l'ancienne : toute ligne existante la respecte.
 * `down()` rétablit l'ancienne, et échoue tant qu'un brouillon retiré reste en base. C'est
 * voulu : on ne recule pas en effaçant l'historique.
 *
 * *Horodatage volontairement non rond : un `…120000` a déjà provoqué une collision de noms
 * de classe (voir `Version20260923153000`).*
 */
final class Version20261028183417 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'ck_prp_request_soumission : une demande annulée peut ne jamais avoir été soumise (retrait d\'un brouillon).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE prp_change_request DROP CONSTRAINT ck_prp_request_soumission');
        $this->addSql(<<<'SQL'
            ALTER TABLE prp_change_request ADD CONSTRAINT ck_prp_request_soumission
                CHECK (status IN ('brouillon', 'annulee') OR submitted_at IS NOT NULL)
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE prp_change_request DROP CONSTRAINT ck_prp_request_soumission');
        $this->addSql(<<<'SQL'
            ALTER TABLE prp_change_request ADD CONSTRAINT ck_prp_request_soumission
                CHECK (status = 'brouillon' OR submitted_at IS NOT NULL)
            SQL);
    }
}
