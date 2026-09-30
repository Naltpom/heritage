<?php

declare(strict_types=1);

namespace App\Tests\Schema;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\ORM\Tools\SchemaValidator;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Le schéma Doctrine et la migration initiale disent-ils la même chose ?
 *
 * **Ce que ce test attrape, et pourquoi il existe.** Une entité à laquelle on ajoute une
 * colonne sans régénérer la migration ne casse rien tout de suite : le développeur qui
 * l'a écrite travaille sur une base déjà migrée à la main, tout marche chez lui, et la
 * divergence ne se révèle qu'au prochain déploiement sur base vierge — c'est-à-dire le
 * jour où on en a le moins envie. Ce test la fait tomber tout de suite.
 *
 * **Pourquoi il ne se contente pas d'appeler `doctrine:schema:validate`.** Cette commande
 * a besoin d'une base **PostgreSQL migrée**. Or les tests tournent sur SQLite et la CI ne
 * monte aucun service de base de données (voir `api/.env.test` et `.github/workflows/ci.yml`).
 * La comparaison se fait donc **sans serveur PostgreSQL** : on demande à Doctrine le DDL
 * PostgreSQL qu'il produirait aujourd'hui depuis le mapping — en rendant un objet
 * `Schema` indépendant de toute plateforme avec `PostgreSQLPlatform` —, on rejoue le
 * `up()` de la migration pour en récupérer les instructions, et on vérifie que les
 * premières sont toutes dans les secondes.
 *
 * L'inclusion est volontairement **à sens unique** : la migration contient légitimement
 * plus que le mapping — contraintes `CHECK`, classes d'opérateurs, sens de tri,
 * `NULLS NOT DISTINCT`, réglages initiaux — qui sont justement ce que Doctrine ne sait
 * pas exprimer. Le sens inverse est couvert autrement, en comparant les **tables** créées
 * de part et d'autre : une table qui traîne dans la migration après la suppression de son
 * entité est une divergence tout aussi réelle.
 *
 * Le contrôle complet, avec une vraie base, reste :
 *
 *     php bin/console doctrine:migrations:migrate --no-interaction
 *     php bin/console doctrine:schema:validate
 */
final class SchemaSynchroniseTest extends KernelTestCase
{
    /**
     * Le § 1 du modèle de données en prévoyait 23, et le § 11 les énumère dans leur ordre
     * de création. Un écart est soit un oubli, soit une décision — dans les deux cas il
     * doit se voir ici avant de se voir en production.
     *
     * **24 depuis le 2026-09-17** : `tree_place` (module `Tree`,
     * `migrations/Version20260917143000.php`) porte la résolution des chaînes `PLAC` en
     * coordonnées. C'est une **décision**, et elle est écrite en entier dans l'en-tête de
     * {@see \App\Module\Tree\Entity\Place} : la table de correspondance existe précisément
     * pour qu'aucune colonne ne s'ajoute aux cinq tables qui portent déjà un lieu — dont
     * deux sont versionnées, et dont une réécriture périmerait les demandes en attente.
     *
     * **25 depuis le 2026-09-18** : `core_email_change` (module `Core`,
     * `migrations/Version20260918150000.php`) porte la demande de changement de courriel
     * entre le moment où elle est faite et le clic sur le lien reçu à la nouvelle
     * adresse. C'est une **décision** : le courriel est l'identifiant du compte, et le
     * remplacer sans preuve de possession de la nouvelle adresse permettrait à une
     * session volée d'enfermer le titulaire dehors. Une table à part, et non trois
     * colonnes sur `core_user`, pour ne pas mêler à l'identité une donnée qui vit
     * vingt-quatre heures — l'en-tête de la migration le dit en entier.
     *
     * **27 depuis le 2026-09-23** : `qst_question` et `qst_question_demande` (module
     * `Question`, `migrations/Version20260923153000.php`) portent la page « Questions »
     * de l'ADR 0008. Ce sont des **décisions**, et elles se lisent dans l'en-tête de la
     * migration : la première n'enregistre pas les questions — qui naissent d'une
     * détection rejouée sur l'arbre — mais **la mémoire de ce qu'on en a fait**, sans
     * laquelle la même question revient indéfiniment ; la seconde est une table et non
     * une colonne parce qu'une seule réponse peut produire **deux** demandes — le lieu
     * en note et la photo de l'acte en média —, que l'administrateur doit pouvoir
     * trancher séparément.
     *
     * **32 depuis le 2026-09-27** : les cinq tables `trg_*` du module `Tirage`
     * (`migrations/Version20260927120000.php`, ADR 0009) — le tirage récurrent, ses
     * participants, chaque édition annuelle, les présences et les attributions. Ce sont des
     * **décisions** : les présences ont leur table parce qu'elles changent d'une année sur
     * l'autre, et les attributions parce qu'elles sont à la fois le secret de l'année et la
     * mémoire des suivantes.
     *
     * **35 depuis le 2026-09-30** : les trois tables `dmr_*` du module `Demarche`
     * (`migrations/Version20260930140000.php`) — les actes demandés à une mairie ou à des
     * archives, les personnes qu'ils concernent, et chaque message avec son texte entier.
     * Ce sont des **décisions** : les personnes ont leur table parce qu'un acte de mariage
     * en concerne deux et que c'est d'elles que dépend qui voit la démarche ; les messages,
     * parce qu'une démarche en compte plusieurs — la demande, une relance, la réponse.
     */
    private const int NOMBRE_DE_TABLES = 35;

