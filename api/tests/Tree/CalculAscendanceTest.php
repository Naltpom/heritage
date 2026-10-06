<?php

declare(strict_types=1);

namespace App\Tests\Tree;

use App\Module\Core\Enum\Branch;
use App\Module\Tree\Service\CalculAscendance;
use App\Module\Tree\ValueObject\FamillePlacable;
use PHPUnit\Framework\TestCase;

/**
 * Les garde-fous du calcul des colonnes dérivées, sur des arbres construits à la main.
 *
 * Le cas nominal — 12 générations et `pm 134, mm 64, mp 51, pp 20, tr 5` — est celui de
 * la base réelle, et `PlacementFournisseurDoctrineTest` le rejoue depuis `graphe.json`. Ce
 * qui est vérifié ici, ce sont les situations que le fichier de référence ne contient pas
 * mais qu'une donnée **saisie dans l'application** peut produire : c'est désormais un cas
 * courant, puisqu'une proposition validée écrit dans l'arbre.
 */
final class CalculAscendanceTest extends TestCase
{
    /**
     * **Le garde-fou contre les boucles.** Une personne déclarée son propre ancêtre est
     * une donnée absurde, mais elle est saisissable : une proposition de lien validée à
     * l'envers suffit. Sans `$vus`, la remontée ne termine pas et la validation part en
     * boucle infinie au lieu d'échouer.
     */
    public function testUnCycleDAscendanceNeFaitPasBoucler(): void
    {
        // A est enfant de B, B est enfant de A : le cycle le plus court possible.
        $placement = (new CalculAscendance())->calculer(
            ['pA', 'pB'],
            [
                $this->famille('f1', ['pB'], ['pA']),
                $this->famille('f2', ['pA'], ['pB']),
            ],
            'pA',
        );

        // La contrainte « un enfant est sous ses parents » ne peut pas être satisfaite des
        // deux côtés à la fois : on sort au bout des six passages plutôt que de boucler.
        // Un arbre absurde ne rend pas un résultat juste, il rend un résultat — et l'ego
        // reste à 0, parce que le ré-ancrage final le lui garantit.
        self::assertSame(0, $placement->generations['pA']);
        self::assertIsInt($placement->generations['pB']);
    }

    /** Une personne qu'aucun lien ne relie à l'ego se pose au rang 0 plutôt que de manquer. */
    public function testUnePersonneIsoleeRecoitQuandMemeUneGeneration(): void
    {
        $placement = (new CalculAscendance())->calculer(
            ['pA', 'pSeul'],
            [$this->famille('f1', ['pA'], [])],
            'pA',
        );

        self::assertSame(0, $placement->generations['pSeul']);
        self::assertArrayNotHasKey('pSeul', $placement->branches, 'Sans lien, aucune branche ne peut être héritée.');
    }

    /**
     * **Sans racine, on ne pose aucune branche.** C'est l'état que laisse la migration
     * tant qu'aucune racine n'a été choisie : `tree.personne_racine_id` vaut 0, et
     * `JeuDePlacement::$egoId` est alors la chaîne vide. « Branche paternelle du père » ne
     * désigne rien — la colonne reste `NULL`, ce qu'elle sait faire.
     */
    public function testSansRacineAucuneBrancheNEstPosee(): void
    {
        $placement = (new CalculAscendance())->calculer(
            ['pA', 'pB'],
            [$this->famille('f1', ['pB'], ['pA'])],
            '',
        );

        self::assertSame([], $placement->branches);

        // Les générations, elles, restent **relatives et cohérentes** : un enfant est
        // toujours sous son parent. Elles ne sont simplement ancrées sur personne, faute
        // de racine — et c'est pour ça que `RecalculDesColonnesDerivees` ne les persiste
        // pas dans ce cas plutôt que de poser des rangs qui ne veulent rien dire.
        self::assertGreaterThan($placement->generations['pB'], $placement->generations['pA']);
    }

