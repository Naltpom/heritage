<?php

declare(strict_types=1);

namespace App\Tests\Schema;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Chaque colonne d'énumération est-elle assez large pour la plus longue de ses valeurs ?
 *
 * **Ce défaut passe à travers tous les contrôles du projet, et c'est pour ça que ce test
 * existe.** Une colonne `VARCHAR(12)` qui doit accueillir `'avertissement'` — treize
 * signes — ne se voit nulle part :
 *
 * - `doctrine:schema:validate` est **vert** : la colonne existe, son type et sa longueur
 *   correspondent exactement au mapping. Les deux disent la même chose ; ils ont tort
 *   ensemble.
 * - `phpstan` est **vert** : côté PHP, la propriété est bien typée `IssueSeverity` (du
 *   module `Gedcom`, supprimé depuis), et il n'y a rien à redire à une énumération.
 * - la migration **passe** : `VARCHAR(12)` est une déclaration parfaitement valide.
 *
 * Rien ne se manifeste **jusqu'au premier `INSERT` réel avec la valeur longue** —
 * PostgreSQL répond alors `SQLSTATE 22001, value too long for type character varying(12)`.
 * C'est-à-dire potentiellement en production, sur un cas rare, des mois plus tard.
 *
 * C'est exactement la catégorie de défaut qu'un test vaut la peine d'attraper : invisible
 * à l'analyse statique, déclenché par la donnée, et dont la probabilité **augmente avec le
 * temps** — on ajoute une valeur d'énumération au fil des fonctionnalités, et la nouvelle
 * est souvent plus longue que les anciennes. Les deux débordements trouvés à l'écriture
 * du schéma (`ged_issue.severity` — table supprimée depuis, ADR 0017 —, et
 * `mbr_member.match_kind`) ont tous deux cette forme : une valeur descriptive ajoutée
 * après que la largeur eut été fixée sur les courtes.
 *
 * Le balayage se fait **par réflexion sur le mapping Doctrine**, jamais sur une liste
 * écrite à la main : une liste vieillit, et elle vieillit en silence — c'est-à-dire
 * qu'elle laisserait passer précisément la colonne ajoutée demain.
 */
final class LongueurDesEnumerationsTest extends KernelTestCase
{
    /**
     * Garde-fou du garde-fou : **le nombre exact** de colonnes que le balayage doit voir.
     *
     * Un test qui découvre lui-même son jeu d'essai a un mode de défaillance qui lui est
     * propre : si la découverte casse, il reste **vert en ne vérifiant plus rien**. Celui-ci
     * garde les deux `VARCHAR` trop courts trouvés à l'import — il ne peut pas se permettre
     * de devenir muet en silence.
     *
     * **Un nombre exact, et non un plancher**, contrairement au recensement des frontières
     * inter-modules où un compte serait une mauvaise idée. La différence tient à deux
     * choses :
     *
     * - ce que ce test compte **ne bouge pas** — le schéma ne gagne une table que par
     *   décision écrite (voir {@see SchemaSynchroniseTest::NOMBRE_DE_TABLES}), et les
     *   énumérations qui montent dans `Core` changent de dossier sans changer le nombre de
     *   colonnes qui les portent ;
     * - un écart ici se **corrige en une ligne** (on met le nouveau chiffre, après avoir
     *   regardé pourquoi), là où un compte de violations serait **compensable** : une
     *   ajoutée ici, une retirée là, total inchangé, test aveugle.
     *
     * Quand ce nombre doit changer, c'est qu'on a ajouté ou retiré une colonne
     * d'énumération. Le mettre à jour est alors un geste délibéré, visible en revue — et
     * c'est exactement ce qu'on veut qu'il soit.
     *
     * **48 depuis le 2026-09-23** : `qst_question` en porte quatre — `nature`,
     * `sujet_kind`, `etat` et `redacteur` (ADR 0008). La plus longue valeur du lot est
     * `date_naissance_inconnue`, 23 signes, d'où le `VARCHAR(32)` de `nature` : c'est
     * exactement la forme de débordement que ce test existe pour attraper, une valeur
     * descriptive ajoutée à une énumération dont les premières étaient courtes.
     *
     * **50 depuis le 2026-09-27** : `trg_edition.statut` et `trg_presence.presence`
     * (module `Tirage`, ADR 0009), toutes deux en `VARCHAR(12)` pour une plus longue valeur
     * de dix signes (`a_distance`).
     *
     * **55 depuis le 2026-09-30** : le module `Demarche` en porte cinq — `nature`, `forme`
     * et `statut` sur `dmr_demarche`, `sens` et `canal` sur `dmr_echange`. La plus longue
     * valeur du lot est `extrait_sans_filiation`, 22 signes, d'où le `VARCHAR(24)` de
     * `forme`.
     *
     * **56 depuis le 2026-10-02** : `evt_invitation.reponse` (module `Evenement`), en
     * `VARCHAR(8)` pour une plus longue valeur de sept signes (`present`). Deux valeurs et
     * pas de troisième — pas de « peut-être », c'est la demande.
     *
     * **53 depuis le 2026-10-06** : `ged_run.direction`, `ged_run.status` et
     * `ged_issue.severity` partent avec les deux tables du module `Gedcom`, supprimé
     * (ADR 0017, `migrations/Version20261006100000.php`).
     */
    private const int COLONNES_ENUM_ATTENDUES = 53;

