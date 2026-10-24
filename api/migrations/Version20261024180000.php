<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * **La signature des affiches** (ADR 0019) : le réglage `tree.signature_affiches`, le nom écrit
 * au pied des affiches généalogiques — « Arbre établi par … avec Heritage ».
 *
 * Un réglage et non une constante du front : le dépôt ne porte aucun nom réel (ADR 0006), et
 * c'est l'administration qui sait qui a établi l'arbre. Il naît **vide** : le pied dit alors
 * « Arbre établi avec Heritage », comme avant. L'écran des réglages rend toutes les lignes de
 * `core_setting` : il apparaît là sans rien d'autre à écrire.
 */
final class Version20261024180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Le réglage tree.signature_affiches : le nom au pied des affiches (ADR 0019).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            INSERT INTO core_setting ("key", value, value_type, label, description, min_value, max_value, updated_at)
            VALUES
              ('tree.signature_affiches', '', 'texte',
               'Signature des affiches',
               'Le nom écrit au pied des affiches généalogiques : « Arbre établi par … avec Heritage ». Vide : « Arbre établi avec Heritage ».',
               NULL, NULL, now())
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            DELETE FROM core_setting WHERE "key" = 'tree.signature_affiches'
            SQL);
    }
}