    /**
     * Un couple partage son rang, et l'alignement se fait **sur le plus ancien** des deux.
     *
     * Sans cela, une femme mariée à un homme d'une génération au-dessus tirerait ses
     * propres ancêtres d'un cran, et les deux moitiés du couple se retrouveraient sur deux
     * rangées du rendu.
     */
    public function testUnCoupleDeRangsDifferentsSAligneSurLePlusAncien(): void
    {
        // pTante arrive au rang de pEgo par sa filiation, et au rang de pPere par son
        // mariage : c'est la boucle de cohérence qui tranche.
        $placement = (new CalculAscendance())->calculer(
            ['pEgo', 'pPere', 'pTante', 'pAieul'],
            [
                $this->famille('f1', ['pPere', 'pTante'], ['pEgo']),
                $this->famille('f2', ['pAieul'], ['pPere', 'pTante']),
            ],
            'pEgo',
        );

        self::assertSame($placement->generations['pPere'], $placement->generations['pTante']);
        self::assertGreaterThan($placement->generations['pPere'], $placement->generations['pEgo']);
    }

    /** `rang = 2 × génération + 1` : les personnes aux rangs pairs, les familles aux impairs. */
    public function testLesRangsDeFamilleSontEntrelaces(): void
    {
        $placement = (new CalculAscendance())->calculer(
            ['pEgo', 'pPere', 'pMere'],
            [$this->famille('f1', ['pPere', 'pMere'], ['pEgo'])],
            'pEgo',
        );

        self::assertSame(-1, $placement->generationsFamille['f1']);
        self::assertSame(-1, $placement->rangs['f1']);
    }

    /**
     * Le tronc écrase les quatre branches : l'ego, ses parents et sa fratrie sont le point
     * où elles se rejoignent.
     */
    public function testLeTroncRecouvreLesQuatreBranches(): void
    {
        $placement = (new CalculAscendance())->calculer(
            ['pEgo', 'pSoeur', 'pPere', 'pMere', 'pGpp', 'pGpm'],
            [
                $this->famille('f1', ['pPere', 'pMere'], ['pEgo', 'pSoeur']),
                $this->famille('f2', ['pGpp', 'pGpm'], ['pPere']),
            ],
            'pEgo',
        );

        self::assertSame(Branch::Tr, $placement->branches['pEgo']);
        self::assertSame(Branch::Tr, $placement->branches['pSoeur']);
        self::assertSame(Branch::Tr, $placement->branches['pPere']);
        self::assertSame(Branch::Tr, $placement->branches['pMere']);
        self::assertSame(Branch::Pp, $placement->branches['pGpp']);
        self::assertSame(Branch::Pm, $placement->branches['pGpm']);
    }