    public function testChaqueColonneAccueilleLaPlusLongueValeurDeSonEnumeration(): void
    {
        $colonnes = $this->colonnesDEnumeration();
        $debordements = [];

        foreach ($colonnes as $colonne) {
            if (null === $colonne['longueurDeclaree']) {
                // Énumération adossée à un entier (`CitationQuality`) : la colonne est un
                // SMALLINT, elle n'a pas de longueur et la question ne se pose pas.
                continue;
            }

            if ($colonne['longueurNecessaire'] > $colonne['longueurDeclaree']) {
                $debordements[] = \sprintf(
                    '%s.%s : VARCHAR(%d) trop court pour %s::%s = %s (%d signes) — passer à VARCHAR(%d) au moins',
                    $colonne['table'],
                    $colonne['colonne'],
                    $colonne['longueurDeclaree'],
                    $colonne['enumCourt'],
                    $colonne['casLePlusLong'],
                    var_export($colonne['valeurLaPlusLongue'], true),
                    $colonne['longueurNecessaire'],
                    $colonne['longueurNecessaire'],
                );
            }
        }

        self::assertSame(
            [],
            $debordements,
            \sprintf(
                "%d colonne(s) d'énumération ne peuvent pas accueillir leur plus longue valeur.\n"
                ."PostgreSQL refusera l'INSERT (SQLSTATE 22001) — et rien d'autre dans le projet ne le voit.\n"
                ."Corriger `length:` dans l'entité, la migration **et** `docs/modele-donnees.md`.\n\n  - %s",
                \count($debordements),
                implode("\n  - ", $debordements),
            ),
        );
    }

    /**
     * Le balayage voit-il bien tout le schéma ?
     *
     * Ce cas ne teste pas le schéma, il teste le test : si un jour la réflexion cesse de
     * traverser les blocs date incrustés ou les entités d'un module, le premier cas
     * deviendrait vert par cécité.
     */
    public function testLeBalayageCouvreToutesLesColonnesDEnumeration(): void
    {
        $colonnes = $this->colonnesDEnumeration();

        self::assertCount(
            self::COLONNES_ENUM_ATTENDUES,
            $colonnes,
            \sprintf(
                "%d colonnes d'énumération balayées, %d attendues.\n"
                ."Si c'est une colonne ajoutée ou retirée à dessein, mettre à jour la constante.\n"
                ."Si ce n'est pas le cas, la réflexion ne voit plus tout le schéma — et le premier\n"
                ."cas de ce fichier est alors vert **sans plus rien vérifier**.\n"
                ."Colonnes vues :\n  - %s",
                \count($colonnes),
                self::COLONNES_ENUM_ATTENDUES,
                implode("\n  - ", array_map(
                    static fn (array $c): string => $c['table'].'.'.$c['colonne'],
                    $colonnes,
                )),
            ),
        );

        // Les blocs date sont incrustés (`#[ORM\Embedded]`) : leurs colonnes n'appartiennent
        // à aucune entité en propre et sont le premier endroit qu'un balayage naïf manque.
        $qualificatifs = array_filter(
            $colonnes,
            static fn (array $c): bool => str_ends_with($c['colonne'], '_qualifier'),
        );

        self::assertGreaterThanOrEqual(
            8,
            \count($qualificatifs),
            'Les colonnes `*_qualifier` des blocs date incrustés ne sont pas vues par le balayage.',
        );
    }

