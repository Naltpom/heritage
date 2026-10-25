<?php

declare(strict_types=1);

namespace App\Tests\Tree;

use App\Module\Core\Enum\Branch;
use App\Module\Core\Enum\Sex;
use App\Module\Tree\Enum\ParenteDansLArbre;
use App\Module\Tree\Service\CalculAscendance;
use App\Module\Tree\ValueObject\FamillePlacable;
use App\Module\Tree\ValueObject\JeuDePlacement;
use App\Module\Tree\ValueObject\PersonnePlacable;
use PHPUnit\Framework\TestCase;

/**
 * ═══════════════════════════════════════════════════════════════════════════════
 *  LES FAMILLES PAR ALLIANCE — décision du commanditaire du 2026-10-01.
 * ═══════════════════════════════════════════════════════════════════════════════.
 *
 * Le cas des captures « voir comme Camille » : la lectrice L (Camille) ; sa mère M, fille
 * du couple Voulgre × Figarol (souches `mp` et `mm`) ; la sœur S de M, mariée à P, qui a
 * une ascendance propre sur six générations ; leurs enfants U (le commanditaire, cousin
 * germain de L) et U2.
 *
 * **Avant** : toute l'ascendance de P — sans aucun lien de sang avec L — était rangée dans
 * la branche `mp` de la lectrice, par deux règles qui propageaient par le conjoint puis
 * remontaient la filiation sans regarder le sang (la boucle d'héritage, puis
 * `parAlliance()`). Ce fichier remplace le test de diagnostic qui le constatait ; ses
 * assertions sont retournées.
 *
 * **Ce qui est tenu ici** — sur {@see CalculAscendance::vuDuLecteur()}, la vue que le
 * graphe sert :
 *
 * - P, le pont, est « famille » et garde la branche de sa femme : il reste à côté d'elle ;
 * - son ascendance est `alliance`, **sans branche**, et son pont est P ;
 * - dans les deux ordres d'identifiants (les deux règles fautives étaient sensibles à
 *   l'ordre de façon différente) ;
 * - la famille du conjoint **du lecteur** reste `conjoint`, avec ses quatre quarts.
 *
 * Et ce qui **ne change pas** : {@see CalculAscendance::calculer()}, la colonne persistée,
 * garde l'arbitrage du 23/09 — voir `CalculAscendanceTest`, qui le tient toujours.
 */
final class FamillesParAllianceTest extends TestCase
{
    public function testLAscendanceDuMariDeLaTanteNEstPlusRangeeDansLaBrancheVoulgre(): void
    {
        [$ids, $familles, $ascendanceDeP] = $this->arbre();

        $vue = (new CalculAscendance())->vuDuLecteur($this->jeu($ids, $familles, 'pL'));
        $b = $vue->branches;

        // Le sang de la lectrice, inchangé.
        self::assertSame(Branch::Tr, $b['pL']);
        self::assertSame(Branch::Tr, $b['pM']);
        self::assertSame(Branch::Mp, $b['pVoulgre1']);
        self::assertSame(Branch::Mm, $b['pFigarol1']);
        self::assertSame(Branch::Mp, $b['pVoulgre5']);
        // La tante et ses enfants : collatéraux, donc du sang — `mp`, comme avant.
        self::assertSame(Branch::Mp, $b['pS']);
        self::assertSame(Branch::Mp, $b['pU']);
        self::assertSame(Branch::Mp, $b['pU2']);

        // Le pont : famille, à côté de sa femme.
        self::assertSame(ParenteDansLArbre::Famille, $vue->parentes['pP']);
        self::assertSame(Branch::Mp, $b['pP'], 'Le mari de la tante prend le bloc de sa femme : il reste le pont, à côté d’elle.');

        // ⚠ LE DÉFAUT DES CAPTURES, RETOURNÉ : aucune branche, parenté « alliance », pont P.
        foreach ($ascendanceDeP as $id) {
            self::assertArrayNotHasKey($id, $b, \sprintf('%s est de l’ascendance du mari de la tante : il ne reçoit aucune branche de la lectrice.', $id));
            self::assertSame(ParenteDansLArbre::Alliance, $vue->parentes[$id], $id);
            self::assertSame('pP', $vue->ponts[$id], $id);
        }

        self::assertGreaterThan(100, \count($ascendanceDeP), 'Le témoin : l’ascendance de P est bien là, sur six générations.');
    }