    /**
     * **LE SANG DU LECTEUR PASSE AVANT L'ALLIANCE**, et c'est ce qui reste de la garde
     * `Branch::Tr !== $branche[$voisin]` dans la boucle d'héritage.
     *
     * ⚠ **CE TEST S'APPELAIT « LE TRONC NE SE PROPAGE PAS PAR ALLIANCE », ET CE N'EST PLUS
     * VRAI.** Le commanditaire a arbitré le 23/09/2026 que *« tous doivent forcément être
     * dans un des 13 blocs ; si c'est les conjoints d'une personne de ma famille, ils vont
     * avec leur partenaire »*. Le tronc se propage donc bien par alliance, et il grossit
     * beaucoup — voir {@see CalculAscendance::parAlliance()}, qui porte les mesures.
     *
     * Ce que la garde fait encore, et qui vaut d'être tenu : elle **ordonne**. Les
     * beaux-parents du lecteur ne sont pas happés par le tronc parce que l'ascendance de
     * son conjoint est réclamée avant, par les quatre quarts. Sans cet ordre, ils
     * partiraient au tronc — et les blocs `cpp`/`cmp` se videraient sans que rien ne le
     * signale, le dessin restant parfaitement cohérent.
     *
     * L'arbre : l'ego, son épouse, et les parents de son épouse.
     */
    public function testLeSangEtLAscendanceDuConjointPassentAvantLAlliance(): void
    {
        $placement = (new CalculAscendance())->calculer(
            ['pEgo', 'pPere', 'pMere', 'pEpouse', 'pBeauPere', 'pBelleMere'],
            [
                $this->famille('f1', ['pPere', 'pMere'], ['pEgo']),
                $this->famille('f2', ['pBeauPere', 'pBelleMere'], ['pEpouse']),
                $this->famille('f3', ['pEgo', 'pEpouse'], []),
            ],
            'pEgo',
        );

        self::assertSame(Branch::Tr, $placement->branches['pEgo']);
        self::assertSame(Branch::Tr, $placement->branches['pPere']);
        self::assertSame(Branch::Tr, $placement->branches['pMere']);

        // L'épouse est au tronc : c'est le foyer du lecteur, et le moteur de placement l'y
        // pose déjà. Ses parents, eux, sont la moitié droite du dessin.
        self::assertSame(Branch::Tr, $placement->branches['pEpouse']);
        self::assertSame(Branch::Cpp, $placement->branches['pBeauPere']);
        self::assertSame(Branch::Cmp, $placement->branches['pBelleMere']);
    }

    /**
     * **QUI ENTRE PAR ALLIANCE PREND LE BLOC DE SON PARTENAIRE**, et sa famille suit.
     *
     * C'est la règle générale du commanditaire, et ce test en est le cas nominal. Le mari
     * de la sœur du lecteur n'a ni sang ni ascendance qui le relie aux treize blocs : il
     * prend celui de sa femme, le tronc. Puis ses parents suivent, parce qu'une
     * belle-famille est une famille entière et pas une personne.
     *
     * Avant cet arbitrage, ces trois-là recevaient `Branch::Bf`, une région à eux. Elle
     * n'existe plus : les treize blocs n'en prévoient pas, et leur en inventer un
     * quatorzième a été écarté explicitement.
     */
    public function testQuiEntreParAlliancePrendLeBlocDeSonPartenaire(): void
    {
        $placement = (new CalculAscendance())->calculer(
            ['pEgo', 'pPere', 'pMere', 'pSoeur', 'pMariDeLaSoeur', 'pSonPere', 'pSaMere'],
            [
                $this->famille('f1', ['pPere', 'pMere'], ['pEgo', 'pSoeur']),
                $this->famille('f2', ['pSonPere', 'pSaMere'], ['pMariDeLaSoeur']),
                $this->famille('f3', ['pMariDeLaSoeur', 'pSoeur'], []),
            ],
            'pEgo',
        );

        // Le partenaire est au tronc : il y va.
        self::assertSame(Branch::Tr, $placement->branches['pSoeur']);
        self::assertSame(Branch::Tr, $placement->branches['pMariDeLaSoeur']);
        // Puis sa famille, de proche en proche.
        self::assertSame(Branch::Tr, $placement->branches['pSonPere']);
        self::assertSame(Branch::Tr, $placement->branches['pSaMere']);
    }

