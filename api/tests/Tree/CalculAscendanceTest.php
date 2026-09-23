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

        // **Ce que ce test vise n'est pas l'épouse, ce sont ses parents.** L'épouse, elle,
        // est au tronc depuis que la colonne a cessé de contredire le moteur de placement,
        // qui l'y garde déjà — « le conjoint reste dans le tronc, c'est le foyer du
        // lecteur ». La définition du commanditaire dit la même chose : *« moi, mes
        // parents, mon conjoint — mais pas ses frères et sœurs »*. Voir {@see
        // CalculAscendance::tronc()}, qui porte la raison en entier.
        //
        // La garde qu'exerce ce test est intacte, et c'est **la seconde ligne** qui la
        // porte : le tronc s'arrête au conjoint et ne s'écoule pas chez ses parents. Si la
        // condition `Branch::Tr !== $branche[$voisin]` disparaissait de la boucle
        // d'héritage, ce sont ces deux-là qui deviendraient `Tr` — et elles rougiraient.
        self::assertSame(Branch::Tr, $placement->branches['pEpouse']);
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
            ['pEgo', 'pPere', 'pMere', 'pSoeur', 'pMariDeLaSoeur', 'pSonPere', 'pSaMere'],
            [
                $this->famille('f1', ['pPere', 'pMere'], ['pEgo', 'pSoeur']),
                $this->famille('f2', ['pSonPere', 'pSaMere'], ['pMariDeLaSoeur']),
                $this->famille('f3', ['pMariDeLaSoeur', 'pSoeur'], []),
            ],
            'pEgo',
        );

        // L'amorce : le conjoint d'un membre du tronc, que le sang n'a pas classé.
        self::assertSame(Branch::Bf, $placement->branches['pMariDeLaSoeur']);
        // Puis sa famille — une belle-famille est une famille, pas une personne.
        self::assertSame(Branch::Bf, $placement->branches['pSonPere']);
        self::assertSame(Branch::Bf, $placement->branches['pSaMere']);
    }

    /**
     * **`Bf` n'a pas été remplacée par les quatre quarts du conjoint : elle a rétréci.**.
     *
     * Son docbloc annonçait « une seule clé aujourd'hui, quatre demain », et prévoyait que
     * le découpage la remplace. Ce test tient la part de cette prévision qui était fausse,
     * parce qu'elle ne se voit qu'en faisant cohabiter les deux régions dans un même arbre.
     *
     * L'amorce de `Bf` part du conjoint de **tout** membre du tronc. Celui du lecteur a
     * désormais ses quatre quarts ; ceux de sa fratrie et de ses enfants n'en ont pas, et
     * n'en auront pas — les treize blocs ne leur font pas de place. `Bf` les garde.
     *
     * L'arbre : le lecteur avec sa conjointe et les parents de celle-ci d'un côté, sa sœur
     * avec son mari à elle et les parents de ce mari de l'autre. **Les deux belles-familles
     * ne doivent pas tomber dans la même région.**
     */
    public function testLesQuartsDuConjointNeViderontPasLaBelleFamille(): void
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

        // La belle-famille de la sœur n'est l'ascendance de personne dans le dessin : elle
        // n'a pas de quatre quarts à recevoir, et reste donc `Bf`.
        self::assertSame(Branch::Bf, $placement->branches['pMariDeLaSoeur']);
        self::assertSame(Branch::Bf, $placement->branches['pSonPere']);
        self::assertSame(Branch::Bf, $placement->branches['pSaMere']);
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
     * {@see Branch::Bf} en son temps, et pour la même raison : une région de plus qui
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
