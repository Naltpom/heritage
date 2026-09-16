<?php

declare(strict_types=1);

namespace App\Tests\Tree;

use App\Module\Tree\Service\MoteurDePlacement;
use App\Module\Tree\ValueObject\AreteDePlacement;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * La preuve d'équivalence du portage : `MoteurDePlacement` doit reproduire
 * `graphe.json` **exactement** (ADR 0005).
 *
 * On porte un algorithme déterministe dont on possède le code
 * (`docs/moteur-retrouve/graphe.py`, 624 lignes) : un écart n'est pas une variation
 * acceptable, c'est un bug de portage. La tolérance de 0,01 ne couvre que la
 * représentation des flottants — surtout pas une différence d'algorithme. Elle est
 * volontairement trop fine pour absorber quoi que ce soit : le plus petit écart réel
 * observé lors du portage se comptait en dizaines de pixels, jamais en centièmes.
 *
 * **Ce test ne tourne pas en CI** : `graphe.json` n'est pas versionné (ADR 0006), il
 * décrit des personnes vivantes. La CI se rabat sur `PlacementInvariantsTest`, qui
 * mesure des invariants sur une topologie synthétique — couverture plus faible,
 * assumée, et qui **peut passer au vert sur un portage faux**. D'où la règle de
 * l'ADR 0005 : ce test-ci doit être lancé au moins une fois avant la mise en service,
 * et son résultat consigné.
 *
 * Pour le relancer :
 *
 *     cd C:/www/heritage/api
 *     vendor/bin/phpunit --filter Placement
 *
 * Si l'atelier n'est pas à son emplacement conventionnel :
 *
 *     HERITAGE_GRAPHE_REFERENCE=/chemin/vers/graphe.json vendor/bin/phpunit --filter Placement
 */
#[Group('donnees-reelles')]
final class PlacementNonRegressionTest extends TestCase
{
    /** Assez fin pour ne laisser passer qu'un dernier bit de flottant. */
    private const float TOLERANCE = 0.01;

    private const int NOEUDS_ATTENDUS = 377;
    private const int ARETES_ATTENDUES = 377;

    private ChargeurDeGrapheDeReference $reference;

    protected function setUp(): void
    {
        if (!ChargeurDeGrapheDeReference::disponible()) {
            self::markTestSkipped(\sprintf(
                "Fichier de référence absent : %s\n".
                "C'est normal hors de la machine du commanditaire — il n'est pas versionné (ADR 0006).\n".
                'Renseigner %s pour le désigner ailleurs.',
                ChargeurDeGrapheDeReference::chemin(),
                ChargeurDeGrapheDeReference::VARIABLE_ENV,
            ));
        }

        $this->reference = ChargeurDeGrapheDeReference::charge();
    }

    /**
     * Le cœur du critère d'acceptation : les 377 nœuds, au centième près, sur
     * `x`, `y`, `w` et `h`.
     */
    public function testLes377NoeudsSontPlacesExactement(): void
    {
        $placement = (new MoteurDePlacement())->calcule($this->reference->jeu());
        $attendu = $this->reference->positionsAttendues();

        self::assertCount(self::NOEUDS_ATTENDUS, $attendu, 'Le fichier de référence lui-même doit porter 377 nœuds.');
        self::assertCount(
            self::NOEUDS_ATTENDUS,
            $placement->noeuds,
            \sprintf('Le moteur a produit %d nœuds au lieu de 377.', \count($placement->noeuds)),
        );

        $exacts = 0;
        $ecarts = [];

        foreach ($attendu as $id => $cible) {
            $noeud = $placement->noeud($id);

            if (null === $noeud) {
                $ecarts[] = \sprintf('%s : absent du placement calculé', $id);

                continue;
            }

            $obtenu = ['x' => $noeud->x, 'y' => $noeud->y, 'w' => (float) $noeud->w, 'h' => (float) $noeud->h];
            $divergents = [];

            foreach ($cible as $axe => $valeur) {
                $delta = $obtenu[$axe] - $valeur;

                if (abs($delta) > self::TOLERANCE) {
                    $divergents[] = \sprintf('%s attendu %.6f, obtenu %.6f (écart %+.6f)', $axe, $valeur, $obtenu[$axe], $delta);
                }
            }

            if ([] === $divergents) {
                ++$exacts;
            } else {
                $ecarts[] = \sprintf('%s : %s', $id, implode(' ; ', $divergents));
            }
        }

        echo \sprintf("\n  Placement — non-régression : %d/%d nœuds exacts.\n", $exacts, \count($attendu));

        self::assertSame(
            \count($attendu),
            $exacts,
            \sprintf(
                "%d nœud(s) divergent(s) sur %d.\n%s\n".
                "Ne pas élargir la tolérance : c'est exactement ce que l'ADR 0005 cherche à empêcher.\n".
                "Relire `docs/moteur-retrouve/graphe.py` et le moteur côte à côte — l'ordre de parcours\n".
                'et le moment de la division flottante sont les deux causes connues.',
                \count($ecarts),
                \count($attendu),
                implode("\n", \array_slice($ecarts, 0, 30)),
            ),
        );
    }