    /**
     * **LES DEUX BELLES-FAMILLES NE VONT PAS AU MÊME ENDROIT**, et c'est ce qui distingue
     * la règle d'un simple fourre-tout.
     *
     * Celle du conjoint du lecteur a des blocs à elle — ses quatre quarts. Celle de sa
     * sœur n'en a pas : les treize n'en prévoient pas, et elle rejoint donc le bloc de son
     * partenaire, le tronc. Une seule passe, deux destinations, décidées par ce que les
     * quarts du conjoint ont réclamé avant.
     *
     * Ce test remplace `testLesQuartsDuConjointNeViderontPasLaBelleFamille`, qui tenait la
     * région `Branch::Bf`. Elle n'existe plus.
     */
    public function testLesDeuxBellesFamillesNeVontPasAuMemeBloc(): void
    {
        $placement = (new CalculAscendance())->calculer(
            [
                'pEgo', 'pPere', 'pMere', 'pSoeur',
                'pEpouse', 'pBeauPere', 'pBelleMere',
                'pMariDeLaSoeur', 'pSonPere', 'pSaMere',
            ],
            [
                $this->famille('f1', ['pPere', 'pMere'], ['pEgo', 'pSoeur']),
                $this->famille('f2', ['pBeauPere', 'pBelleMere'], ['pEpouse']),
                $this->famille('f3', ['pEgo', 'pEpouse'], []),
                $this->famille('f4', ['pSonPere', 'pSaMere'], ['pMariDeLaSoeur']),
                $this->famille('f5', ['pMariDeLaSoeur', 'pSoeur'], []),
            ],
            'pEgo',
        );

        // La moitié droite du dessin : la parenté de la conjointe du lecteur.
        self::assertSame(Branch::Cpp, $placement->branches['pBeauPere']);
        self::assertSame(Branch::Cmp, $placement->branches['pBelleMere']);

        // La belle-famille de la sœur n'a pas de bloc à elle : elle prend le sien.
        self::assertSame(Branch::Tr, $placement->branches['pMariDeLaSoeur']);
        self::assertSame(Branch::Tr, $placement->branches['pSonPere']);
        self::assertSame(Branch::Tr, $placement->branches['pSaMere']);
    }

    /**
     * **L'ALLIANCE NE DISPUTE RIEN AU SANG.** La passe qui donne à chacun le bloc de son
     * partenaire vient après l'héritage par le sang et ne touche que ce qui n'a pas de
     * bloc : un grand-parent reste dans son quart même s'il est marié à quelqu'un.
     */
    public function testLAllianceNeDisputeRienAuSang(): void
    {
        $placement = (new CalculAscendance())->calculer(
            ['pEgo', 'pPere', 'pMere', 'pGpp', 'pGpm'],
            [
                $this->famille('f1', ['pPere', 'pMere'], ['pEgo']),
                $this->famille('f2', ['pGpp', 'pGpm'], ['pPere']),
            ],
            'pEgo',
        );

        self::assertSame(Branch::Pp, $placement->branches['pGpp']);
        self::assertSame(Branch::Pm, $placement->branches['pGpm']);
    }