    public function testLeMappingEstValide(): void
    {
        $erreurs = (new SchemaValidator($this->entityManager()))->validateMapping();

        self::assertSame(
            [],
            $erreurs,
            "Le mapping Doctrine comporte des erreurs :\n".print_r($erreurs, true),
        );
    }

    public function testLeSchemaCompteLesVingtTroisTablesDuModele(): void
    {
        $tables = $this->tablesDuMapping();

        self::assertCount(
            self::NOMBRE_DE_TABLES,
            $tables,
            \sprintf(
                "Le modèle de données prévoit %d tables, le mapping en déclare %d :\n%s",
                self::NOMBRE_DE_TABLES,
                \count($tables),
                implode(', ', $tables),
            ),
        );
    }

    /**
     * Chaque table porte bien le préfixe de son module (§ 0.1 du modèle de données).
     *
     * Le préfixe n'est pas décoratif : il permet de lire un plan d'exécution ou un
     * `pg_dump` et de savoir immédiatement quel module est en cause.
     */
    public function testChaqueTablePorteLePrefixeDeSonModule(): void
    {
        $prefixes = ['core_', 'tree_', 'mbr_', 'prp_', 'med_', 'tml_', 'ged_', 'qst_', 'trg_', 'dmr_'];

        foreach ($this->tablesDuMapping() as $table) {
            $porteUnPrefixe = array_filter(
                $prefixes,
                static fn (string $prefixe): bool => str_starts_with($table, $prefixe),
            );

            self::assertNotEmpty(
                $porteUnPrefixe,
                \sprintf('La table « %s » ne porte aucun des huit préfixes de module.', $table),
            );
        }
    }

