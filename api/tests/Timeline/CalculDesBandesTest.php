<?php

declare(strict_types=1);

namespace App\Tests\Timeline;

use App\Module\Timeline\Service\Bande;
use App\Module\Timeline\Service\Bornes;
use App\Module\Timeline\Service\CalculDesBandes;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Le découpage de l'axe en bandes — § 9.1, `debut(k) = bornes.min + k × bande`.
 *
 * ## Ce que ce fichier garde vraiment
 *
 * La récurrence du § 9.1 tient en deux lignes et se réécrit en une division sans qu'on y
 * pense — `ceil((max − min + 1) / bande)`. Les deux formes coïncident **presque** toujours,
 * et c'est le « presque » qui coûte : ce projet a déjà perdu du temps sur un `arrondi` posé
 * là où la source faisait un `tronqué`. Les cas ci-dessous sont choisis pour **séparer** les
 * deux formes, pas pour les illustrer.
 *
 * La seconde chose gardée est la **dernière bande partielle**. Elle a l'air fausse — sa
 * `fin` dépasse `bornes.max` —, elle est explicitement voulue par le § 9.1, et c'est
 * exactement le genre de résultat qu'un relecteur « corrige » en le rognant. Deux des cas
 * ci-dessous le vérifient nommément, et le troisième vérifie qu'une bande **exacte** ne se
 * met pas à déborder pour autant : sans lui, une implémentation qui ajouterait une bande de
 * trop passerait les deux premiers.
 *
 * ## Un test qui ne peut pas échouer ne prouve rien
 *
 * Chaque cas porte le **nombre** de bandes, la **première**, la **dernière** et le signe du
 * libellé. Rogner la dernière bande, arrondir, décaler `fin` d'une année ou changer le
 * demi-cadratin en trait d'union font tomber au moins un cas — vérifié en cassant chacune
 * des quatre à l'écriture.
 */
final class CalculDesBandesTest extends TestCase
{
    /**
     * Les bornes de l'exemple du § 9.1 : `{ "min": 1657, "max": 2037 }`.
     *
     * ⚠ **`1657` est la valeur de l'exemple, pas celle de la vraie base.** Recompté sur
     * `tree_person`, le premier millésime de cet arbre est **1655** ; l'exemple du § 9.1 est
     * inexact d'une paire d'années, comme il l'est sur la génération de Sureau (`-3` écrit,
     * `-4` en base). On garde ici la valeur du **contrat** : ce fichier vérifie la conformité
     * à la spécification, et sa vérification contre la vraie base est faite ailleurs.
     *
     * Le choix n'est pas gratuit pour autant : `2037 − 1657 + 1 = 381 = 3 × 127`, ce qui
     * donne des cas exacts calculables à la main — dont celui à `bande = 127`, le seul qui
     * distingue « dernière bande partielle » de « une bande de trop ».
     */
    private const int MIN = 1657;
    private const int MAX = 2037;

    /**
     * @param array<int, array{int, int}> $attendues clé = rang de la bande, volontairement creux
     */
    #[DataProvider('casDuDecoupage')]
    public function testLeDecoupageSuitLaRecurrenceDuParagraphe91(
        int $bande,
        int $nombreAttendu,
        array $attendues,
    ): void {
        $bandes = (new CalculDesBandes())->bandes(new Bornes(self::MIN, self::MAX), $bande);

        self::assertCount(
            $nombreAttendu,
            $bandes,
            \sprintf(
                'Pour bande=%d sur [%d, %d], la récurrence « debut(k) = min + k × bande tant que debut(k) ≤ max » '
                .'donne %d bandes. Un compte différent trahit une division ou un arrondi là où le § 9.1 itère.',
                $bande, self::MIN, self::MAX, $nombreAttendu,
            ),
        );

        // On affirme la taille de l'ensemble parcouru avant de le parcourir : un cas dont
        // le fournisseur rendrait un tableau vide passerait sinon en silence, en ne
        // vérifiant plus rien du tout.
        self::assertNotEmpty($attendues, 'Le cas doit nommer au moins une bande à vérifier.');

        foreach ($attendues as $rang => [$debut, $fin]) {
            $reelle = $bandes[$rang];
            self::assertSame($debut, $reelle->debut, \sprintf('debut de la bande %d (bande=%d)', $rang, $bande));
            self::assertSame($fin, $reelle->fin, \sprintf('fin de la bande %d (bande=%d)', $rang, $bande));
        }
    }

