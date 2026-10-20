<?php

declare(strict_types=1);

namespace App\Tests\Membership;

use App\Module\Membership\Service\CalculDuCercle;
use App\Module\Membership\Service\Port\FamilleDeLArbre;
use App\Module\Membership\Service\Port\PersonneDeLArbre;
use App\Module\Membership\Service\Port\VueDeLArbre;
use PHPUnit\Framework\TestCase;

/**
 * La formule de l'ADR 0003, testée **sans base, sans session et sans pare-feu**.
 *
 * C'est ce que la séparation entre {@see CalculDuCercle} et
 * `Membership\Service\CercleDeVisibilite` existe pour permettre : la règle la plus
 * sensible du produit se vérifie ici en trois lignes par cas, et le fichier se relit en
 * entier quand on se demande ce que « le cercle » veut dire exactement.
 */
final class CalculDuCercleTest extends TestCase
{
    /**
     * `N = 3` : on remonte trois générations, puis on redescend **sans limite**.
     *
     * Le cousin issu de germain (50) est dans le cercle bien qu'il soit à cinq arêtes de
     * l'ego : c'est l'asymétrie voulue. « Mes cousins, même éloignés, sont ma famille ;
     * les ancêtres de mon beau-frère ne le sont pas. »
     */
    public function testLaFormuleRemonteDeNPuisRedescendSansLimite(): void
    {
        $cercle = $this->cercle(ArbreFamilial::EGO, 3);

        self::assertSame([1, 2, 10, 11, 20, 21, 30, 31, 40, 50, 100, 101, 110], $cercle);
        self::assertNotContains(ArbreFamilial::ETRANGER, $cercle);
        self::assertNotContains(ArbreFamilial::ANCETRE_ETRANGER, $cercle);
    }

    /**
     * **`N` change le résultat.** C'est ce qui rend `cercle.generations` un réglage et non
     * une constante : « le commanditaire a formulé ce chiffre avec hésitation, il doit
     * pouvoir le changer sans redéploiement » (§ 6.2 du dossier).
     *
     * À `N = 1`, la tante (40) sort du cercle : elle est fille des grands-parents, qui ne
     * sont plus dans `A(u)`.
     */
    public function testLeNombreDeGenerationsChangeLeResultat(): void
    {
        self::assertSame([30, 31, 100, 101, 110], $this->cercle(ArbreFamilial::EGO, 1));
        self::assertSame([1, 2, 10, 11, 20, 21, 30, 31, 40, 50, 100, 101, 110], $this->cercle(ArbreFamilial::EGO, 3));
        self::assertNotContains(ArbreFamilial::TANTE, $this->cercle(ArbreFamilial::EGO, 1));
    }

    /**
     * ═══════════════════════════════════════════════════════════════════════════
     *  LE PIÈGE QUE L'ADR 0003 NOMME : les conjoints ne propagent rien.
     * ═══════════════════════════════════════════════════════════════════════════.
     *
     * « Un agent qui itère jusqu'à stabilité implémente autre chose que ce qui est
     * demandé, et c'est ce qui sépare un cercle de trente-cinq personnes d'un cercle qui
     * avale la moitié de l'arbre au premier remariage. »
     *
     * L'arbre du test : l'ego épouse Spinel, dont la famille (parents, frère, et le
     * conjoint du frère) n'a aucun autre lien avec lui. Un point fixe la ferait entrer en
     * entier. Une passe unique s'arrête à Spinel.
     */
    public function testLesConjointsSontAjoutesEnUneSeulePasseEtNePropagentRien(): void
    {
        $personnes = [
            $this->personne(100, 'Natron', 'Romarin'),
            $this->personne(101, 'Spinel', 'Thym'),
            $this->personne(200, 'Beau', 'Pere'),
            $this->personne(201, 'Belle', 'Mere'),
            $this->personne(202, 'Frere', 'Thym'),
            $this->personne(203, 'Belle', 'Soeur'),
        ];

        $familles = [
            new FamilleDeLArbre([100, 101], []),
            // Les parents de Spinel, et son frère.
            new FamilleDeLArbre([200, 201], [101, 202]),
            // Le frère de Spinel et sa conjointe : deux crans au-delà du conjoint.
            new FamilleDeLArbre([202, 203], []),
        ];

        $cercle = (new CalculDuCercle())->cercle(new VueDeLArbre($personnes, $familles), 100, 3);

        self::assertSame([100, 101], $cercle, 'Le conjoint entre ; sa famille reste dehors.');
        self::assertNotContains(200, $cercle, 'Les parents du conjoint ne sont pas des ancêtres du lecteur.');
        self::assertNotContains(202, $cercle, 'Le frère du conjoint n’est pas un descendant de A(u).');
        self::assertNotContains(203, $cercle, 'Le conjoint d’un conjoint n’entre jamais.');
    }