    /**
     * Les deux règles fautives n'étaient pas sensibles à l'ordre de la même façon : la
     * boucle d'héritage avance d'une génération par passe, `parAlliance()` tourne jusqu'au
     * point fixe. Dans l'ordre inverse — ancêtres lointains d'abord —, la correction doit
     * tenir de la même manière.
     */
    public function testLaCorrectionTientDansLesDeuxOrdres(): void
    {
        [$ids, $familles, $ascendanceDeP] = $this->arbre();

        $vue = (new CalculAscendance())->vuDuLecteur($this->jeu(array_reverse($ids), $familles, 'pL'));

        self::assertSame(Branch::Mp, $vue->branches['pP'] ?? null);

        foreach ($ascendanceDeP as $id) {
            self::assertArrayNotHasKey($id, $vue->branches, $id);
            self::assertSame('pP', $vue->ponts[$id] ?? null, $id);
        }
    }

    /**
     * **La colonne persistée garde l'ancienne règle**, et ce test dit pourquoi on n'y
     * touche pas : la chronologie fait ses colonnes de `tree_person.branch`, et la
     * réécrire bousculerait la version de centaines de fiches. Les générations, elles,
     * sont identiques des deux côtés — une seule définition de « génération ».
     */
    public function testLaColonnePersisteeEtLaVueDuLecteurPartagentLesGenerations(): void
    {
        [$ids, $familles] = $this->arbre();
        $calcul = new CalculAscendance();

        $colonne = $calcul->calculer($ids, $familles, 'pL');
        $vue = $calcul->vuDuLecteur($this->jeu($ids, $familles, 'pL'));

        self::assertSame($colonne->generations, $vue->generations);
        self::assertSame(Branch::Mp, $colonne->branches['pA1'] ?? null, 'La colonne persistée garde l’arbitrage du 23/09.');
        self::assertSame([], $colonne->parentes, 'La colonne n’a pas l’usage de la parenté vue du lecteur.');
    }

    /**
     * **La famille du conjoint du lecteur reste « conjoint »** — le filtre « Famille du
     * conjoint » est conservé tel quel —, et ses quatre quarts avec elle. Seule la
     * belle-famille de la **sœur** devient une famille par alliance.
     */
    public function testLaFamilleDuConjointDuLecteurResteConjoint(): void
    {
        $ids = [
            'pEgo', 'pPere', 'pMere', 'pSoeur',
            'pEpouse', 'pBeauPere', 'pBelleMere', 'pBeauFrere',
            'pMariDeLaSoeur', 'pSonPere', 'pSaMere',
        ];
        $familles = [
            new FamillePlacable('f1', ['pPere', 'pMere'], ['pEgo', 'pSoeur'], false),
            new FamillePlacable('f2', ['pBeauPere', 'pBelleMere'], ['pEpouse', 'pBeauFrere'], false),
            new FamillePlacable('f3', ['pEgo', 'pEpouse'], [], false),
            new FamillePlacable('f4', ['pSonPere', 'pSaMere'], ['pMariDeLaSoeur'], false),
            new FamillePlacable('f5', ['pMariDeLaSoeur', 'pSoeur'], [], false),
        ];

        $vue = (new CalculAscendance())->vuDuLecteur($this->jeu($ids, $familles, 'pEgo'));

        foreach (['pEgo', 'pPere', 'pMere', 'pSoeur', 'pEpouse', 'pMariDeLaSoeur'] as $id) {
            self::assertSame(ParenteDansLArbre::Famille, $vue->parentes[$id], $id);
        }

        foreach (['pBeauPere', 'pBelleMere', 'pBeauFrere'] as $id) {
            self::assertSame(ParenteDansLArbre::Conjoint, $vue->parentes[$id], $id);
            self::assertArrayNotHasKey($id, $vue->ponts, '`pont` ne concerne que l’alliance.');
        }

        self::assertSame(Branch::Cpp, $vue->branches['pBeauPere']);
        self::assertSame(Branch::Cmp, $vue->branches['pBelleMere']);
        self::assertSame(Branch::Cpp, $vue->branches['pBeauFrere']);

        // La belle-famille de la sœur : le mari reste au tronc, à côté d'elle ; ses
        // parents sont une famille par alliance, rattachée par lui.
        self::assertSame(Branch::Tr, $vue->branches['pMariDeLaSoeur']);

        foreach (['pSonPere', 'pSaMere'] as $id) {
            self::assertSame(ParenteDansLArbre::Alliance, $vue->parentes[$id], $id);
            self::assertSame('pMariDeLaSoeur', $vue->ponts[$id], $id);
            self::assertArrayNotHasKey($id, $vue->branches, $id);
        }
    }

