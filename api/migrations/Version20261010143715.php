<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `prp_change_request.complement_de_id` — une demande qui **complète** la proposition d'un
 * autre membre (ADR 0017, décision du commanditaire du 09/10/2026).
 *
 * ## Le besoin
 *
 * Un membre tape le nom de la personne qu'il veut ajouter, et la ressemblance (§ 6.6 quater
 * du contrat) lui apprend qu'une proposition semblable attend déjà la validation — celle
 * d'un autre membre. Il ne peut pas la modifier : elle n'est pas à lui. Il ne doit pas
 * non plus déposer une seconde création de la même personne : c'est le doublon que la file
 * d'administration passerait ensuite à écarter. Il dépose donc un **complément** : une
 * `creation_personne` ordinaire, une fiche et au moins un lien, qui dit « je complète la
 * fiche 7 ». L'administrateur la **fusionne** dans l'origine en un clic.
 *
 * ## Pourquoi le pointeur vise une fiche, pas une demande
 *
 * Une création peut porter plusieurs fiches (une branche de vingt-deux personnes en une
 * demande). « Complète la demande n° 12 » ne dirait pas laquelle ; la fiche, si, et la
 * demande s'en déduit par la jointure. D'où la clé étrangère vers `prp_draft_person` — la
 * seule de `prp_change_request` qui ne soit pas une auto-référence.
 *
 * ## Les trois objets posés, et ce que chacun attrape
 *
 * - **la clé étrangère**, `ON DELETE SET NULL` : intra-module, donc permise (ADR 0002,
 *   règle 2). Une fiche ne disparaît qu'avec sa demande, ce qui n'arrive jamais (ADR 0004) ;
 *   si cela arrivait, le complément redeviendrait une création autonome — l'état qu'il
 *   prend déjà quand son origine est refusée, retirée ou caduque. `CASCADE` emporterait la
 *   contribution d'un autre membre avec une demande qui n'est pas la sienne ;
 * - **l'index** `idx_prp_request_complement` : « quels compléments attendent sur cette
 *   proposition ? » est posé à chaque ouverture de la vue d'administration de l'origine, et
 *   par le contrôle préalable qui en bloque la validation. Il est nommé dans le mapping,
 *   ce qui dispense Doctrine de poser le sien (`IDX_…` haché) sur la colonne de jointure ;
 * - **`ck_prp_request_complement`** : seule une `creation_personne` complète une
 *   proposition. Un `ajout_lien` ou une `modification_personne` qui porterait ce pointeur
 *   ne voudrait rien dire, et la fusion ne saurait pas quoi en verser. Le service le refuse
 *   en `422` avant la base (les tests tournent sur SQLite, qui ignore les `CHECK`) ; la
 *   contrainte est là pour la console, les correctifs à chaud, et le jour où un second
 *   chemin d'écriture oublierait la règle.
 *
 * ## Rien à reprendre
 *
 * Aucune demande existante n'est un complément : la colonne naît vide, et la contrainte est
 * vraie sur toutes les lignes. La migration est réversible sans perte tant qu'aucun
 * complément n'a été déposé ; après, `down()` en fait des créations ordinaires — ce qu'elles
 * deviennent de toute façon quand leur origine est tranchée.
 *
 * *Horodatage volontairement non rond : un `…120000` a déjà provoqué une collision de noms
 * de classe (voir `Version20260923153000`).*
 */
final class Version20261010143715 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute prp_change_request.complement_de_id : le complément d\'une proposition en attente (ADR 0017).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE prp_change_request ADD complement_de_id INT DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_prp_request_complement ON prp_change_request (complement_de_id)');
        // Le nom est celui que Doctrine dérive de la table et de la colonne : le reprendre
        // tel quel évite qu'un `doctrine:schema:validate` sur la base de production annonce
        // une clé à renommer. Même geste que pour `reprise_de_id` (`Version20260915193611`).
        $this->addSql('ALTER TABLE prp_change_request ADD CONSTRAINT FK_1A7C5F68CB5117D FOREIGN KEY (complement_de_id) REFERENCES prp_draft_person (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql(<<<'SQL'
            ALTER TABLE prp_change_request ADD CONSTRAINT ck_prp_request_complement
                CHECK (complement_de_id IS NULL OR kind = 'creation_personne')
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE prp_change_request DROP CONSTRAINT ck_prp_request_complement');
        $this->addSql('ALTER TABLE prp_change_request DROP CONSTRAINT FK_1A7C5F68CB5117D');
        $this->addSql('DROP INDEX idx_prp_request_complement');
        $this->addSql('ALTER TABLE prp_change_request DROP complement_de_id');
    }
}
