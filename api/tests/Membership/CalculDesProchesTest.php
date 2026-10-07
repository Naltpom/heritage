<?php

declare(strict_types=1);

namespace App\Tests\Membership;

use App\Module\Membership\Service\CalculDesProches;
use App\Module\Membership\Service\Port\FamilleDeLArbre;
use App\Module\Membership\Service\Port\PersonneDeLArbre;
use App\Module\Membership\Service\Port\VueDeLArbre;
use PHPUnit\Framework\TestCase;

/**
 * **Les proches** de la réciprocité des vivants (ADR 0016, révision du 2026-10-07), sans
 * base ni session.
 *
 * L'arbre, autour de l'ego (11) :
 *
 * - ascendants : ses parents 1 et 2, ses grands-parents paternels 90 et 91 ;
 * - fratrie : son frère 10, et son demi-frère 3 (fils de 2 et de 4) ;
 * - conjoints : sa conjointe 52 (parents 50 et 51, frère 80) ; son ex 13, divorcée
 *   (parents 60 et 61), mère de son enfant 14 ;
 * - descendants : son fils 20 (épouse 21), son petit-fils 30 (épouse 31) ;
 * - et ce qui n'est pas proche : la tante 70 (sœur du père) et son fils 71, cousin.
 */
final class CalculDesProchesTest extends TestCase
{
    private const int PERE = 1;
    private const int MERE = 2;
    private const int DEMI_FRERE = 3;
    private const int AUTRE_PERE = 4;
    private const int FRERE = 10;
    private const int EGO = 11;
    private const int EX = 13;
    private const int FILS = 20;
    private const int BRU = 21;
    private const int PETIT_FILS = 30;
    private const int EPOUSE_DU_PETIT_FILS = 31;
    private const int BEAU_PERE = 50;
    private const int BELLE_MERE = 51;
    private const int CONJOINTE = 52;
    private const int EX_BEAU_PERE = 60;
    private const int EX_BELLE_MERE = 61;
    private const int GRAND_PERE = 90;
    private const int GRAND_MERE = 91;
    private const int TANTE = 70;
    private const int COUSIN = 71;
    private const int BEAU_FRERE = 80;
    private const int ENFANT_DE_L_EX = 14;

    public function testLesProchesDeLEgo(): void
    {
        $proches = $this->proches(self::EGO);

        self::assertSame(
            [
                self::PERE, self::MERE, self::DEMI_FRERE, self::FRERE, self::ENFANT_DE_L_EX,
                self::FILS, self::BRU, self::PETIT_FILS, self::EPOUSE_DU_PETIT_FILS,
                self::BEAU_PERE, self::BELLE_MERE, self::CONJOINTE, self::GRAND_PERE, self::GRAND_MERE,
            ],
            $proches,
        );
    }

    public function testCeQuiNEnEstPas(): void
    {
        $proches = $this->proches(self::EGO);

        self::assertNotContains(self::EGO, $proches, 'On n’est pas son propre proche : sa fiche se voit par une autre règle.');
        self::assertNotContains(self::EX, $proches, 'Un ex-conjoint n’est plus un proche.');
        self::assertNotContains(self::EX_BEAU_PERE, $proches, 'Ses parents non plus.');
        self::assertNotContains(self::TANTE, $proches);
        self::assertNotContains(self::COUSIN, $proches);
        self::assertNotContains(self::BEAU_FRERE, $proches, 'Le frère du conjoint n’est pas un proche.');
        self::assertNotContains(self::AUTRE_PERE, $proches, 'Le père d’un demi-frère non plus.');
    }

    /** `v ∈ P(u) ⇔ u ∈ P(v)`, pour tous les couples de l'arbre. */
    public function testLaRelationEstSymetrique(): void
    {
        $arbre = $this->arbre();
        $calcul = new CalculDesProches();

        foreach (array_keys($arbre->personnes) as $u) {
            foreach (array_keys($calcul->proches($arbre, $u)) as $v) {
                self::assertArrayHasKey($u, $calcul->proches($arbre, $v), \sprintf('%d est proche de %d, mais pas l’inverse.', $v, $u));
            }
        }
    }

    public function testUnePersonneInconnueNAPasDeProches(): void
    {
        self::assertSame([], (new CalculDesProches())->proches($this->arbre(), 999));
    }

    public function testLImplexeNeFaitPasBoucler(): void
    {
        $arbre = new VueDeLArbre(
            [$this->personne(1), $this->personne(2), $this->personne(3)],
            [new FamilleDeLArbre([1], [2]), new FamilleDeLArbre([2], [3]), new FamilleDeLArbre([3], [1])],
        );

        self::assertSame([2, 3], $this->trier((new CalculDesProches())->proches($arbre, 1)));
    }

    /** @return list<int> */
    private function proches(int $personne): array
    {
        return $this->trier((new CalculDesProches())->proches($this->arbre(), $personne));
    }

    /**
     * @param array<int, true> $ensemble
     *
     * @return list<int>
     */
    private function trier(array $ensemble): array
    {
        $ids = array_keys($ensemble);
        sort($ids);

        return $ids;
    }

    private function arbre(): VueDeLArbre
    {
        $ids = [1, 2, 3, 4, 10, 11, 13, 14, 20, 21, 30, 31, 50, 51, 52, 60, 61, 70, 71, 80, 90, 91];

        return new VueDeLArbre(
            array_map($this->personne(...), $ids),
            [
                new FamilleDeLArbre([self::GRAND_PERE, self::GRAND_MERE], [self::PERE, self::TANTE]),
                new FamilleDeLArbre([self::TANTE], [self::COUSIN]),
                new FamilleDeLArbre([self::PERE, self::MERE], [self::FRERE, self::EGO]),
                new FamilleDeLArbre([self::AUTRE_PERE, self::MERE], [self::DEMI_FRERE]),
                new FamilleDeLArbre([self::EGO, self::EX], [self::ENFANT_DE_L_EX], rompue: true),
                new FamilleDeLArbre([self::EX_BEAU_PERE, self::EX_BELLE_MERE], [self::EX]),
                new FamilleDeLArbre([self::BEAU_PERE, self::BELLE_MERE], [self::CONJOINTE, self::BEAU_FRERE]),
                new FamilleDeLArbre([self::EGO, self::CONJOINTE], [self::FILS]),
                new FamilleDeLArbre([self::FILS, self::BRU], [self::PETIT_FILS]),
                new FamilleDeLArbre([self::PETIT_FILS, self::EPOUSE_DU_PETIT_FILS], []),
            ],
        );
    }

    private function personne(int $id): PersonneDeLArbre
    {
        return new PersonneDeLArbre($id, 'P'.$id, 'Essai', 'P'.$id.' Essai', null, '', true, false);
    }
}
