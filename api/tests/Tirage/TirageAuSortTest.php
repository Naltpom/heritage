<?php

declare(strict_types=1);

namespace App\Tests\Tirage;

use App\Module\Tirage\Exception\ErreurDeTirage;
use App\Module\Tirage\Service\TirageAuSort;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Le tirage lui-même, sans base ni HTTP : ses trois règles, et le relâchement de la
 * mémoire quand elle rend le tirage impossible.
 *
 * Chaque propriété est éprouvée sur **cent graines** : un tirage correct une fois peut
 * l'être par chance, et c'est justement le hasard qu'on teste.
 */
final class TirageAuSortTest extends TestCase
{
    private const int GRAINES = 100;

    /** @return iterable<string, array{int}> */
    public static function graines(): iterable
    {
        foreach ([1, 7, 42, 2026, 31415] as $graine) {
            yield 'graine '.$graine => [$graine];
        }
    }

    #[DataProvider('graines')]
    public function testChacunOffreEtRecoitExactementUneFoisEtJamaisASoiMeme(int $graine): void
    {
        $personnes = [11, 12, 13, 14, 15, 16, 17];
        $resultat = (new TirageAuSort())->tirer($personnes, [], [], 0, $this->hasard($graine));

        self::assertSame($personnes, $this->triees(array_keys($resultat->attributions)), 'Chacun offre.');
        self::assertSame($personnes, $this->triees(array_values($resultat->attributions)), 'Chacun reçoit, une seule fois.');

        foreach ($resultat->attributions as $donneur => $receveur) {
            self::assertNotSame($donneur, $receveur, 'Personne ne s’offre un cadeau à soi-même.');
        }
    }

    public function testUnConjointNEstJamaisTire(): void
    {
        $couples = [[1, 2], [3, 4], [5, 6]];

        for ($graine = 0; $graine < self::GRAINES; ++$graine) {
            $resultat = (new TirageAuSort())->tirer([1, 2, 3, 4, 5, 6], $couples, [], 0, $this->hasard($graine));

            foreach ($couples as [$un, $autre]) {
                self::assertNotSame($autre, $resultat->attributions[$un], \sprintf('Graine %d : %d a tiré son conjoint.', $graine, $un));
                self::assertNotSame($un, $resultat->attributions[$autre], \sprintf('Graine %d : %d a tiré son conjoint.', $graine, $autre));
            }
        }
    }

    /**
     * La règle de mémoire, quand elle est tenable : jamais la même personne que les deux
     * dernières années.
     */
    public function testOnNeRetombePasSurLaMemePersonneQuandCEstPossible(): void
    {
        $personnes = [1, 2, 3, 4, 5, 6, 7, 8];
        $anDernier = [1 => 2, 2 => 3, 3 => 4, 4 => 5, 5 => 6, 6 => 7, 7 => 8, 8 => 1];
        $ilYaDeuxAns = [1 => 3, 2 => 4, 3 => 5, 4 => 6, 5 => 7, 6 => 8, 7 => 1, 8 => 2];

        for ($graine = 0; $graine < self::GRAINES; ++$graine) {
            $resultat = (new TirageAuSort())->tirer($personnes, [], [$anDernier, $ilYaDeuxAns], 2, $this->hasard($graine));

            self::assertSame(2, $resultat->memoireAppliquee);

            foreach ($resultat->attributions as $donneur => $receveur) {
                self::assertNotSame($anDernier[$donneur], $receveur, \sprintf('Graine %d : %d retombe sur la personne de l’an dernier.', $graine, $donneur));
                self::assertNotSame($ilYaDeuxAns[$donneur], $receveur, \sprintf('Graine %d : %d retombe sur la personne d’il y a deux ans.', $graine, $donneur));
            }
        }
    }

    /**
     * La mémoire ne remonte pas plus loin qu'on le lui demande : avec une mémoire de 1,
     * l'année d'avant-dernière est de nouveau permise.
     */
    public function testLaMemoireNeRemontePasPlusLoinQueDemande(): void
    {
        // Quatre personnes, deux couples : chacun n'a que deux candidats. L'an dernier en
        // interdit un, il n'en reste qu'un — le tirage est donc entièrement déterminé.
        $couples = [[1, 2], [3, 4]];
        $anDernier = [1 => 3, 3 => 2, 2 => 4, 4 => 1];
        $ilYaDeuxAns = [1 => 4, 4 => 2, 2 => 3, 3 => 1];

        $resultat = (new TirageAuSort())->tirer([1, 2, 3, 4], $couples, [$anDernier, $ilYaDeuxAns], 1, $this->hasard(3));

        self::assertSame(1, $resultat->memoireAppliquee);
        self::assertSame($this->triesParCle($ilYaDeuxAns), $this->triesParCle($resultat->attributions));
    }

