<?php

declare(strict_types=1);

namespace App\Tests\Tree;

use App\Module\Tree\Service\EtiquetteDeDateGedcom;
use App\Module\Tree\Service\JeuDePlacementDoctrine;
use App\Module\Tree\Service\MoteurDePlacement;
use App\Module\Tree\Service\ReglePersonneVivante;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * **Le dernier maillon** : le placement calculé sur la base que produit réellement
 * `heritage:import-gedcom`, et non sur une base reconstruite depuis `graphe.json`.
 *
 * Les trois tests de placement se répondent, et aucun ne remplace les autres :
 *
 * | Test | Alimenté par | Ce qu'il isole |
 * |---|---|---|
 * | `PlacementNonRegressionTest` | `graphe.json` | le **moteur** est un portage fidèle |
 * | `PlacementFournisseurDoctrineTest` | base reconstruite depuis `graphe.json` | le **fournisseur** lit et ordonne juste |
 * | **celui-ci** | base issue du **GEDCOM** | l'**import** écrit ce que le placement attend |
 *
 * Si les 377 nœuds retombent exacts ici, toute la chaîne serveur est prouvée — du
 * fichier GEDCOM jusqu'aux coordonnées du dessin. C'est le seul endroit où un écart
 * entre ce que l'import écrit et ce que `graphe.json` contient deviendrait visible : il
 * échapperait aux totaux de contrôle de l'import (qui comptent des lignes, pas des
 * largeurs) comme au test de non-régression (qui ne lit jamais la base).
 *
 * ## Lancer ce test
 *
 * Il lui faut `pdo_pgsql`, que le PHP de la machine ne charge pas par défaut :
 *
 * ```
 * php -d extension=pdo_pgsql vendor/bin/phpunit tests/Tree/PlacementBaseImporteeTest.php
 * ```
 *
 * La base visée est celle de `compose.yaml`. Pour en désigner une autre :
 * `HERITAGE_DSN_REEL=postgresql://user:pass@hote:5432/base`.
 *
 * **Lecture seule, du début à la fin.** Ce test n'écrit rien, ne crée aucun schéma et ne
 * vide aucune table : il se branche sur une base de travail et se contente de la lire.
 */
#[Group('donnees-reelles')]
final class PlacementBaseImporteeTest extends TestCase
{
    private const float TOLERANCE = 0.01;

    public const string VARIABLE_ENV = 'HERITAGE_DSN_REEL';

    /**
     * ── LES DEUX VALEURS QUI ONT QUITTÉ LE DÉPÔT, ET POURQUOI ──
     *
     * {@see testUneFourchetteTraverseLImportSansEtreAplatie} est le seul test de ce
     * fichier qui compare à des valeurs **nominatives** : une date de naissance réelle
     * et son année pivot. Les autres comparent des décomptes et des coordonnées, qui
     * ne désignent personne.
     *
     * Ces deux-là ne peuvent pas être substituées comme le reste : elles servent de
     * **témoin** sur la base réelle, et un témoin réécrit ne prouve plus rien. Elles
     * ne peuvent pas non plus rester écrites ici, puisque ce fichier part sur GitHub.
     * Elles sont donc lues dans l'environnement, comme {@see VARIABLE_ENV} l'est déjà.
     *
     * **La référence GEDCOM, elle, reste écrite en clair** (§ 9.1). Un xref est un
     * numéro opaque : il ne désignait quelqu'un qu'accolé à une date, et c'est la date
     * qui part. La sortir aussi aurait coûté une troisième variable à poser sur le
     * poste du commanditaire sans rien protéger de plus.
     *
     * Sur un poste sans la base réelle, rien ne change : le test se saute avant de les
     * demander. Sur le poste du commanditaire, leur absence est une **erreur franche**
     * et non un saut — un test conditionnel qui se tait est un test qu'on croit vert.
     */
    public const string VARIABLE_NAIS_FOURCHETTE = 'HERITAGE_NAIS_FOURCHETTE';
    public const string VARIABLE_AN_FOURCHETTE = 'HERITAGE_AN_FOURCHETTE';

    /** Celui de `compose.yaml`, port bindé sur la boucle locale. */
    private const string DSN_PAR_DEFAUT = 'postgresql://heritage:heritage_dev@127.0.0.1:5432/heritage';

    private ChargeurDeGrapheDeReference $reference;
    private EntityManagerInterface $em;

    /**
     * Xref littéral à dessein dans l'exemple : le brief demandait de renuméroter, la
     * consigne a été annulée (§ 9.1). Note complète sur {@see VARIABLE_NAIS_FOURCHETTE}.
     *
     * @var array<string, string> xref GEDCOM → identifiant de nœud (`@I500001@` → `p1`)
     */
    private array $noeudParXref = [];