    /**
     * **Le conjoint d'un conjoint n'entre pas** : la famille ajoute les conjoints du sang
     * en une seule passe, comme le cercle de l'ADR 0003. Le second mari de la femme du
     * cousin, et l'enfant qu'elle a eu de lui, ne sont pas de la famille du lecteur.
     *
     * Et **un groupe touché par deux ponts va au plus proche** : l'ascendance du mari de
     * la sœur rejoint son bloc à lui, pas celui de l'épouse du cousin, même si les deux
     * belles-familles se rejoignent plus haut.
     */
    public function testLeConjointDUnConjointNEntrePasEtChaqueGroupeVaAuPontLePlusProche(): void
    {
        $ids = [
            'pEgo', 'pPere', 'pMere', 'pSoeur', 'pOncle', 'pCousin',
            'pFemmeDuCousin', 'pSecondMari', 'pDemiCousin',
            'pMariDeLaSoeur', 'pSonPere', 'pSonGrandPere', 'pPereDeLaFemme',
        ];
        $familles = [
            new FamillePlacable('f1', ['pPere', 'pMere'], ['pEgo', 'pSoeur'], false),
            new FamillePlacable('f2', ['pOncle'], ['pCousin'], false),
            new FamillePlacable('f3', ['pCousin', 'pFemmeDuCousin'], [], false),
            new FamillePlacable('f4', ['pSecondMari', 'pFemmeDuCousin'], ['pDemiCousin'], false),
            new FamillePlacable('f5', ['pMariDeLaSoeur', 'pSoeur'], [], false),
            new FamillePlacable('f6', ['pSonPere'], ['pMariDeLaSoeur'], false),
            new FamillePlacable('f7', ['pSonGrandPere'], ['pSonPere', 'pPereDeLaFemme'], false),
            new FamillePlacable('f8', ['pPereDeLaFemme'], ['pFemmeDuCousin'], false),
            // L'oncle est le frère du père : ils ont un parent commun.
            new FamillePlacable('f9', ['pGrandPere'], ['pPere', 'pOncle'], false),
        ];
        $ids[] = 'pGrandPere';

        $vue = (new CalculAscendance())->vuDuLecteur($this->jeu($ids, $familles, 'pEgo'));

        self::assertSame(ParenteDansLArbre::Famille, $vue->parentes['pCousin']);
        self::assertSame(ParenteDansLArbre::Famille, $vue->parentes['pFemmeDuCousin'], 'Le conjoint d’un cousin est de la famille.');
        self::assertSame(ParenteDansLArbre::Alliance, $vue->parentes['pSecondMari'], 'Le conjoint d’un conjoint n’entre pas.');
        self::assertSame(ParenteDansLArbre::Alliance, $vue->parentes['pDemiCousin'], 'L’enfant d’une autre union du conjoint n’est pas du sang.');
        self::assertSame('pFemmeDuCousin', $vue->ponts['pSecondMari']);
        self::assertSame('pFemmeDuCousin', $vue->ponts['pDemiCousin']);
        self::assertSame('pFemmeDuCousin', $vue->ponts['pPereDeLaFemme']);

        // Le plus proche : un saut depuis le mari de la sœur, deux depuis la femme du cousin.
        self::assertSame('pMariDeLaSoeur', $vue->ponts['pSonPere']);
        // À égalité (deux sauts de chaque côté), le premier pont dans l'ordre des
        // identifiants : la femme du cousin précède le mari de la sœur dans la liste.
        self::assertSame('pFemmeDuCousin', $vue->ponts['pSonGrandPere']);
    }

    /**
     * **La belle-famille de la sœur du conjoint est une famille par alliance** — vu de
     * l'oncle par alliance du commanditaire (`/arbre?depuis=`, 25/10/2026).
     *
     * Le lecteur O a épousé N ; la sœur S de N a épousé P ; leurs enfants U et U2. Pour O,
     * S, U, U2 sont du sang de sa femme, P est le conjoint de ce sang : tous restent du côté
     * du conjoint. Mais les parents de P et son frère n'ont aucun lien avec O : c'est une
     * famille par alliance, rattachée par P. La règle d'avant — tout ce qu'on atteint
     * depuis le conjoint — les rangeait chez N, et toute l'ascendance de P avec eux.
     */
    public function testLaBelleFamilleDeLaSoeurDuConjointEstUneFamilleParAlliance(): void
    {
        $ids = [
            'pO', 'pN', 'pGpN', 'pGmN', 'pS', 'pP', 'pU', 'pU2',
            'pPereDeP', 'pMereDeP', 'pFrereDeP', 'pGrandPereDeP',
        ];
        $familles = [
            new FamillePlacable('f1', ['pO', 'pN'], [], false),
            new FamillePlacable('f2', ['pGpN', 'pGmN'], ['pN', 'pS'], false),
            new FamillePlacable('f3', ['pP', 'pS'], ['pU', 'pU2'], false),
            new FamillePlacable('f4', ['pPereDeP', 'pMereDeP'], ['pP', 'pFrereDeP'], false),
            new FamillePlacable('f5', ['pGrandPereDeP'], ['pPereDeP'], false),
        ];

        foreach ([$ids, array_reverse($ids)] as $ordre) {
            $vue = (new CalculAscendance())->vuDuLecteur($this->jeu($ordre, $familles, 'pO'));

            self::assertSame(ParenteDansLArbre::Famille, $vue->parentes['pN']);

            foreach (['pGpN', 'pGmN', 'pS', 'pU', 'pU2', 'pP'] as $id) {
                self::assertSame(ParenteDansLArbre::Conjoint, $vue->parentes[$id], $id.' : du sang de sa femme, ou conjoint de ce sang.');
                self::assertArrayHasKey($id, $vue->branches, $id);
            }

            foreach (['pPereDeP', 'pMereDeP', 'pFrereDeP', 'pGrandPereDeP'] as $id) {
                self::assertSame(ParenteDansLArbre::Alliance, $vue->parentes[$id], $id.' : aucun lien avec le lecteur.');
                self::assertSame('pP', $vue->ponts[$id], $id);
                self::assertArrayNotHasKey($id, $vue->branches, $id.' : une alliance ne reçoit aucune branche.');
            }
        }
    }