    /**
     * Les **colonnes** que le mapping déclare sont-elles toutes créées par l'historique
     * des migrations, avec la même définition ?
     *
     * **Colonne par colonne, et non instruction par instruction** — la première rédaction
     * de ce test comparait les `CREATE TABLE` du mapping à ceux des migrations, ce qui ne
     * vaut que tant qu'il n'y a **qu'une seule migration**. Dès la deuxième, une colonne
     * arrive par `ALTER TABLE … ADD` et le `CREATE TABLE` d'origine ne la porte plus :
     * le test tombait sur un schéma pourtant juste. Un test qui n'accepte pas l'historique
     * interdit d'en avoir un — c'est-à-dire qu'il pousse exactement vers la faute qu'il
     * devait empêcher, retoucher une migration déjà jouée.
     *
     * On rejoue donc l'historique : `CREATE TABLE` pose des colonnes, `ALTER TABLE … ADD`
     * en ajoute, `DROP` en retire, et l'état final se compare à ce que le mapping demande.
     */
    public function testLesMigrationsCreentToutesLesColonnesDuMapping(): void
    {
        $attendu = $this->colonnesParTable($this->ddlPostgreSqlDepuisLeMapping());
        $obtenu = $this->colonnesParTable($this->sqlDesMigrations());

        $ecarts = [];

        foreach ($attendu as $table => $colonnes) {
            foreach ($colonnes as $colonne => $definition) {
                $definitionMigree = $obtenu[$table][$colonne] ?? null;

                if (null === $definitionMigree) {
                    $ecarts[] = \sprintf(
                        "ABSENTE de l'historique des migrations — %s.%s\n  le mapping demande : %s",
                        $table,
                        $colonne,
                        $definition,
                    );
                    continue;
                }

                if ($definitionMigree !== $definition) {
                    $ecarts[] = \sprintf(
                        "DIVERGENTE — %s.%s\n  mapping    : %s\n  migrations : %s",
                        $table,
                        $colonne,
                        $definition,
                        $definitionMigree,
                    );
                }
            }
        }

        self::assertSame(
            [],
            $ecarts,
            "Le mapping déclare des colonnes que l'historique des migrations ne produit pas.\n"
            ."**Ne pas corriger en éditant une migration déjà jouée** : une version inscrite dans\n"
            ."`doctrine_migration_versions` ne se rejoue jamais, et la colonne n'apparaîtrait que\n"
            ."sur les bases créées à neuf. Créer une migration de plus :\n"
            ."    php bin/console doctrine:migrations:diff\n\n"
            .implode("\n\n", $ecarts),
        );
    }

    /**
     * Les index et contraintes du mapping sont-ils tous posés par les migrations ?
     *
     * Séparé du contrôle de colonnes parce qu'il se compare autrement : un index est une
     * instruction entière, qu'on retrouve telle quelle ou pas du tout.
     */
    public function testLesMigrationsPosentTousLesIndexDuMapping(): void
    {
        $interessantes = static fn (string $sql): bool => 1 === preg_match('/^CREATE (?:UNIQUE )?INDEX /i', $sql);

        $attendu = array_filter($this->ddlPostgreSqlDepuisLeMapping(), $interessantes);
        $ecritDansLesMigrations = $this->sqlDesMigrations();

        $ecarts = [];

        foreach (array_diff($attendu, $ecritDansLesMigrations) as $attendue) {
            $ecarts[] = $this->expliquerLEcart($attendue, $ecritDansLesMigrations);
        }

        self::assertSame(
            [],
            $ecarts,
            "Le mapping déclare des index que les migrations ne posent pas à l'identique.\n\n"
            .implode("\n\n", $ecarts),
        );
    }