    protected function setUp(): void
    {
        if (!ChargeurDeGrapheDeReference::disponible()) {
            self::markTestSkipped('Fichier de référence absent : '.ChargeurDeGrapheDeReference::chemin());
        }

        if (!\extension_loaded('pdo_pgsql')) {
            self::markTestSkipped('pdo_pgsql non chargé — relancer avec `php -d extension=pdo_pgsql`.');
        }

        $this->reference = ChargeurDeGrapheDeReference::charge();

        try {
            $this->em = FabriqueDEntityManager::versDsn(self::dsn());
            $personnes = (int) $this->em->getConnection()->fetchOne('SELECT count(*) FROM tree_person');
        } catch (\Throwable $e) {
            self::markTestSkipped(\sprintf('Base réelle injoignable (%s) : %s', self::dsn(), $e->getMessage()));
        }

        if (0 === $personnes) {
            self::markTestSkipped('Base réelle vide : lancer `bin/console heritage:import-gedcom` d\'abord.');
        }

        $this->noeudParXref = $this->correspondanceDesXrefs();
    }

    private static function dsn(): string
    {
        $configure = $_SERVER[self::VARIABLE_ENV] ?? $_ENV[self::VARIABLE_ENV] ?? null;

        return \is_string($configure) && '' !== $configure ? $configure : self::DSN_PAR_DEFAUT;
    }

    /** L'import doit avoir produit exactement l'arbre de référence. */
    public function testLaBaseImporteePorteLArbreAttendu(): void
    {
        $connexion = $this->em->getConnection();

        self::assertSame(274, (int) $connexion->fetchOne('SELECT count(*) FROM tree_person'));
        self::assertSame(103, (int) $connexion->fetchOne('SELECT count(*) FROM tree_family'));
        self::assertSame(174, (int) $connexion->fetchOne('SELECT count(*) FROM tree_child'));
    }

    /**
     * **Le critère** : les 377 nœuds au centième, calculés sur la base issue du GEDCOM.
     */
    public function testLes377NoeudsSontExactsDepuisLaBaseImportee(): void
    {
        $placement = (new MoteurDePlacement())->calcule($this->fournisseur()->jeuComplet());
        $attendu = $this->reference->positionsAttendues();

        $exacts = 0;
        $ecarts = [];

        foreach ($attendu as $xref => $cible) {
            $identifiant = $this->noeudParXref[$xref] ?? null;

            if (null === $identifiant) {
                $ecarts[] = \sprintf('%s : absent de la base importée', $xref);

                continue;
            }

            $noeud = $placement->noeud($identifiant);

            if (null === $noeud) {
                $ecarts[] = \sprintf('%s (%s) : absent du placement', $xref, $identifiant);

                continue;
            }

            $obtenu = ['x' => $noeud->x, 'y' => $noeud->y, 'w' => (float) $noeud->w, 'h' => (float) $noeud->h];
            $divergents = [];

            foreach ($cible as $axe => $valeur) {
                $delta = $obtenu[$axe] - $valeur;

                if (abs($delta) > self::TOLERANCE) {
                    $divergents[] = \sprintf('%s attendu %.4f, obtenu %.4f (écart %+.4f)', $axe, $valeur, $obtenu[$axe], $delta);
                }
            }

            if ([] === $divergents) {
                ++$exacts;
            } else {
                $ecarts[] = \sprintf('%s (%s) : %s', $xref, $identifiant, implode(' ; ', $divergents));
            }
        }

        echo \sprintf("\n  Placement — depuis la base importée : %d/%d nœuds exacts.\n", $exacts, \count($attendu));

        self::assertCount(377, $placement->noeuds, 'Le placement doit porter 377 nœuds.');
        self::assertSame(
            \count($attendu),
            $exacts,
            \sprintf(
                "%d nœud(s) divergent(s) :\n%s\n".
                "Les deux autres tests de placement étant exacts, l'écart est entre ce que l'import écrit et ".
                "ce que `graphe.json` contient — pas dans le moteur ni dans le fournisseur.\n".
                'Ne pas élargir la tolérance.',
                \count($ecarts),
                implode("\n", \array_slice($ecarts, 0, 30)),
            ),
        );
    }