    /**
     * L'ordre des nœuds n'est pas cosmétique : il est produit par le parcours
     * rangée par rangée puis gauche à droite, et le viewer s'en sert pour l'ordre de
     * dessin. Un ordre différent à positions identiques trahirait un tri instable.
     */
    public function testLOrdreDesNoeudsEstIdentique(): void
    {
        $placement = (new MoteurDePlacement())->calcule($this->reference->jeu());

        self::assertSame(
            array_keys($this->reference->positionsAttendues()),
            array_keys($placement->noeuds),
            "L'ordre de placement doit être celui du script d'origine : rangées croissantes, puis gauche à droite, puis les nœuds familiaux.",
        );
    }

    public function testLes377AretesSontIdentiques(): void
    {
        $placement = (new MoteurDePlacement())->calcule($this->reference->jeu());
        $attendu = $this->reference->aretesAttendues();

        self::assertCount(self::ARETES_ATTENDUES, $attendu);
        self::assertCount(self::ARETES_ATTENDUES, $placement->aretes);

        foreach ($attendu as $rang => $cible) {
            $arete = $placement->aretes[$rang];

            self::assertSame($cible['de'], $arete->de, \sprintf('Arête %d : origine.', $rang));
            self::assertSame($cible['vers'], $arete->vers, \sprintf('Arête %d : destination.', $rang));
            self::assertSame($cible['genre'], $arete->genre, \sprintf('Arête %d : genre.', $rang));

            if (AreteDePlacement::GENRE_UNION === $cible['genre']) {
                self::assertSame($cible['divorce'], $arete->divorce, \sprintf('Arête %d : divorce.', $rang));
            }
        }
    }

    /**
     * La boîte alimente le `viewBox` du viewer : `-7770.390869140625 -1730 15824 1810`.
     * Un écart ici veut dire qu'un nœud extrême a bougé, même si le décompte est bon.
     *
     * > **`-1730` et `1810` sont des pixels, pas des millésimes.** Un relevé automatique
     * > de dates les prend pour des années et propose de les décaler ; les décaler
     * > décrirait une autre boîte que celle du fichier de référence, et ce test
     * > tomberait. Ils ne se substituent pas.
     */
    public function testLaBoiteEnglobanteEstIdentique(): void
    {
        $placement = (new MoteurDePlacement())->calcule($this->reference->jeu());
        $attendu = $this->reference->boiteAttendue();

        self::assertEqualsWithDelta($attendu['x'], $placement->boite->x, self::TOLERANCE, 'Boîte : x.');
        self::assertEqualsWithDelta($attendu['y'], $placement->boite->y, self::TOLERANCE, 'Boîte : y.');
        self::assertEqualsWithDelta($attendu['w'], $placement->boite->w, self::TOLERANCE, 'Boîte : largeur.');
        self::assertEqualsWithDelta($attendu['h'], $placement->boite->h, self::TOLERANCE, 'Boîte : hauteur.');
    }

    /** L'ego est le point de lecture : c'est lui qu'on centre, pas la boîte. */
    public function testLEgoEstRameneALOrigine(): void
    {
        $placement = (new MoteurDePlacement())->calcule($this->reference->jeu());
        $ego = $placement->noeud($this->reference->ego());

        self::assertNotNull($ego);
        self::assertEqualsWithDelta(0.0, $ego->x, self::TOLERANCE);
    }

    /**
     * Le moteur est sans état (ADR 0005) : deux appels sur la même entrée donnent le
     * même résultat, et le premier ne contamine pas le second par une mémoïsation
     * restée en place.
     */
    public function testLeMoteurEstSansEtat(): void
    {
        $moteur = new MoteurDePlacement();
        $jeu = $this->reference->jeu();

        $premier = $moteur->calcule($jeu);
        $second = $moteur->calcule($jeu);

        foreach ($premier->noeuds as $id => $noeud) {
            $autre = $second->noeud($id);
            self::assertNotNull($autre);
            self::assertSame($noeud->x, $autre->x, \sprintf('Nœud %s : le second calcul diverge du premier.', $id));
        }
    }
}