    /**
     * **L'INVARIANT : AUCUNE PERSONNE SANS BLOC, hors îlot qu'aucun chemin ne relie.**.
     *
     * *« Tous doivent forcément être dans un des 13 blocs »* — c'est une propriété du
     * produit, pas un effet de bord à constater, et elle se teste. Une personne sans
     * branche retombe sur le repli silencieux du sérialiseur et reçoit le quart
     * d'ascendance de **la racine** : « branche paternelle » désigne alors le côté
     * paternel de quelqu'un d'autre, et rien ne le signale.
     *
     * ⚠ **CE TEST A DÉJÀ MORDU.** La première rédaction de {@see
     * CalculAscendance::parAlliance()} plafonnait ses passes à huit, comme la boucle
     * d'héritage. Sur les 468 points de vue du graphe servi, **264 lecteurs gardaient de 4
     * à 33 personnes sans bloc alors qu'un chemin les reliait à eux** — l'invariant tombait
     * en silence. Il sort désormais sur un point fixe. L'arbre ci-dessous porte une chaîne
     * d'alliances de six maillons, qui ne tiendrait pas sous un plafond serré.
     */
    public function testAucunePersonneNeSortSansBlocHorsIlotDetache(): void
    {
        // Le tronc de départ : le lecteur et sa sœur.
        $familles = [$this->famille('f1', ['pPere', 'pMere'], ['pEgo', 'pSoeur'])];
        $chaine = [];

        // **Une chaîne de douze BEAUX-FRÈRES, et pas une descendance.** Chaque maillon
        // épouse le précédent, amène ses deux parents, et ces parents ont un autre enfant
        // qui épouse le maillon suivant. Rien ici n'est ni l'ascendance ni la descendance du
        // lecteur : le seul chemin qui y mène est une suite d'alliances.
        //
        // La première rédaction de ce cas chaînait des ENFANTS du lecteur. Elle ne prouvait
        // rien : {@see CalculAscendance::tronc()} sert toute la descendance d'emblée, si
        // bien que la chaîne entière était déjà posée avant la passe qu'on voulait éprouver.
        $ancre = 'pSoeur';

        for ($i = 1; $i <= 12; ++$i) {
            $conjoint = 'pAllie'.$i;
            $beauPere = 'pSonPere'.$i;
            $belleMere = 'pSaMere'.$i;
            $suivant = 'pSaSoeur'.$i;

            array_push($chaine, $conjoint, $beauPere, $belleMere, $suivant);
            $familles[] = $this->famille('fu'.$i, [$ancre, $conjoint], []);
            $familles[] = $this->famille('fp'.$i, [$beauPere, $belleMere], [$conjoint, $suivant]);

            $ancre = $suivant;
        }

        // ** LA CHAÎNE EST DÉCLARÉE À L'ENVERS, ET C'EST TOUT L'INTÉRÊT DU CAS.** L'ordre de
        // la liste est de la donnée et non de la présentation — `CalculAscendance::calculer()`
        // le dit en toutes lettres. Une passe sert les identifiants dans cet ordre :
        // déclarée du lecteur vers le large, la chaîne tombe en une seule passe et le cas ne
        // prouverait rien. Du large vers le lecteur, elle n'avance que d'un maillon par
        // passe — et douze maillons ne tiennent pas sous un plafond de huit.
        $personnes = array_merge(array_reverse($chaine), ['pEgo', 'pSoeur', 'pPere', 'pMere']);

        // Et un îlot que rien ne relie : lui seul a le droit de sortir sans bloc.
        $personnes[] = 'pIlotA';
        $personnes[] = 'pIlotB';
        $familles[] = $this->famille('fi', ['pIlotA', 'pIlotB'], []);

        $placement = (new CalculAscendance())->calculer($personnes, $familles, 'pEgo');

        $sansBloc = array_values(array_diff($personnes, array_keys($placement->branches)));
        sort($sansBloc);

        // **L'ATTENDU EST CALCULÉ, PAS ÉCRIT À LA MAIN**, et c'est ce qui fait de ce cas une
        // propriété plutôt qu'un exemple : c'est exactement le complément de la composante
        // connexe du lecteur. Une liste en dur passerait encore le jour où quelqu'un
        // ajouterait un maillon à la chaîne sans ajouter l'assertion qui va avec.
        $detaches = array_values(array_diff($personnes, $this->relieesA('pEgo', $familles)));
        sort($detaches);

        self::assertSame(
            $detaches,
            $sansBloc,
            'Sans bloc doit valoir EXACTEMENT l’îlot détaché : ni plus — quelqu’un de relié a '
            .'perdu son bloc —, ni moins — un détaché en a reçu un auquel il n’a pas droit.',
        );
        self::assertSame(['pIlotA', 'pIlotB'], $detaches, 'Le témoin : l’îlot n’est pas vide.');
    }

    /**
     * Tout ce qu'un chemin relie à une personne — parents, enfants, conjoints, sans limite
     * de distance. C'est la définition en creux de « vraiment hors de mon arbre ».
     *
     * @param list<FamillePlacable> $familles
     *
     * @return list<string>
     */
    private function relieesA(string $depart, array $familles): array
    {
        $voisins = [];

        foreach ($familles as $famille) {
            foreach ($famille->enfants as $enfant) {
                foreach ($famille->epoux as $parent) {
                    $voisins[$enfant][] = $parent;
                    $voisins[$parent][] = $enfant;
                }
            }

            if (2 === \count($famille->epoux)) {
                $voisins[$famille->epoux[0]][] = $famille->epoux[1];
                $voisins[$famille->epoux[1]][] = $famille->epoux[0];
            }
        }

        $vus = [$depart => true];
        $pile = [$depart];

        while ([] !== $pile) {
            $courant = array_pop($pile);

            foreach ($voisins[$courant] ?? [] as $voisin) {
                if (!isset($vus[$voisin])) {
                    $vus[$voisin] = true;
                    $pile[] = $voisin;
                }
            }
        }

        return array_keys($vus);
    }