    /**
     * La fourchette de Marne Dolomite Sureau est le cas d'école du § 9.1 : MyHeritage
     * y avait écrit son année de décès à la place de sa naissance, ce qui la faisait
     * naître après ses propres enfants et générait six incohérences en cascade.
     *
     * Elle doit traverser toute la chaîne sans être aplatie — et son étiquette compacte
     * doit mesurer 9 caractères, pas les 20 de la prose de `UncertainDate::format()`.
     *
     * Sa naissance et son année pivot sont lues dans l'environnement (voir
     * {@see VARIABLE_NAIS_FOURCHETTE}) : ce sont des données nominatives, elles ne
     * sont pas écrites dans un fichier versionné. La référence GEDCOM, elle, reste en
     * clair — privée de sa date, elle n'énonce plus rien (§ 9.1).
     */
    public function testUneFourchetteTraverseLImportSansEtreAplatie(): void
    {
        // **Littérale à dessein, et ce n'est pas un reste d'anonymisation** (§ 9.1).
        // Un xref est un numéro opaque : publié seul, il énonce « il existe un individu
        // numéroté 500017 dans un arbre » — pas un nom, pas une date, pas un lieu. Ce
        // qui le rendait bavard, c'était la date collée à côté, et cette date-là a
        // quitté le dépôt juste en dessous. Le brief d'origine demandait de renuméroter
        // les xrefs ; cette consigne a été annulée après coup. Ne pas la réappliquer.
        $xref = '@I500017@';
        $naisAttendue = self::valeurNominative(self::VARIABLE_NAIS_FOURCHETTE);
        $anAttendu = (int) self::valeurNominative(self::VARIABLE_AN_FOURCHETTE);

        $jeu = $this->fournisseur()->jeuComplet();
        $identifiant = $this->noeudParXref[$xref] ?? null;

        self::assertNotNull($identifiant, \sprintf('%s désigne une personne absente de la base importée.', $xref));

        $sureau = null;
        foreach ($jeu->personnes as $personne) {
            if ($personne->id === $identifiant) {
                $sureau = $personne;
                break;
            }
        }

        self::assertNotNull($sureau);
        self::assertSame($naisAttendue, $sureau->nais, 'La fourchette reste une fourchette, écrite court.');
        self::assertSame($anAttendu, $sureau->anNais, 'La borne basse ordonne la fratrie.');

        // **La largeur du nœud est la conséquence mesurable de ce choix**, et c'est elle
        // qui rend l'erreur détectable. Son étiquette de moteur est `1901-1909 – 1987`,
        // 16 signes :
        //     max(104, max(len('Marne Sureau') × 6.6, 16 × 5.8) + 28)
        //   = max(104, max(79.2, 92.8) + 28) = 120        ← tronqué, jamais arrondi
        //
        // Mesurée sur la **prose** de `UncertainDate::format()` —
        // `entre 1901 et 1909 – 1987`, 25 signes — la même fiche ferait **173**. C'est
        // l'écart qu'une rédaction du contrat d'API a commis une fois : 53 px sur un seul
        // nœud, et autant de décalage sur toute sa rangée.
        //
        // Les millésimes ci-dessus sont ceux de la table d'anonymisation, pas ceux de la
        // base. Ils comptent le **même nombre de caractères** que les vrais — c'est toute
        // la raison d'être de cette table —, donc l'arithmétique se relit telle quelle et
        // la valeur attendue reste 120.
        $noeud = (new MoteurDePlacement())->calcule($jeu)->noeud($identifiant);

        self::assertNotNull($noeud);
        self::assertSame(120, $noeud->w, 'La largeur se mesure sur l\'étiquette compacte (120), jamais sur la prose (173).');
    }

    /**
     * Une valeur nominative attendue, lue dans l'environnement.
     *
     * On est déjà passé par {@see setUp} : la base réelle répond. Une variable manquante
     * est donc une erreur de configuration du poste, pas une raison de sauter — sauter
     * ici rendrait le test vert sur la seule machine où il a quelque chose à prouver.
     */
    private static function valeurNominative(string $variable): string
    {
        $valeur = $_SERVER[$variable] ?? $_ENV[$variable] ?? null;

        $manquante = \sprintf(
            '%s absente : la base réelle est présente mais la valeur attendue a quitté '.
            'le dépôt (anonymisation). Pose-la dans api/.env.test.local — PAS dans '.
            'api/.env.local, que Dotenv::bootEnv() ignore délibérément quand APP_ENV=test, '.
            'et phpunit.dist.xml force APP_ENV=test. Même remarque pour %s.',
            $variable,
            self::VARIABLE_ENV,
        );

        self::assertIsString($valeur, $manquante);
        self::assertNotSame('', $valeur, $manquante);

        return $valeur;
    }