    /**
     * Le petit groupe : la mémoire demandée est intenable, le tirage **oublie les années
     * les plus anciennes** plutôt que de refuser — et le dit.
     */
    public function testLaMemoireSeRelacheQuandElleRendLeTirageImpossible(): void
    {
        $couples = [[1, 2], [3, 4]];
        $anDernier = [1 => 3, 3 => 2, 2 => 4, 4 => 1];
        $ilYaDeuxAns = [1 => 4, 4 => 2, 2 => 3, 3 => 1];

        for ($graine = 0; $graine < self::GRAINES; ++$graine) {
            $resultat = (new TirageAuSort())->tirer([1, 2, 3, 4], $couples, [$anDernier, $ilYaDeuxAns], 2, $this->hasard($graine));

            self::assertSame(1, $resultat->memoireAppliquee, 'Deux années sont intenables à quatre en deux couples : on n’en garde qu’une.');

            foreach ($resultat->attributions as $donneur => $receveur) {
                self::assertNotSame($anDernier[$donneur], $receveur, 'L’année la plus récente reste respectée.');
            }
        }
    }

    /** Trois personnes dont un couple : le troisième ne peut pas recevoir deux cadeaux. */
    public function testUnTirageImpossibleMemeSansMemoireEstRefuse(): void
    {
        try {
            (new TirageAuSort())->tirer([1, 2, 3], [[1, 2]], [], 3, $this->hasard(1));
            self::fail('Le tirage aurait dû être refusé.');
        } catch (ErreurDeTirage $erreur) {
            self::assertSame('tirage_impossible', $erreur->codeErreur());
            self::assertSame(409, $erreur->statutHttp());
        }
    }

    public function testIlFautAuMoinsTroisParticipants(): void
    {
        try {
            (new TirageAuSort())->tirer([1, 2], [], [], 0, $this->hasard(1));
            self::fail('Deux participants ne suffisent pas.');
        } catch (ErreurDeTirage $erreur) {
            self::assertSame('participants_insuffisants', $erreur->codeErreur());
            self::assertSame(['minimum' => 3], $erreur->details());
        }
    }

    /** Le même générateur, la même graine : le même tirage. C'est ce qui rend le reste testable. */
    public function testLeTirageEstReproductibleAGraineEgale(): void
    {
        $personnes = range(1, 12);

        self::assertSame(
            (new TirageAuSort())->tirer($personnes, [], [], 0, $this->hasard(99))->attributions,
            (new TirageAuSort())->tirer($personnes, [], [], 0, $this->hasard(99))->attributions,
        );
    }

    /** Le hasard est bien du hasard : sur cent graines, plus d'un tirage différent. */
    public function testDesGrainesDifferentesDonnentDesTiragesDifferents(): void
    {
        $vus = [];

        for ($graine = 0; $graine < self::GRAINES; ++$graine) {
            $vus[json_encode($this->triesParCle((new TirageAuSort())->tirer(range(1, 6), [], [], 0, $this->hasard($graine))->attributions), \JSON_THROW_ON_ERROR)] = true;
        }

        self::assertGreaterThan(20, \count($vus));
    }

    public function testLaMemoireConseillee(): void
    {
        self::assertSame(4, TirageAuSort::memoireConseillee(6, false));
        self::assertSame(3, TirageAuSort::memoireConseillee(6, true));
        self::assertSame(0, TirageAuSort::memoireConseillee(3, true));
        self::assertSame(0, TirageAuSort::memoireConseillee(1, false));
    }

    private function hasard(int $graine): Randomizer
    {
        return new Randomizer(new Mt19937($graine));
    }

    /**
     * @param list<int> $valeurs
     *
     * @return list<int>
     */
    private function triees(array $valeurs): array
    {
        sort($valeurs);

        return $valeurs;
    }

    /**
     * @param array<int, int> $attributions
     *
     * @return array<int, int>
     */
    private function triesParCle(array $attributions): array
    {
        ksort($attributions);

        return $attributions;
    }
}
