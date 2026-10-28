<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `prp_change_request.idempotency_key` et `idempotency_fingerprint` — un dépôt rejoué ne
 * crée pas de doublon (ADR 0022).
 *
 * ## Le besoin
 *
 * `POST /api/demandes` accepte un en-tête facultatif `Idempotency-Key`. Un client qui
 * n'a pas reçu la réponse (coupure réseau, délai dépassé) renvoie la même requête avec la
 * même clé et doit retrouver la demande déjà déposée, au lieu d'en créer une seconde. Le
 * serveur a donc besoin de se souvenir, par compte, de la clé sous laquelle chaque demande
 * est entrée, et de ce que contenait le corps : une clé réutilisée avec un autre corps est
 * une erreur du client, pas un rejeu.
 *
 * ## Les objets posés, et ce que chacun attrape
 *
 * - **`idempotency_key`** (`VARCHAR(128)`, nulle) : la clé choisie par le client. Nulle pour
 *   tout dépôt sans l'en-tête, ce que fait le front. Le format (16 à 128 caractères
 *   `[A-Za-z0-9_-]`) est contrôlé par le service avant la base ;
 * - **`idempotency_fingerprint`** (`VARCHAR(64)`, nulle) : le sha256 hexadécimal du corps
 *   canonique. C'est lui qui distingue le rejeu fidèle (`200`) du rejeu au corps différent
 *   (`422 idempotence_corps_different`) ;
 * - **l'index unique partiel** `uniq_prp_request_idempotence` sur
 *   (`author_user_id`, `idempotency_key`) `WHERE (idempotency_key IS NOT NULL)` : c'est lui,
 *   et non une recherche suivie d'une écriture, qui tranche la course entre deux requêtes
 *   simultanées de même clé. Partiel, parce que la plupart des demandes n'ont pas de clé.
 *   Le prédicat est écrit sans `::` : c'est la forme que Doctrine produit depuis le
 *   mapping, et `SchemaSynchroniseTest` compare l'instruction entière (même recette que
 *   `uniq_mbr_autorisation_active`, `Version20261003090000`) ;
 * - **`ck_prp_request_idempotence`** : la clé et l'empreinte vont ensemble. Une clé sans
 *   empreinte ferait confondre tout rejeu avec un corps identique ; une empreinte sans clé
 *   ne servirait à rien. Le service les pose d'un seul geste
 *   (`ChangeRequest::poserLaCleDIdempotence()`) ; la contrainte est là pour la console, les
 *   correctifs à chaud, et le jour où un second chemin d'écriture l'oublierait. Les tests
 *   tournent sur SQLite, qui ignore les `CHECK` : `IndexDIdempotencePostgresTest` la
 *   vérifie sur la base migrée.
 *
 * ## Rien à reprendre
 *
 * Aucune demande existante n'a été déposée sous une clé : les colonnes naissent vides, et
 * la contrainte est vraie sur toutes les lignes. `down()` perd les clés posées depuis, ce
 * qui ne coûte que la protection contre le rejeu des dépôts concernés.
 *
 * *Horodatage volontairement non rond : un `…120000` a déjà provoqué une collision de noms
 * de classe (voir `Version20260923153000`).*
 */
final class Version20261028151742 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'prp_change_request.idempotency_key et idempotency_fingerprint : un dépôt rejoué ne crée pas de doublon (ADR 0022).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE prp_change_request ADD idempotency_key VARCHAR(128) DEFAULT NULL');
        $this->addSql('ALTER TABLE prp_change_request ADD idempotency_fingerprint VARCHAR(64) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_prp_request_idempotence ON prp_change_request (author_user_id, idempotency_key) WHERE (idempotency_key IS NOT NULL)');
        $this->addSql(<<<'SQL'
            ALTER TABLE prp_change_request ADD CONSTRAINT ck_prp_request_idempotence
                CHECK ((idempotency_key IS NULL) = (idempotency_fingerprint IS NULL))
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE prp_change_request DROP CONSTRAINT ck_prp_request_idempotence');
        $this->addSql('DROP INDEX uniq_prp_request_idempotence');
        $this->addSql('ALTER TABLE prp_change_request DROP idempotency_fingerprint');
        $this->addSql('ALTER TABLE prp_change_request DROP idempotency_key');
    }
}