    /**
     * **La descendance du lecteur est sa lignée directe, pas sa belle-famille.**.
     *
     * {@see Branch::Tr} dit « la lignée directe et la fratrie » depuis toujours, mais
     * `tronc()` s'arrêtait à l'ego, ses parents et sa fratrie. L'oubli est invisible sur la
     * racine de l'arbre réel, qui n'a pas d'enfant ; pour tout autre lecteur, ses propres
     * enfants n'étaient d'aucun quart ni du tronc et tombaient avec la belle-famille —
     * c'est-à-dire du côté de leur mère.
     */
    public function testLaDescendanceDuLecteurEstDansSonTronc(): void
    {
        $placement = (new CalculAscendance())->calculer(
            ['pEgo', 'pPere', 'pMere', 'pEpouse', 'pFils', 'pPetitFils'],
            [
                $this->famille('f1', ['pPere', 'pMere'], ['pEgo']),
                $this->famille('f2', ['pEgo', 'pEpouse'], ['pFils']),
                $this->famille('f3', ['pFils'], ['pPetitFils']),
            ],
            'pEgo',
        );

        self::assertSame(Branch::Tr, $placement->branches['pFils']);
        self::assertSame(Branch::Tr, $placement->branches['pPetitFils']);
        // La mère de l'enfant est au tronc elle aussi — c'est le foyer du lecteur. Ce qui
        // n'y est pas, c'est **sa parenté à elle** : ici elle n'en a aucune dans le graphe.
        self::assertSame(Branch::Tr, $placement->branches['pEpouse']);
    }

    /**
     * L'implexe : le couple des arrière-grands-parents est atteint par les deux côtés.
     * La remontée doit s'arrêter, et la branche revenir à la première souche qui l'atteint
     * — l'ordre pp, pm, mp, mm, celui de `graphe.py`.
     */
    public function testUnImplexeNeFaitPasBoucler(): void
    {
        $placement = (new CalculAscendance())->calculer(
            ['pEgo', 'pPere', 'pMere', 'pGpm', 'pGmm', 'pSouche'],
            [
                $this->famille('f1', ['pPere', 'pMere'], ['pEgo']),
                $this->famille('f2', ['pGpm'], ['pPere']),
                $this->famille('f3', ['pGmm'], ['pMere']),
                $this->famille('f4', ['pSouche'], ['pGpm', 'pGmm']),
            ],
            'pEgo',
        );

        self::assertSame(-3, $placement->generations['pSouche']);
        self::assertArrayHasKey('pSouche', $placement->branches);
    }