    /** Sans lecteur, personne n'est rangé : « famille de qui ? » n'a pas de réponse. */
    public function testSansLecteurAucuneParenteNEstCalculee(): void
    {
        $vue = (new CalculAscendance())->vuDuLecteur($this->jeu(['pA', 'pB'], [new FamillePlacable('f1', ['pB'], ['pA'], false)], ''));

        self::assertSame([], $vue->parentes);
        self::assertSame([], $vue->ponts);
        self::assertSame([], $vue->branches);
    }

    /**
     * @param list<string>          $ids
     * @param list<FamillePlacable> $familles
     */
    private function jeu(array $ids, array $familles, string $ego): JeuDePlacement
    {
        return new JeuDePlacement(
            array_map(static fn (string $id): PersonnePlacable => new PersonnePlacable(
                id: $id,
                prenom: 'X',
                nom: 'Y',
                usage: '',
                sexe: Sex::Inconnu,
                nais: '',
                deces: '',
                anNais: null,
                vivant: false,
                generation: 0,
            ), $ids),
            $familles,
            $ego,
        );
    }

    /**
     * L'arbre des captures, synthétique.
     *
     * @return array{0: list<string>, 1: list<FamillePlacable>, 2: list<string>}
     */
    private function arbre(): array
    {
        $ids = ['pL', 'pF', 'pM', 'pS', 'pP', 'pU', 'pU2'];
        $familles = [
            new FamillePlacable('fLM', ['pF', 'pM'], ['pL'], false),
            new FamillePlacable('fSP', ['pP', 'pS'], ['pU', 'pU2'], false),
        ];

        // Côté père de la lectrice : deux grands-parents, sans plus.
        $ids[] = 'pGpp';
        $ids[] = 'pGpm';
        $familles[] = new FamillePlacable('fF', ['pGpp', 'pGpm'], ['pF'], false);

        // Côté mère : Voulgre (mp) × Figarol (mm), cinq générations de Voulgre.
        $ids[] = 'pVoulgre1';
        $ids[] = 'pFigarol1';
        $familles[] = new FamillePlacable('fG1', ['pVoulgre1', 'pFigarol1'], ['pM', 'pS'], false);
        $enfant = 'pVoulgre1';
        for ($n = 2; $n <= 5; ++$n) {
            $ids[] = 'pVoulgre'.$n;
            $ids[] = 'pVoulgreF'.$n;
            $familles[] = new FamillePlacable('fV'.$n, ['pVoulgre'.$n, 'pVoulgreF'.$n], [$enfant], false);
            $enfant = 'pVoulgre'.$n;
        }

        // L'ascendance de P : six générations complètes (126 ancêtres).
        $ascendance = [];
        $file = [['pP', 1]];
        $k = 0;
        while ([] !== $file) {
            [$enfant, $g] = array_shift($file);
            if ($g > 6) {
                continue;
            }
            $pere = 'pA'.(++$k);
            $mere = 'pA'.(++$k);
            $ids[] = $pere;
            $ids[] = $mere;
            $ascendance[] = $pere;
            $ascendance[] = $mere;
            $familles[] = new FamillePlacable('fA'.$k, [$pere, $mere], [$enfant], false);
            $file[] = [$pere, $g + 1];
            $file[] = [$mere, $g + 1];
        }

        return [$ids, $familles, $ascendance];
    }
}
