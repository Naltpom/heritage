<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `tree_person.branch` passe de `VARCHAR(2)` à `VARCHAR(3)` : les quatre quarts du
 * conjoint ne tiennent pas en deux caractères.
 *
 * ## Pourquoi la colonne s'élargit, et pourquoi si peu
 *
 * La spécification à treize blocs du commanditaire découpe la parenté de son conjoint
 * comme la sienne : `pp'`, `pm'`, `mp'`, `mm'`. L'apostrophe ne passe ni dans une valeur
 * stockée ni dans un nom de classe CSS ; elle devient le préfixe `c`, et les quatre
 * valeurs deviennent `cpp`, `cpm`, `cmp`, `cmm` — **trois** caractères.
 *
 * ## Les deux encodages à deux caractères qui ont été écartés, et pourquoi
 *
 * Ils auraient évité cette migration, ce qui n'est pas rien. Aucun ne tient :
 *
 * - **Les majuscules** (`PP`, `PM`, `MP`, `MM` pour le conjoint) entrent dans
 *   `VARCHAR(2)` et ne coûtent rien en base. Elles coûtent ailleurs : une valeur
 *   servie devient un nom de classe CSS (`b-PP` contre `b-pp`), un membre d'union
 *   TypeScript et une clé de `switch`. La casse y est significative à chaque fois,
 *   et **une seule faute de casse sur l'une des quatre ne casse rien — elle range
 *   silencieusement la fiche dans l'autre région.** Un encodage dont la seule
 *   défense est la vigilance n'en est pas un.
 * - **Deux lettres mnémoniques** quelconques. Les quatre valeurs portent trois
 *   informations : le côté du conjoint (père ou mère), le grand-parent (père ou mère),
 *   et le fait d'être du côté du conjoint. Deux lettres en portent deux. L'encodage
 *   existe — `pp`/`pm`/`mp`/`mm` — et il est déjà pris par le lecteur.
 *
 * ## Ce que cette migration ne fait pas
 *
 * **Elle ne réécrit aucune ligne.** Élargir un `VARCHAR` en PostgreSQL ne réécrit pas la
 * table et ne prend pas de verrou long : c'est un changement de contrainte de type, pas
 * de représentation. L'index `idx_tree_person_placement` est reconstruit par le moteur.
 *
 * **Et elle ne remplit pas la colonne.** `branch` est **dérivée** : elle vaut la vue de la
 * personne racine, et c'est `RecalculDesColonnesDerivees` qui l'écrit après toute écriture
 * structurante. Une racine sans conjoint — le cas d'aujourd'hui — n'y posera jamais aucune
 * des quatre valeurs nouvelles. La colonne s'élargit pour le jour où elle en aura un, et
 * pour que ce jour-là rien ne casse à l'insertion.
 *
 * ## Le retour en arrière n'est pas gratuit, et c'est écrit exprès
 *
 * `down()` rétrécit la colonne. **PostgreSQL refusera si une seule ligne porte déjà une
 * valeur de trois caractères** — ce n'est pas un défaut de cette migration, c'est la seule
 * réponse honnête : rétrécir en silence voudrait dire tronquer `cpp` en `cp`, donc inventer
 * une région. Vider la colonne d'abord, et la faire recalculer.
 */
final class Version20260923120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'tree_person.branch passe à VARCHAR(3) : les quatre quarts du conjoint (cpp, cpm, cmp, cmm).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tree_person ALTER branch TYPE VARCHAR(3)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tree_person ALTER branch TYPE VARCHAR(2)');
    }
}
