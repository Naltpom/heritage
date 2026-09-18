<?php

declare(strict_types=1);

namespace App\Tests\Tree;

use App\Module\Tree\ValueObject\BoiteEnglobante;
use App\Module\Tree\ValueObject\NoeudPlace;
use App\Module\Tree\ValueObject\Placement;
use PHPUnit\Framework\TestCase;

/**
 * ═══════════════════════════════════════════════════════════════════════════════
 *  L'EMPREINTE DE LA MISE EN PAGE — CE QUI FAIT QU'UN DÉPLOIEMENT ATTEINT L'ÉCRAN.
 * ═══════════════════════════════════════════════════════════════════════════════.
 *
 * `GET /api/arbre/graphe` répond `Cache-Control: private, no-cache` : le navigateur garde
 * la charge et revalide. Son `ETag` ne portait que l'état de la base et le lecteur —
 * **rien qui bouge quand c'est le code de placement qu'on réécrit**. Un lecteur revenu
 * après un déploiement recevait donc `304` et redessinait l'ancienne image.
 *
 * {@see Placement::empreinte()} ferme le cas en dérivant l'identité de l'image **de
 * l'image**, plutôt qu'en confiant à quelqu'un le soin de bousculer un numéro de version.
 * Ce fichier tient les quatre propriétés dont dépend ce raisonnement.
 */
final class EmpreinteDeLaMiseEnPageTest extends TestCase
{
    /**
     * La propriété de base : **deux images différentes, deux empreintes différentes**.
     * Sans elle, la composante ne sert à rien.
     */
    public function testDeplacerUnSeulNoeudChangeLEmpreinte(): void
    {
        $avant = $this->placement(['p1' => 0.0, 'p2' => 180.0]);
        $apres = $this->placement(['p1' => 0.0, 'p2' => 200.0]);

        self::assertNotSame($avant->empreinte(), $apres->empreinte());
    }

    /**
     * La propriété symétrique, et c'est elle qui rend la revalidation possible : à image
     * égale, l'empreinte ne bouge pas. Une empreinte instable ferait retélécharger le
     * graphe à chaque requête — un `ETag` qui ne vaut jamais rien.
     */
    public function testLaMemeImageDonneLaMemeEmpreinte(): void
    {
        $coordonnees = ['p1' => 0.0, 'p2' => 180.0, 'f1' => 90.0];

        self::assertSame(
            $this->placement($coordonnees)->empreinte(),
            $this->placement($coordonnees)->empreinte(),
        );
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $this->placement($coordonnees)->empreinte());
    }

    /**
     * **L'ordre du tableau compte**, et ce n'est pas un excès de prudence : la projection
     * de l'étage 2 parcourt `noeuds` tel quel, donc deux placements aux mêmes coordonnées
     * mais d'ordre différent produisent deux charges JSON différentes — que l'`ETag` doit
     * distinguer.
     */
    public function testDeuxOrdresDeRangeeDonnentDeuxEmpreintes(): void
    {
        $gauche = $this->placement(['p1' => 0.0, 'p2' => 180.0]);
        $droite = $this->placement(['p2' => 180.0, 'p1' => 0.0]);

        self::assertNotSame($gauche->empreinte(), $droite->empreinte());
    }

    /**
     * La forme sérialisée, écrite en clair — c'est elle qui porte les deux gardes.
     *
     * Le point décimal : `%F` est indépendant de la locale, `%f` ne l'est pas. Sans cette
     * assertion, remplacer l'un par l'autre passerait toutes les autres et ne se verrait
     * que sur un processus réglé en `fr_FR`, où la même image prendrait une autre
     * empreinte — donc un `ETag` qui change sans raison, à chaque bascule de locale.
     *
     * Et `-0.0` : c'est le même point que `0.0`, les distinguer coûterait une
     * revalidation pour rien.
     */
    public function testLaFormeSerialiseeNeDependNiDeLaLocaleNiDuSigneDuZero(): void
    {
        self::assertSame(
            hash('sha256', implode("\x1e", ['p1', 'p1 18.5000 0.0000 120 40'])),
            $this->placement(['p1' => 18.5])->empreinte(),
            'La forme : « id x y w h », point décimal, quatre décimales.',
        );

        self::assertSame(
            $this->placement(['p1' => 0.0])->empreinte(),
            $this->placement(['p1' => -0.0])->empreinte(),
        );
    }

    /** @param array<string, float> $abscisses */
    private function placement(array $abscisses): Placement
    {
        $noeuds = [];

        foreach ($abscisses as $id => $x) {
            $noeuds[$id] = new NoeudPlace($id, $x, 0.0, 120, 40);
        }

        return new Placement($noeuds, [], new BoiteEnglobante(0.0, 0.0, 100.0, 100.0), 'p1');
    }
}