    /**
     * Rejoue un historique d'instructions et rend l'état final des colonnes, par table.
     *
     * Cinq formes, parce que ce sont celles que Doctrine produit :
     * `CREATE TABLE t (col def, …, PRIMARY KEY (…))`, `ALTER TABLE t ADD col def`,
     * `ALTER TABLE t DROP col`, `ALTER TABLE t ALTER col TYPE x` et
     * `ALTER TABLE t ALTER col DROP|SET NOT NULL`. Tout le reste — index,
     * contraintes, données — est ignoré ici et couvert ailleurs.
     *
     * ⚠ **La quatrième manquait, et son absence rendait ce test faux dans un sens qu'on ne
     * soupçonne pas en le lisant.** Il ne devenait pas permissif : il devenait
     * **infranchissable**. Une colonne qui change de type — `VARCHAR(2)` élargi en
     * `VARCHAR(3)` pour les quatre quarts du conjoint — se déclare dans le mapping et
     * s'applique par une migration que cette relecture ignorait. Le test annonçait alors
     * « DIVERGENTE » pour un schéma parfaitement juste, **et son propre message d'échec
     * conseillait `doctrine:migrations:diff`, c'est-à-dire exactement la migration qui
     * venait d'être écrite.** Le seul moyen de le faire taire était de ne jamais changer le
     * type d'une colonne, ou de retoucher une migration déjà jouée — la faute même que ce
     * fichier existe pour empêcher.
     *
     * C'est le reproche que le docbloc voisin adresse à la première rédaction du test,
     * revenu par une autre porte : *« un test qui n'accepte pas l'historique interdit d'en
     * avoir un »*. Il valait pour l'ajout d'une colonne ; il vaut identiquement pour sa
     * modification.
     *
     * @param list<string> $instructions
     *
     * @return array<string, array<string, string>> table → colonne → définition
     */
    private function colonnesParTable(array $instructions): array
    {
        $tables = [];

        foreach ($instructions as $sql) {
            if (1 === preg_match('/^CREATE TABLE ([a-z0-9_]+) \((.*)\)$/is', $sql, $t)) {
                $tables[$t[1]] = [];
                foreach ($this->decouperAuPremierNiveau($t[2]) as $morceau) {
                    // `PRIMARY KEY (id)` et `CONSTRAINT …` ne sont pas des colonnes.
                    if (1 === preg_match('/^(PRIMARY KEY|CONSTRAINT|UNIQUE|FOREIGN KEY|CHECK)\b/i', $morceau)) {
                        continue;
                    }
                    [$nom, $definition] = $this->nomEtDefinition($morceau);
                    $tables[$t[1]][$nom] = $definition;
                }
                continue;
            }

            if (1 === preg_match('/^ALTER TABLE ([a-z0-9_]+) ADD (?!CONSTRAINT\b)(.+)$/i', $sql, $t)) {
                [$nom, $definition] = $this->nomEtDefinition($t[2]);
                $tables[$t[1]][$nom] = $definition;
                continue;
            }

            if (1 === preg_match('/^ALTER TABLE ([a-z0-9_]+) DROP (?!CONSTRAINT\b)([a-z0-9_]+)$/i', $sql, $t)) {
                unset($tables[$t[1]][$t[2]]);
                continue;
            }

            if (1 === preg_match('/^ALTER TABLE ([a-z0-9_]+) ALTER ([a-z0-9_]+) TYPE (.+)$/i', $sql, $t)) {
                // Une colonne que l'historique n'a pas encore créée ne se modifie pas : on
                // laisse le trou, et la comparaison le signalera comme une absence — ce
                // qu'il est réellement.
                if (isset($tables[$t[1]][$t[2]])) {
                    $tables[$t[1]][$t[2]] = $this->typeRemplace($tables[$t[1]][$t[2]], trim($t[3]));
                }
                continue;
            }

            // La nullité seule — `mbr_invitation.expires_at` (liens sans échéance) l'a
            // rencontrée la première. Même règle qu'au-dessus pour le trou.
            if (1 === preg_match('/^ALTER TABLE ([a-z0-9_]+) ALTER ([a-z0-9_]+) (DROP|SET) NOT NULL$/i', $sql, $t)) {
                if (isset($tables[$t[1]][$t[2]])) {
                    $tables[$t[1]][$t[2]] = $this->nulliteRemplacee($tables[$t[1]][$t[2]], 'DROP' === strtoupper($t[3]));
                }
            }
        }

        return $tables;
    }

    /**
     * Pose un nouveau type devant ce qui suivait l'ancien : `VARCHAR(2) DEFAULT NULL` et
     * `VARCHAR(3)` donnent `VARCHAR(3) DEFAULT NULL`.
     *
     * **Le découpage se fait sur ce qui SUIT le type, jamais sur le type lui-même**, et
     * c'est le seul point délicat de cette fonction. Un type SQL n'a pas de longueur
     * prévisible en mots : `VARCHAR(2)` en fait un, `DOUBLE PRECISION` deux,
     * `TIMESTAMP(0) WITH TIME ZONE` quatre. Le chercher par la gauche demanderait de
     * connaître la liste des types ; les quatre mots-clés qui peuvent le suivre dans un DDL
     * Doctrine, eux, se comptent sur une main.
     *
     * Un `ALTER … TYPE` ne dit rien de la nullité ni du défaut : PostgreSQL les conserve,
     * et cette fonction fait de même. Une migration qui changerait les deux d'un coup émet
     * deux instructions, et c'est la seconde qui porterait le `SET DEFAULT` — forme qu'on
     * n'a pas encore rencontrée et qu'il faudra ajouter ici le jour où elle arrivera.
     */
    private function typeRemplace(string $definition, string $type): string
    {
        if (1 === preg_match('/\s(?:DEFAULT|NOT NULL|NULL|CHECK)\b.*$/is', $definition, $suite)) {
            return $type.$suite[0];
        }

        return $type;
    }