    /**
     * `N = 0` **reste un cercle** : `A(u) = { u }`, puis la passe des conjoints y ajoute
     * le conjoint.
     *
     * Ce test ne décrit pas un cas d'usage — le réglage est borné à `[1, 6]` et personne
     * ne peut poser `N = 0`. Il existe pour **prouver l'argument** de l'ADR 0003 et du
     * § 12 : « il ne faut pas implémenter le régime du compte en attente comme un cercle
     * avec `N = 0` ; un `N = 0` contient encore `u` et ses conjoints, donc il laisserait
     * sortir la fiche d'une personne vivante ».
     *
     * Trois fiches de personnes vivantes sortent ci-dessous, là où le régime `Aucun` n'en
     * laisse sortir aucune. C'est mesuré, et c'est pourquoi le régime est porté par une
     * énumération sans profondeur plutôt que par un entier qu'on pourrait mettre à zéro.
     */
    public function testUnNZeroResteUnCercleEtLaisseSortirDesFichesVivantes(): void
    {
        $cercle = $this->cercle(ArbreFamilial::EGO, 0);

        self::assertSame(
            [ArbreFamilial::EGO, ArbreFamilial::CONJOINT, ArbreFamilial::ENFANT],
            $cercle,
            'A(u) = {u}, ses descendants, puis son conjoint : jamais l’ensemble vide.',
        );
    }

    /**
     * Une boucle de consanguinité ne fait pas boucler le calcul.
     *
     * L'implexe existe dans cet arbre — `graphe.py` portait déjà un ensemble `vus` contre
     * les boucles. Les deux parcours sont en largeur avec un ensemble de visités : une
     * boucle y est une arête déjà vue, pas une pile qui déborde.
     */
    public function testUneBoucleDeConsanguiniteNeFaitPasBoucler(): void
    {
        $personnes = [
            $this->personne(1, 'A', 'Un'),
            $this->personne(2, 'B', 'Deux'),
            $this->personne(3, 'C', 'Trois'),
        ];

        // 1 est parent de 2, 2 est parent de 3, et 3 est parent de 1 : un cycle franc.
        $familles = [
            new FamilleDeLArbre([1], [2]),
            new FamilleDeLArbre([2], [3]),
            new FamilleDeLArbre([3], [1]),
        ];

        $cercle = (new CalculDuCercle())->cercle(new VueDeLArbre($personnes, $familles), 1, 3);

        self::assertSame([1, 2, 3], $cercle);
    }

    /** Une personne qui n'est dans aucune famille est seule dans son cercle. */
    public function testUnePersonneIsoleeEstSeuleDansSonCercle(): void
    {
        $cercle = $this->cercle(ArbreFamilial::ETRANGER, 3);

        self::assertSame([ArbreFamilial::ETRANGER], $cercle);
    }

    /**
     * L'arbre en mémoire est statique, et il tient lieu de `PortArbre` à **tout**
     * l'environnement de test : laissé peuplé, il fait le cercle des classes qui suivent
     * dans le même processus. Sous ParaTest, les tests de `Tree` (`ArbreVuDuLecteurTest`,
     * `RechercheParPlusieursMotsTest`) tombaient selon le voisinage — le cousin
     * confidentiel entrait dans le cercle du lecteur.
     */
    protected function tearDown(): void
    {
        Double\ArbreEnMemoire::reinitialiser();
    }

    /** @return list<int> */
    private function cercle(int $racine, int $generations): array
    {
        ArbreFamilial::poser();

        return (new CalculDuCercle())->cercle((new Double\ArbreEnMemoire())->vue(), $racine, $generations);
    }

    private function personne(int $id, string $prenoms, string $nom): PersonneDeLArbre
    {
        return new PersonneDeLArbre($id, $prenoms, $nom, $prenoms.' '.$nom, null, '', true, false);
    }
}