    /**
     * **Les quatre quarts du conjoint sont découpés comme ceux du lecteur** — `pp'`, `pm'`,
     * `mp'`, `mm'` de la spécification à treize blocs.
     *
     * Ce qui est vérifié ici n'est pas seulement que quatre valeurs existent : c'est que
     * **le découpage est le même geste**. Le père du père de la conjointe donne le premier
     * quart, la mère du père le deuxième, le père de la mère le troisième, la mère de la
     * mère le quatrième — la même phrase que pour le lecteur, mot pour mot, parce que c'est
     * la même fonction avec l'autre liste de quatre valeurs.
     */
    public function testLesQuatreQuartsDuConjointSontDecoupesCommeCeuxDuLecteur(): void
    {
        $placement = (new CalculAscendance())->calculer(
            [
                'pEgo', 'pPere', 'pMere', 'pEpouse', 'pBeauPere', 'pBelleMere',
                'pSonGpp', 'pSonGpm', 'pSonGmp', 'pSonGmm',
            ],
            [
                $this->famille('f1', ['pPere', 'pMere'], ['pEgo']),
                $this->famille('f2', ['pBeauPere', 'pBelleMere'], ['pEpouse']),
                $this->famille('f3', ['pEgo', 'pEpouse'], []),
                $this->famille('f4', ['pSonGpp', 'pSonGpm'], ['pBeauPere']),
                $this->famille('f5', ['pSonGmp', 'pSonGmm'], ['pBelleMere']),
            ],
            'pEgo',
        );

        self::assertSame(Branch::Cpp, $placement->branches['pSonGpp']);
        self::assertSame(Branch::Cpm, $placement->branches['pSonGpm']);
        self::assertSame(Branch::Cmp, $placement->branches['pSonGmp']);
        self::assertSame(Branch::Cmm, $placement->branches['pSonGmm']);

        // Les parents de la conjointe, chacun à la tête de sa moitié.
        self::assertSame(Branch::Cpp, $placement->branches['pBeauPere']);
        self::assertSame(Branch::Cmp, $placement->branches['pBelleMere']);

        // Et elle-même au tronc : c'est le foyer du lecteur, pas une région à part.
        self::assertSame(Branch::Tr, $placement->branches['pEpouse']);
    }

    /**
     * **LE TÉMOIN DE TOUT CE CHANTIER : la vue de la racine ne change pas d'une fiche.**.
     *
     * La racine de cet arbre n'a pas de conjoint. Les quatre quarts du conjoint ne doivent
     * donc jamais y être posés, et la colonne `branch` persistée — qui est exactement la
     * vue de la racine — ne doit pas bouger. C'est le même témoin que celui de
     * `Branch::Bf` en son temps, et pour la même raison : une région de plus qui
     * s'allumerait sur la racine serait une régression invisible en relecture, puisque le
     * dessin resterait parfaitement cohérent.
     *
     * Le test ne cite aucune des quatre valeurs nouvelles et n'a pas à être retouché si une
     * cinquième arrive : il affirme que **seules** les cinq régions d'origine sont servies.
     */
    public function testLaRacineSansConjointNeVoitAucunQuartDeConjoint(): void
    {
        $placement = (new CalculAscendance())->calculer(
            ['pEgo', 'pSoeur', 'pPere', 'pMere', 'pGpp', 'pGpm', 'pGmp', 'pGmm'],
            [
                $this->famille('f1', ['pPere', 'pMere'], ['pEgo', 'pSoeur']),
                $this->famille('f2', ['pGpp', 'pGpm'], ['pPere']),
                $this->famille('f3', ['pGmp', 'pGmm'], ['pMere']),
            ],
            'pEgo',
        );

        $attendues = [Branch::Pp, Branch::Pm, Branch::Mp, Branch::Mm, Branch::Tr];

        foreach ($placement->branches as $id => $branche) {
            self::assertContains(
                $branche,
                $attendues,
                \sprintf('« %s » sort en « %s » sur une vue sans conjoint.', $id, $branche->value),
            );
        }

        self::assertSame(Branch::Pp, $placement->branches['pGpp']);
        self::assertSame(Branch::Pm, $placement->branches['pGpm']);
        self::assertSame(Branch::Mp, $placement->branches['pGmp']);
        self::assertSame(Branch::Mm, $placement->branches['pGmm']);
        self::assertSame(Branch::Tr, $placement->branches['pEgo']);
    }