    /**
     * `X NOT NULL` ⇄ `X DEFAULT NULL`, dans la forme exacte où Doctrine écrit une colonne
     * nullable sans défaut. Un défaut explicite (`DEFAULT 0`) est conservé tel quel.
     */
    private function nulliteRemplacee(string $definition, bool $nullable): string
    {
        $base = trim((string) preg_replace('/\s+(?:NOT NULL|DEFAULT NULL)\b/i', '', $definition));

        if ($nullable) {
            return 1 === preg_match('/\sDEFAULT\s/i', ' '.$base.' ') ? $base : $base.' DEFAULT NULL';
        }

        return $base.' NOT NULL';
    }

    /**
     * Sépare `email VARCHAR(180) NOT NULL` en son nom et le reste.
     *
     * @return array{string, string}
     */
    private function nomEtDefinition(string $morceau): array
    {
        $morceau = trim($morceau);
        $espace = strpos($morceau, ' ');

        if (false === $espace) {
            return [$morceau, ''];
        }

        return [substr($morceau, 0, $espace), substr($morceau, $espace + 1)];
    }

    /**
     * Découpe sur les virgules **de premier niveau**.
     *
     * `VARCHAR(180)`, `TIMESTAMP(0) WITH TIME ZONE` et `PRIMARY KEY (a, b)` contiennent des
     * virgules entre parenthèses : un `explode(',')` naïf couperait au milieu d'un type.
     *
     * @return list<string>
     */
    private function decouperAuPremierNiveau(string $corps): array
    {
        $morceaux = [];
        $courant = '';
        $profondeur = 0;

        foreach (str_split($corps) as $caractere) {
            if ('(' === $caractere) {
                ++$profondeur;
            } elseif (')' === $caractere) {
                --$profondeur;
            }

            if (',' === $caractere && 0 === $profondeur) {
                $morceaux[] = trim($courant);
                $courant = '';
                continue;
            }

            $courant .= $caractere;
        }

        if ('' !== trim($courant)) {
            $morceaux[] = trim($courant);
        }

        return $morceaux;
    }

    /**
     * Dire **en quoi** une instruction diverge, et non seulement qu'elle diverge.
     *
     * Ce détour a été ajouté après coup, parce que le message qu'il remplace a fait perdre
     * une demi-heure : il se contentait de recopier l'instruction attendue sous le titre
     * « instructions manquantes ». Une table dont **une seule colonne** avait changé de
     * largeur s'affichait donc comme un `CREATE TABLE tree_x (…)` entier, et se lisait
     * comme une table absente de la migration — alors qu'elle y était, à un `VARCHAR(12)`
     * contre `VARCHAR(16)` près. Le diagnostic qu'on en tire est faux, et la correction
     * qu'il appelle (« rajouter la table manquante ») l'est aussi.
     *
     * On cherche donc d'abord une instruction de la migration qui porte sur **le même
     * objet** — même table, même index. Si on en trouve une, l'écart est une divergence et
     * on montre le mot qui change ; sinon, et seulement sinon, l'objet est réellement
     * absent.
     *
     * @param list<string> $ecritDansLaMigration
     */
    private function expliquerLEcart(string $attendue, array $ecritDansLaMigration): string
    {
        $objet = $this->objetVise($attendue);

        $correspondantes = array_values(array_filter(
            $ecritDansLaMigration,
            fn (string $instruction): bool => null !== $objet && $objet === $this->objetVise($instruction),
        ));

        if ([] === $correspondantes) {
            return \sprintf(
                "ABSENT de la migration — %s\n  attendu : %s",
                $objet ?? 'objet non identifié',
                $attendue,
            );
        }

        $trouvee = $correspondantes[0];
        [$motAttendu, $motTrouve] = $this->premierMotQuiDiffere($attendue, $trouvee);

        return \sprintf(
            "DIVERGENT (l'objet existe dans la migration, sa définition diffère) — %s\n"
            ."  mapping   : … %s …\n"
            ."  migration : … %s …\n"
            .'  attendu en entier : %s',
            $objet,
            $motAttendu,
            $motTrouve,
            $attendue,
        );
    }