    /**
     * @return iterable<string, array{int, int, array<int, array{int, int}>}>
     */
    public static function casDuDecoupage(): iterable
    {
        // Le cas du § 9.1 lui-même : ses deux premières bandes y sont écrites en toutes
        // lettres (`1657–1681`, `1682–1706`) et servent ici de vérification littérale.
        //
        // Dernière bande : k = 15 → debut = 1657 + 375 = 2032, fin = 2056. Elle DÉPASSE
        // bornes.max de 19 ans, et c'est « correct et voulu ».
        yield 'bande=25 — la dernière déborde de 19 ans' => [25, 16, [
            0 => [1657, 1681],
            1 => [1682, 1706],
            15 => [2032, 2056],
        ]];

        // Seconde valeur exigée, avec un débordement d'une autre taille — une seule année.
        // k = 38 → debut = 2037, fin = 2046 ; et debut(38) = 2037 = bornes.max vérifie
        // encore la condition « ≤ », ce qu'un « < » écrit par distraction ferait tomber.
        yield 'bande=10 — la dernière bande commence exactement sur bornes.max' => [10, 39, [
            0 => [1657, 1666],
            38 => [2037, 2046],
        ]];

        // Le cas EXACT, celui qui empêche de « corriger » en ajoutant une bande de trop :
        // 381 = 3 × 127, donc k = 2 → debut = 1911, fin = 1911 + 126 = 2037 = bornes.max
        // pile. Et k = 3 donnerait debut = 2038 > 2037 : la boucle s'arrête.
        yield 'bande=127 — la dernière tombe pile sur bornes.max' => [127, 3, [
            0 => [1657, 1783],
            2 => [1911, 2037],
        ]];

        // Le degenerate case : une bande d'un an. 381 bandes, une par année, la dernière
        // sur bornes.max. Une division mal arrondie se voit tout de suite ici.
        yield 'bande=1 — une bande par année' => [1, 381, [
            0 => [1657, 1657],
            380 => [2037, 2037],
        ]];

        // Une bande plus large que tout l'axe : une seule bande, qui déborde largement.
        yield 'bande=500 — une seule bande, très débordante' => [500, 1, [
            0 => [1657, 2156],
        ]];
    }

    /**
     * Le cas dégénéré des bornes : `min = max`.
     *
     * Il se produit pour de bon — un lecteur dont le cercle ne montre qu'une personne, ou
     * un arbre vide, pour lequel {@see Bornes::surLesAnnees()} rend l'année courante des
     * deux côtés. La boucle doit rendre **une** bande, jamais zéro : un axe sans aucune
     * bande n'est pas dessinable, et le front n'a rien prévu pour ce cas.
     */
    public function testUnAxeDUneSeuleAnneeRendUneBande(): void
    {
        $bandes = (new CalculDesBandes())->bandes(new Bornes(2037, 2037), 25);

        self::assertCount(1, $bandes);
        self::assertSame(2037, $bandes[0]->debut);
        self::assertSame(2061, $bandes[0]->fin);
    }

    /**
     * Le libellé porte un **demi-cadratin sans espaces** — `1657–1681`, § 9.1.
     *
     * À ne pas confondre avec le séparateur de `ligneDates`, qui est le même signe
     * **entouré d'espaces**. On compare ici au point de code, pas à l'œil : un trait
     * d'union `-` (U+002D) et un demi-cadratin `–` (U+2013) se ressemblent dans un
     * terminal et ne sont pas le même caractère.
     */
    public function testLeLibelleEmploieUnDemiCadratinSansEspaces(): void
    {
        $libelle = (new Bande(1657, 1681))->libelle();

        self::assertSame('1657'."\u{2013}".'1681', $libelle);
        self::assertStringNotContainsString(' ', $libelle, 'Le libellé de bande n\'a pas d\'espaces (§ 9.1).');
        self::assertStringNotContainsString('-', $libelle, 'Trait d\'union U+002D au lieu du demi-cadratin U+2013.');
    }

    /**
     * Le garde-fou contre la boucle infinie.
     *
     * À `bande ≤ 0`, `debut(k)` cesse de progresser et la condition `debut(k) ≤ bornes.max`
     * reste vraie indéfiniment. La validation du § 9.1 refuse déjà la valeur en amont ;
     * cette exception est le second filet, et le tester est ce qui empêche qu'on le retire
     * un jour en le prenant pour une redondance.
     */
    public function testUneBandeNulleLeveAuLieuDeBoucler(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new CalculDesBandes())->bandes(new Bornes(self::MIN, self::MAX), 0);
    }

    /**
     * Les bornes retiennent l'année courante **si une barre est ouverte**, et pas sinon.
     *
     * Sans cette règle, l'axe s'arrêterait à la dernière année morte de l'arbre et les
     * barres ouvertes déborderaient de la dernière bande. C'est ce qui explique le
     * `"max": 2037` de l'exemple du § 9.1, qui n'est l'année de décès de personne.
     */
    public function testLAnneeCouranteEtendLAxeSeulementQuandUneBarreEstOuverte(): void
    {
        $avec = Bornes::surLesAnnees([1901, 1987], auMoinsUneBarreOuverte: true, anneeCourante: 2037);
        self::assertSame(1901, $avec->min);
        self::assertSame(2037, $avec->max);

        $sans = Bornes::surLesAnnees([1901, 1987], auMoinsUneBarreOuverte: false, anneeCourante: 2037);
        self::assertSame(1901, $sans->min);
        self::assertSame(1987, $sans->max, 'Sans barre ouverte, l\'axe s\'arrête au dernier décès connu.');

        $vide = Bornes::surLesAnnees([], auMoinsUneBarreOuverte: false, anneeCourante: 2037);
        self::assertSame(2037, $vide->min);
        self::assertSame(2037, $vide->max, 'Un lecteur qui ne voit aucune barre reçoit un axe d\'une bande, pas une réponse sans bornes.');
    }
}