    /**
     * L'ordre rendu par le fournisseur doit être celui du GEDCOM. C'est `ORDER BY id` qui
     * le porte, et il ne tient que parce que l'import crée les lignes dans l'ordre du
     * fichier — une propriété de l'import, pas du fournisseur, donc à vérifier ici.
     */
    public function testLOrdreDesLignesEstCeluiDuFichierGedcom(): void
    {
        $jeu = $this->fournisseur()->jeuComplet();
        $xrefParNoeud = array_flip($this->noeudParXref);

        $rendus = array_map(static fn ($p): string => $xrefParNoeud[$p->id] ?? '?', $jeu->personnes);
        $attendus = array_map(static fn ($p): string => $p->id, $this->reference->jeu()->personnes);

        self::assertSame($attendus, $rendus, 'Les personnes doivent sortir dans l\'ordre du GEDCOM.');

        $famillesRendues = array_map(static fn ($f): string => $xrefParNoeud[$f->id] ?? '?', $jeu->familles);
        $famillesAttendues = array_map(static fn ($f): string => $f->id, $this->reference->jeu()->familles);

        self::assertSame($famillesAttendues, $famillesRendues, 'Les familles aussi.');
    }

    /**
     * La règle « personne vivante » appliquée à la base importée doit retomber sur les
     * 18 `RESN privacy` du GEDCOM. Si elle échouait ici alors qu'elle réussit sur la base
     * reconstruite, c'est que l'import n'écrit pas `deceased` ou `birth_max` comme
     * `graphe.json` les décrit — et les largeurs suivraient.
     */
    public function testLaRegleVivanteRetombeSurLes18(): void
    {
        $jeu = $this->fournisseur()->jeuComplet();
        $xrefParNoeud = array_flip($this->noeudParXref);

        $calcules = [];
        foreach ($jeu->personnes as $personne) {
            if ($personne->vivant) {
                $calcules[] = $xrefParNoeud[$personne->id] ?? '?';
            }
        }

        $attendus = [];
        foreach ($this->reference->jeu()->personnes as $personne) {
            if ($personne->vivant) {
                $attendus[] = $personne->id;
            }
        }

        sort($calcules);
        sort($attendus);

        self::assertCount(18, $attendus);
        self::assertSame($attendus, $calcules);
    }

    private function fournisseur(): JeuDePlacementDoctrine
    {
        $connexion = $this->em->getConnection();

        // Les réglages réels, lus en SQL plutôt que par `SettingService` : celui-ci
        // demande un dépôt Doctrine, l'`EntityManager` et le service d'audit, c'est-à-dire
        // le conteneur entier — pour deux entiers. C'est exactement ce que l'interface
        // `ReglagesDeLArbre` existe pour éviter.
        $seuil = $connexion->fetchOne("SELECT value FROM core_setting WHERE key = 'tree.seuil_vivant_annees'");
        $racine = $connexion->fetchOne("SELECT value FROM core_setting WHERE key = 'tree.personne_racine_id'");

        return new JeuDePlacementDoctrine(
            $this->em,
            new EtiquetteDeDateGedcom(),
            new ReglePersonneVivante(new EtiquetteDeDateGedcom()),
            new ReglagesFixes(
                false === $seuil ? ReglePersonneVivante::SEUIL_DEFAUT : (int) $seuil,
                false === $racine ? null : (int) $racine,
            ),
        );
    }

    /**
     * `@I500001@` → `p1`, `@F500001@` → `f28`.
     *
     * Xrefs littéraux à dessein dans ces exemples : le brief demandait de les renuméroter,
     * la consigne a été annulée (§ 9.1). Note complète sur {@see VARIABLE_NAIS_FOURCHETTE}.
     *
     * C'est `gedcom_xref` qui fait le pont, et c'est pour ça qu'il est conservé en base
     * (§ 9.8) : sans lui, aucun moyen de rapprocher une ligne importée de la référence.
     *
     * @return array<string, string>
     */
    private function correspondanceDesXrefs(): array
    {
        $connexion = $this->em->getConnection();
        $carte = [];

        foreach ($connexion->fetchAllAssociative('SELECT id, gedcom_xref FROM tree_person WHERE gedcom_xref IS NOT NULL') as $ligne) {
            $carte[(string) $ligne['gedcom_xref']] = 'p'.$ligne['id'];
        }

        foreach ($connexion->fetchAllAssociative('SELECT id, gedcom_xref FROM tree_family WHERE gedcom_xref IS NOT NULL') as $ligne) {
            $carte[(string) $ligne['gedcom_xref']] = 'f'.$ligne['id'];
        }

        return $carte;
    }
}