    /**
     * L'objet de schéma que vise une instruction : `TABLE tree_person`, `INDEX idx_x`….
     *
     * C'est la clé de rapprochement entre les deux côtés. Elle ignore volontairement le
     * corps de l'instruction, qui est justement ce qu'on veut comparer ensuite.
     */
    private function objetVise(string $instruction): ?string
    {
        $motifs = [
            '/^CREATE TABLE ([a-z0-9_]+) /i' => 'TABLE',
            '/^CREATE (?:UNIQUE )?INDEX ([a-z0-9_]+) /i' => 'INDEX',
            '/^ALTER TABLE ([a-z0-9_]+) ADD CONSTRAINT ([a-z0-9_]+) /i' => 'CONTRAINTE',
        ];

        foreach ($motifs as $motif => $nature) {
            if (1 === preg_match($motif, $instruction, $trouve)) {
                return $nature.' '.($trouve[2] ?? $trouve[1]);
            }
        }

        return null;
    }

    /**
     * Les premiers mots qui divergent entre deux instructions, avec ce qui les entoure.
     *
     * Un `CREATE TABLE` fait couramment deux mille signes ; montrer les deux en entier
     * laisse le lecteur chercher la différence à l'œil. On lui donne l'endroit.
     *
     * @return array{string, string}
     */
    private function premierMotQuiDiffere(string $attendue, string $trouvee): array
    {
        $motsAttendus = explode(' ', $attendue);
        $motsTrouves = explode(' ', $trouvee);
        $contexte = 4;

        foreach ($motsAttendus as $i => $mot) {
            if (($motsTrouves[$i] ?? null) === $mot) {
                continue;
            }

            $debut = max(0, $i - $contexte);

            return [
                implode(' ', \array_slice($motsAttendus, $debut, $contexte * 2 + 1)),
                implode(' ', \array_slice($motsTrouves, $debut, $contexte * 2 + 1)),
            ];
        }

        // Même préfixe, longueurs différentes : la divergence est à la fin.
        return [
            implode(' ', \array_slice($motsAttendus, -($contexte * 2 + 1))),
            implode(' ', \array_slice($motsTrouves, -($contexte * 2 + 1))),
        ];
    }

    /**
     * Le sens inverse : une table créée par la migration doit correspondre à une entité.
     *
     * C'est le cas qu'une simple inclusion laisserait passer — supprimer une entité sans
     * toucher à la migration, ou l'inverse.
     */
    public function testLaMigrationNeCreeQueDesTablesQuiOntUneEntite(): void
    {
        $tablesDeLaMigration = [];

        foreach ($this->sqlDesMigrations() as $instruction) {
            if (1 === preg_match('/^CREATE TABLE ([a-z0-9_]+) /i', $instruction, $trouve)) {
                $tablesDeLaMigration[] = $trouve[1];
            }
        }

        sort($tablesDeLaMigration);
        $tablesDuMapping = $this->tablesDuMapping();

        self::assertSame(
            $tablesDuMapping,
            $tablesDeLaMigration,
            'Les tables créées par les migrations ne correspondent pas aux entités déclarées.',
        );
    }

    private function entityManager(): EntityManagerInterface
    {
        self::bootKernel();

        $registry = self::getContainer()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $registry);

