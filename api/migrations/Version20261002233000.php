<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Deux demandes du commanditaire sur les événements de famille (ADR 0014, addendum du
 * 2 octobre 2026) :
 *
 * 1. **Noter la réponse d'un invité à sa place** — « grand-mère a répondu par téléphone ».
 *    `evt_invitation` gagne qui l'a notée : `reponse_notee_par_user_id` (⇢ `core_user`) et
 *    son nom dénormalisé `reponse_notee_par_libelle`, pour l'affichage discret « noté par
 *    Nathan ». Les deux nuls quand l'invité a répondu lui-même.
 * 2. **Changer les dates prévient les invités** — `evt_evenement.dates_modifiees_le`, le
 *    dernier déplacement. Une réponse antérieure reste, mais devient « à confirmer ».
 *
 * Deux `CHECK`, pour qu'une écriture qui oublierait la règle tombe en base :
 *
 * - `ck_evt_invitation_notee_par` — un compte et son nom vont ensemble ;
 * - `ck_evt_invitation_notee_avec_reponse` — on ne note pas « rien » : une trace « notée
 *   par » suppose une réponse.
 *
 * Les lignes existantes ne bougent pas : toutes les colonnes naissent nulles, ce qui est
 * exactement leur sens (réponses données par les invités eux-mêmes, dates d'origine).
 */
final class Version20261002233000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'evt_* : réponse notée par l’organisateur, et date du dernier changement de dates (ADR 0014, addendum).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE evt_evenement ADD dates_modifiees_le TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');

        $this->addSql('ALTER TABLE evt_invitation ADD reponse_notee_par_user_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE evt_invitation ADD reponse_notee_par_libelle VARCHAR(180) DEFAULT NULL');
        $this->addSql(<<<'SQL'
            ALTER TABLE evt_invitation
              ADD CONSTRAINT ck_evt_invitation_notee_par
                CHECK ((reponse_notee_par_user_id IS NULL) = (reponse_notee_par_libelle IS NULL)),
              ADD CONSTRAINT ck_evt_invitation_notee_avec_reponse
                CHECK (reponse_notee_par_user_id IS NULL OR reponse IS NOT NULL)
            SQL);
    }

    public function down(Schema $schema): void
    {
        // Ce qui est perdu : qui avait noté quelles réponses, et la trace du dernier
        // déplacement (les réponses à confirmer redeviennent des réponses tout court).
        $this->addSql('ALTER TABLE evt_invitation DROP CONSTRAINT ck_evt_invitation_notee_avec_reponse');
        $this->addSql('ALTER TABLE evt_invitation DROP CONSTRAINT ck_evt_invitation_notee_par');
        $this->addSql('ALTER TABLE evt_invitation DROP reponse_notee_par_libelle');
        $this->addSql('ALTER TABLE evt_invitation DROP reponse_notee_par_user_id');
        $this->addSql('ALTER TABLE evt_evenement DROP dates_modifiees_le');
    }
}