    /**
     * L'inventaire lisible de ce que le balayage a trouvé, marge par marge.
     *
     * Muet par défaut — quarante-cinq lignes de tableau à chaque exécution de CI seraient
     * du bruit. Pour le lire, et notamment pour voir quelles colonnes n'ont plus de marge
     * du tout :
     *
     *     INVENTAIRE_ENUMS=1 php vendor/bin/phpunit --filter testInventaireDesColonnes
     *
     * Une marge nulle n'est pas une erreur : elle dit qu'une seule valeur d'énumération
     * plus longue, ajoutée un jour pour une nouvelle fonctionnalité, refermerait le même
     * piège. C'est le signal qu'il faut avoir sous les yeux au moment d'en ajouter une.
     */
    public function testInventaireDesColonnes(): void
    {
        $colonnes = $this->colonnesDEnumeration();
        self::assertNotEmpty($colonnes);

        if ('1' !== getenv('INVENTAIRE_ENUMS')) {
            return;
        }

        $serrees = 0;
        $lignes = [];

        foreach ($colonnes as $c) {
            $declaree = $c['longueurDeclaree'];
            $marge = null === $declaree ? '—' : (string) ($declaree - $c['longueurNecessaire']);

            if (null !== $declaree && $declaree - $c['longueurNecessaire'] <= 2) {
                ++$serrees;
                $marge .= ' <-- serrée';
            }

            $lignes[] = \sprintf(
                '%-34s %-22s %-6s %-6s %-22s %s',
                $c['table'].'.'.$c['colonne'],
                $c['enumCourt'],
                null === $declaree ? 'int' : (string) $declaree,
                (string) $c['longueurNecessaire'],
                (string) $c['valeurLaPlusLongue'],
                $marge,
            );
        }

        fwrite(\STDOUT, \sprintf(
            "\n%-34s %-22s %-6s %-6s %-22s %s\n%s\n%s\n\n%d colonnes `enumType` balayées, dont %d à marge de 2 signes ou moins.\n",
            'COLONNE', 'ÉNUMÉRATION', 'LARG.', 'BESOIN', 'VALEUR LA PLUS LONGUE', 'MARGE',
            str_repeat('-', 116),
            implode("\n", $lignes),
            \count($colonnes),
            $serrees,
        ));
    }

    /**
     * Toutes les colonnes déclarées avec `enumType`, avec la largeur qu'il leur faut.
     *
     * Les champs d'un `#[ORM\Embeddable]` sont repris ici sans effort : Doctrine les
     * incruste dans les métadonnées de l'entité porteuse, sous un nom pointé
     * (`birth.qualifier`) et avec leur vraie colonne (`birth_qualifier`). On saute donc
     * les métadonnées de l'embeddable lui-même, sans quoi on compterait des colonnes qui
     * n'existent dans aucune table.
     *
     * @return list<array{
     *     table: string,
     *     colonne: string,
     *     enumCourt: string,
     *     casLePlusLong: string,
     *     valeurLaPlusLongue: string|int,
     *     longueurNecessaire: int,
     *     longueurDeclaree: int|null
     * }>
     */
    private function colonnesDEnumeration(): array
    {
        $colonnes = [];

        foreach ($this->entityManager()->getMetadataFactory()->getAllMetadata() as $metadonnees) {
            if ($metadonnees->isEmbeddedClass || $metadonnees->isMappedSuperclass) {
                continue;
            }

            foreach ($metadonnees->fieldMappings as $mapping) {
                $enum = $mapping->enumType;
                if (null === $enum) {
                    continue;
                }

                self::assertTrue(
                    enum_exists($enum),
                    \sprintf('%s déclare `enumType: %s`, qui n\'est pas une énumération.', $metadonnees->getTableName(), $enum),
                );

                $casLePlusLong = null;
                $longueurNecessaire = 0;

                foreach ($enum::cases() as $cas) {
                    // `$cas->value` est forcément défini : Doctrine refuse une énumération
                    // pure dans `enumType`, il lui faut un type adossé.
                    $longueur = mb_strlen((string) $cas->value);
                    if ($longueur >= $longueurNecessaire) {
                        $longueurNecessaire = $longueur;
                        $casLePlusLong = $cas;
                    }
                }

                self::assertNotNull(
                    $casLePlusLong,
                    \sprintf('L\'énumération %s ne déclare aucun cas.', $enum),
                );

                $morceaux = explode('\\', $enum);

                $colonnes[] = [
                    'table' => $metadonnees->getTableName(),
                    'colonne' => $mapping->columnName,
                    'enumCourt' => end($morceaux),
                    'casLePlusLong' => $casLePlusLong->name,
                    'valeurLaPlusLongue' => $casLePlusLong->value,
                    'longueurNecessaire' => $longueurNecessaire,
                    'longueurDeclaree' => $mapping->length,
                ];
            }
        }

        usort($colonnes, static fn (array $a, array $b): int => [$a['table'], $a['colonne']] <=> [$b['table'], $b['colonne']]);

        return $colonnes;
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
}