    /**
     * **LE SANG DU LECTEUR PASSE AVANT LES QUARTS DU CONJOINT**, et ce test vise l'ordre
     * de deux appels, pas une condition.
     *
     * Sur un arbre endogame, un même aïeul appartient aux deux moitiés du dessin. Il doit
     * rester du côté du lecteur. Intervertir les deux passes le ferait basculer dans le
     * bloc du conjoint **sans rien casser ni rien signaler** : le dessin resterait
     * cohérent, simplement faux — un aïeul du lecteur peint dans la famille de sa femme.
     *
     * L'arbre : le lecteur épouse sa cousine germaine. Le grand-père paternel du lecteur
     * est donc **aussi** le grand-père paternel de sa conjointe.
     */
    public function testLeSangDuLecteurPasseAvantLesQuartsDuConjoint(): void
    {
        $placement = (new CalculAscendance())->calculer(
            ['pEgo', 'pPere', 'pMere', 'pGpp', 'pGpm', 'pEpouse', 'pBeauPere', 'pBelleMere'],
            [
                $this->famille('f1', ['pPere', 'pMere'], ['pEgo']),
                // Le père du lecteur et le père de sa conjointe sont frères.
                $this->famille('f2', ['pGpp', 'pGpm'], ['pPere', 'pBeauPere']),
                $this->famille('f3', ['pBeauPere', 'pBelleMere'], ['pEpouse']),
                $this->famille('f4', ['pEgo', 'pEpouse'], []),
            ],
            'pEgo',
        );

        // Atteints par les deux moitiés : ils restent du côté du lecteur.
        self::assertSame(Branch::Pp, $placement->branches['pGpp']);
        self::assertSame(Branch::Pm, $placement->branches['pGpm']);

        // La moitié droite garde ce qui n'appartient qu'à elle.
        self::assertSame(Branch::Cmp, $placement->branches['pBelleMere']);
    }

    /**
     * **Un conjoint sans grands-parents connus garde quand même sa famille.**.
     *
     * Ce test est né d'un échec, et il vaut d'être gardé pour cela. La première version de
     * {@see CalculAscendance::quartsDuConjoint()} ne posait que les **grands-parents** du
     * conjoint, en laissant la boucle d'héritage servir la génération des parents. Le
     * raisonnement se tenait — et il tombait dès que ces grands-parents manquaient : plus
     * aucune amorce, et les beaux-parents ressortaient **sans branche du tout**,
     * c'est-à-dire sur le repli silencieux du sérialiseur que `Bf` avait supprimé.
     *
     * L'asymétrie qui le cause est réelle : du côté du lecteur, la génération des parents
     * est couverte par le tronc ; du côté du conjoint, rien ne la couvre.
     *
     * **Aucune fiche ne doit sortir d'ici sans branche.**
     */
    public function testLaFamilleDuConjointSansGrandsParentsRecoitQuandMemeSesQuarts(): void
    {
        $placement = (new CalculAscendance())->calculer(
            ['pEgo', 'pPere', 'pMere', 'pEpouse', 'pBeauPere', 'pBelleMere', 'pBeauFrere'],
            [
                $this->famille('f1', ['pPere', 'pMere'], ['pEgo']),
                $this->famille('f2', ['pBeauPere', 'pBelleMere'], ['pEpouse', 'pBeauFrere']),
                $this->famille('f3', ['pEgo', 'pEpouse'], []),
            ],
            'pEgo',
        );

        self::assertSame(Branch::Cpp, $placement->branches['pBeauPere']);
        self::assertSame(Branch::Cmp, $placement->branches['pBelleMere']);
        // Le frère de la conjointe suit le losange de ses parents, donc le quart du père —
        // le même choix par défaut que pour la fratrie d'un parent du lecteur.
        self::assertSame(Branch::Cpp, $placement->branches['pBeauFrere']);

        self::assertCount(
            7,
            $placement->branches,
            'Une fiche sans branche retombe sur la vue de la racine par un repli silencieux.',
        );
    }

    /**
     * @param list<string> $epoux
     * @param list<string> $enfants
     */
    private function famille(string $id, array $epoux, array $enfants): FamillePlacable
    {
        return new FamillePlacable(id: $id, epoux: $epoux, enfants: $enfants, aDivorce: false);
    }
}