        $em = $registry->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }

    /**
     * Les noms de table déclarés par le mapping, triés.
     *
     * @return list<string>
     */
    private function tablesDuMapping(): array
    {
        $tables = [];

        foreach ($this->entityManager()->getMetadataFactory()->getAllMetadata() as $metadonnees) {
            if ($metadonnees->isMappedSuperclass || $metadonnees->isEmbeddedClass) {
                continue;
            }

            $tables[] = $metadonnees->getTableName();
        }

        sort($tables);

        return array_values(array_unique($tables));
    }

    /**
     * Le DDL PostgreSQL que le mapping produirait aujourd'hui, **sans serveur
     * PostgreSQL**.
     *
     * En deux temps, et la séparation est ce qui rend l'opération possible hors ligne :
     * `SchemaTool::getSchemaFromMetadata()` construit un objet `Schema` **indépendant de
     * toute plateforme** — des tables, des colonnes, des types Doctrine — puis
     * `Schema::toSql()` le rend dans le dialecte qu'on lui donne. Seule la première
     * étape touche une connexion (pour lire la longueur maximale d'un identifiant et le
     * schéma courant), et c'est celle du noyau de test : SQLite, un fichier local, ce que
     * la CI a déjà. Le rendu, lui, se fait avec `PostgreSQLPlatform`, sans rien ouvrir.
     *
     * Tenter l'inverse — un gestionnaire d'entités branché sur une connexion PostgreSQL
     * factice — échoue : `createSchemaConfig()` interroge réellement le serveur.
     *
     * @return list<string>
     */
    private function ddlPostgreSqlDepuisLeMapping(): array
    {
        $em = $this->entityManager();
        $outil = new SchemaTool($em);

        $schema = $outil->getSchemaFromMetadata($em->getMetadataFactory()->getAllMetadata());

        return array_map($this->normaliser(...), $schema->toSql(new PostgreSQLPlatform()));
    }

    /**
     * Les instructions du `up()` de toutes les migrations du dossier, dans l'ordre.
     *
     * On rejoue le `up()` plutôt que d'analyser le fichier à la recherche de chaînes :
     * `AbstractMigration::addSql()` les accumule, `getSql()` les rend, et un `addSql()`
     * écrit en `heredoc` ou construit par une boucle est compté comme les autres.
     *
     * @return list<string>
     */
    private function sqlDesMigrations(): array
    {
        $connexionFactice = DriverManager::getConnection([
            'driver' => 'pdo_pgsql',
            'host' => 'localhost',
            'dbname' => 'heritage',
            'user' => 'heritage',
            'password' => 'heritage',
            'serverVersion' => '17',
        ]);

        $instructions = [];

        foreach ($this->fichiersDeMigration() as $fichier) {
            $classe = 'DoctrineMigrations\\'.basename($fichier, '.php');

            if (!class_exists($classe)) {
                require_once $fichier;
            }

            self::assertTrue(
                class_exists($classe),
                \sprintf('Le fichier %s ne déclare pas la classe %s attendue.', $fichier, $classe),
            );

            $migration = new $classe($connexionFactice, new NullLogger());
            self::assertInstanceOf(AbstractMigration::class, $migration);

            $migration->up(new Schema());

            foreach ($migration->getSql() as $requete) {
                $instructions[] = $this->normaliser($requete->getStatement());
            }
        }

        self::assertNotEmpty($instructions, 'Aucune migration trouvée dans api/migrations.');

        return $instructions;
    }

    /** @return list<string> */
    private function fichiersDeMigration(): array
    {
        $dossier = \dirname(__DIR__, 2).'/migrations';
        $fichiers = glob($dossier.'/Version*.php');

        self::assertIsArray($fichiers);
        sort($fichiers);

        return $fichiers;
    }

    /**
     * Met les deux côtés sur le même pied : les espaces, les retours à la ligne et
     * l'indentation d'un `heredoc` ne sont pas une différence de schéma.
     */
    private function normaliser(string $sql): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $sql));
    }
}
