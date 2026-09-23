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
 * Le cas nominal — 12 générations et `pm 134, mm 64, mp 51, pp 20, tr 5` — est couvert
 * par `tests/Gedcom/AnalyseurGedcomTest.php` sur sa fixture, et par l'import réel. Ce qui
 * est vérifié ici, ce sont les situations que le fichier de référence ne contient pas
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
     * avant le premier import : `tree.personne_racine_id` vaut 0, et
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
     * **LE TRONC NE SE PROPAGE PAS PAR ALLIANCE.** C'est le test qui manquait, et il vise
     * une seule ligne : `Branch::Tr !== $branche[$voisin]` dans la passe d'héritage.
     *
     * Le test voisin vérifie ce que le tronc **contient** ; celui-ci vérifie ce qu'il ne
     * **déborde pas**. La nuance n'est pas académique : retirer cette condition fait passer
     * le tronc de 8 à 180 personnes sur le graphe servi, parce qu'il s'écoule par les
     * conjoints puis de proche en proche. **Retirer la garde doit faire rougir ce test.**
     *
     * Et il ne rougissait nulle part ailleurs : la garde est *inerte pour la racine*, seul
     * point de vue qu'un compte obtienne aujourd'hui. Sans ce test, la condition restait
     * une mine — porteuse, non documentée, et jamais exercée.
     *
     * L'arbre : l'ego, son épouse, et les parents de son épouse. Rien ne relie ces
     * beaux-parents au sang de l'ego — ils ne doivent donc jamais devenir `Tr`.
     */
    public function testLeTroncNeSePropagePasParAlliance(): void
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

        // L'épouse touche le tronc par trois liens : elle n'en fait pas partie pour autant,
        // et sa famille non plus. Ce qu'elles reçoivent à la place est `Bf`, et c'est le
        // test suivant qui le tient.
        self::assertNotSame(Branch::Tr, $placement->branches['pEpouse'] ?? null);
        self::assertNotSame(Branch::Tr, $placement->branches['pBeauPere'] ?? null);
        self::assertNotSame(Branch::Tr, $placement->branches['pBelleMere'] ?? null);
    }

    /**
     * La belle-famille reçoit **sa propre région**, et non celle d'un quart d'ascendance.
     *
     * Avant elle, ces personnes sortaient sans branche du tout, et le sérialiseur du graphe
     * leur servait celle **de la racine** par un repli silencieux — « maternelle du père »
     * désignait alors le côté maternel de quelqu'un d'autre.
     */
    public function testLaBelleFamilleARegionAElle(): void
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

        // L'amorce : la conjointe d'un membre du tronc, que le sang n'a pas classée.
        self::assertSame(Branch::Bf, $placement->branches['pEpouse']);
        // Puis sa famille — une belle-famille est une famille, pas une personne.
        self::assertSame(Branch::Bf, $placement->branches['pBeauPere']);
        self::assertSame(Branch::Bf, $placement->branches['pBelleMere']);
        self::assertSame(Branch::Bf, $placement->branches['pBeauFrere']);
    }

    /**
     * **La belle-famille n'entame aucun quart d'ascendance.** Sa passe vient après
     * l'héritage par le sang et ne touche que ce qui n'a pas de branche : un grand-parent
     * reste dans son quart même s'il est marié à quelqu'un.
     */
    public function testLaBelleFamilleNeDisputeRienAuSang(): void
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
        self::assertArrayNotHasKey('pBf', $placement->branches);
        foreach ($placement->branches as $branche) {
            self::assertNotSame(Branch::Bf, $branche);
        }
    }

    /**
     * **La descendance du lecteur est sa lignée directe, pas sa belle-famille.**
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
        // La mère de l'enfant reste, elle, la belle-famille.
        self::assertSame(Branch::Bf, $placement->branches['pEpouse']);
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
     * @param list<string> $epoux
     * @param list<string> $enfants
     */
    private function famille(string $id, array $epoux, array $enfants): FamillePlacable
    {
        return new FamillePlacable(id: $id, epoux: $epoux, enfants: $enfants, aDivorce: false);
    }
}
