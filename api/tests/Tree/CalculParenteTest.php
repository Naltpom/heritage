<?php

declare(strict_types=1);

namespace App\Tests\Tree;

use App\Module\Core\Enum\Sex;
use App\Module\Tree\Service\CalculParente;
use App\Module\Tree\ValueObject\FamillePlacable;
use App\Module\Tree\ValueObject\IdentifiantDeNoeud;
use App\Module\Tree\ValueObject\JeuDePlacement;
use App\Module\Tree\ValueObject\PersonnePlacable;
use PHPUnit\Framework\TestCase;

/**
 * Le calcul des liens de parenté, sur des arbres montés à la main.
 *
 * **Portage de `parente_sang()` de `build_data.py`.** L'équivalence a été établie d'abord
 * hors test, par comparaison libellé par libellé avec une réexécution du script Python sur
 * `arbre-romarin-v5.ged` : **274 comparés, 0 divergence**. Ce test ne peut pas rejouer
 * cette comparaison — les données réelles ne sont pas versionnées (§ 9 du dossier de
 * mission) —, il fige donc un cas par règle, dont les deux qui ont coûté cher :
 * l'ancêtre commun atteint par deux chemins, et la pièce rapportée nommée par son conjoint.
 *
 * Le service ne touche pas la base : il prend un {@see JeuDePlacement}, ce qui le rend
 * nourrissable à la main — même contrat que le moteur de placement, même raison.
 */
final class CalculParenteTest extends TestCase
{
    /**
     * L'ascendance directe, dans les deux sens, avec les préfixes d'éloignement.
     */
    public function testLaLigneDirecteEstNommeeAvecSesPrefixes(): void
    {
        // p1 ← p2 ← p3 ← p4 ← p5 : quatre générations au-dessus de la racine.
        $jeu = $this->jeu(
            ['p1' => Sex::Masculin, 'p2' => Sex::Masculin, 'p3' => Sex::Feminin, 'p4' => Sex::Masculin, 'p5' => Sex::Feminin],
            [
                ['epoux' => ['p2'], 'enfants' => ['p1']],
                ['epoux' => ['p3'], 'enfants' => ['p2']],
                ['epoux' => ['p4'], 'enfants' => ['p3']],
                ['epoux' => ['p5'], 'enfants' => ['p4']],
            ],
            'p1',
        );

        $libelles = (new CalculParente())->pourTous($jeu);

        self::assertSame('toi', $libelles['p1']);
        self::assertSame('père', $libelles['p2']);
        self::assertSame('grand-mère', $libelles['p3']);
        self::assertSame('arrière-grand-père', $libelles['p4']);
        self::assertSame('arrière-arrière-grand-mère', $libelles['p5']);
    }

    public function testLaDescendanceEstNommeeDepuisLaRacine(): void
    {
        $jeu = $this->jeu(
            ['p1' => Sex::Feminin, 'p2' => Sex::Masculin, 'p3' => Sex::Feminin],
            [
                ['epoux' => ['p1'], 'enfants' => ['p2']],
                ['epoux' => ['p2'], 'enfants' => ['p3']],
            ],
            'p1',
        );

        $libelles = (new CalculParente())->pourTous($jeu);

        self::assertSame('fils', $libelles['p2']);
        self::assertSame('petite-fille', $libelles['p3']);
    }

    public function testLaFratrieLesOnclesEtLesNeveux(): void
    {
        // p9 a deux enfants, p1 (racine) et p2 ; p2 a un enfant p3.
        // p9 a lui-même un frère p4, dont l'enfant est p5.
        $jeu = $this->jeu(
            [
                'p1' => Sex::Masculin, 'p2' => Sex::Feminin, 'p3' => Sex::Masculin,
                'p4' => Sex::Masculin, 'p5' => Sex::Feminin, 'p9' => Sex::Masculin, 'p10' => Sex::Masculin,
            ],
            [
                ['epoux' => ['p9'], 'enfants' => ['p1', 'p2']],
                ['epoux' => ['p2'], 'enfants' => ['p3']],
                ['epoux' => ['p10'], 'enfants' => ['p9', 'p4']],
                ['epoux' => ['p4'], 'enfants' => ['p5']],
            ],
            'p1',
        );

        $libelles = (new CalculParente())->pourTous($jeu);

        self::assertSame('sœur', $libelles['p2']);
        self::assertSame('neveu', $libelles['p3']);
        self::assertSame('oncle', $libelles['p4']);
        self::assertSame('cousine germaine', $libelles['p5']);
        self::assertSame('grand-père', $libelles['p10']);
    }

    /**
     * Le degré et l'éloignement d'un cousinage, qui sont deux choses distinctes.
     */
    public function testUnCousinageEloigneNommeSonDegreEtSonEcart(): void
    {
        // p1 et p6 descendent tous deux de p20, mais à des profondeurs différentes :
        // p1 à 2 générations, p6 à 3. Cousin germain, éloigné d'une génération.
        $jeu = $this->jeu(
            [
                'p1' => Sex::Masculin, 'p2' => Sex::Masculin, 'p5' => Sex::Masculin,
                'p6' => Sex::Masculin, 'p7' => Sex::Masculin, 'p20' => Sex::Masculin,
            ],
            [
                ['epoux' => ['p2'], 'enfants' => ['p1']],
                ['epoux' => ['p20'], 'enfants' => ['p2', 'p5']],
                ['epoux' => ['p5'], 'enfants' => ['p7']],
                ['epoux' => ['p7'], 'enfants' => ['p6']],
            ],
            'p1',
        );

        $libelles = (new CalculParente())->pourTous($jeu);

        self::assertSame('cousin germain', $libelles['p7']);
        self::assertSame('cousin germain, éloigné de 1 génération', $libelles['p6']);
    }

    /**
     * **L'implexe.** La racine descend deux fois du même couple : par son père et par sa
     * mère, qui sont cousins germains. La remontée ne doit ni boucler, ni compter
     * l'ancêtre deux fois, ni le nommer par le chemin le plus long.
     *
     * C'est la topologie de la fixture GEDCOM, transposée : Julien et Hélène sont cousins
     * germains par leurs mères, filles de Gustave.
     */
    public function testUnImplexeNommeLAncetreParLeCheminLePlusCourt(): void
    {
        $jeu = $this->jeu(
            [
                'p1' => Sex::Feminin,  // racine
                'p2' => Sex::Masculin, // père
                'p3' => Sex::Feminin,  // mère
                'p5' => Sex::Feminin,  // mère du père
                'p7' => Sex::Feminin,  // mère de la mère
                'p8' => Sex::Masculin, // père de p5 et p7 — atteint par deux chemins
            ],
            [
                ['epoux' => ['p2', 'p3'], 'enfants' => ['p1']],
                ['epoux' => ['p5'], 'enfants' => ['p2']],
                ['epoux' => ['p7'], 'enfants' => ['p3']],
                ['epoux' => ['p8'], 'enfants' => ['p5', 'p7']],
            ],
            'p1',
        );

        $libelles = (new CalculParente())->pourTous($jeu);

        self::assertSame('père', $libelles['p2']);
        self::assertSame('mère', $libelles['p3']);
        self::assertSame('grand-mère', $libelles['p5']);
        self::assertSame('grand-mère', $libelles['p7']);
        // Trois générations par l'un comme par l'autre chemin : le libellé est le même,
        // et le calcul s'arrête.
        self::assertSame('arrière-grand-père', $libelles['p8']);
    }

    /**
     * **Le cycle le plus court possible** : deux personnes déclarées ancêtres l'une de
     * l'autre. Donnée absurde mais saisissable — une filiation validée à l'envers suffit.
     * Sans garde-fou, la remontée ne termine jamais et la requête part en boucle infinie.
     */
    public function testUnCycleDAscendanceNeFaitPasBoucler(): void
    {
        $jeu = $this->jeu(
            ['p1' => Sex::Masculin, 'p2' => Sex::Masculin],
            [
                ['epoux' => ['p2'], 'enfants' => ['p1']],
                ['epoux' => ['p1'], 'enfants' => ['p2']],
            ],
            'p1',
        );

        $libelles = (new CalculParente())->pourTous($jeu);

        self::assertSame('toi', $libelles['p1']);
        self::assertArrayHasKey('p2', $libelles);
    }

    /**
     * La pièce rapportée est nommée par son conjoint, et la seconde passe lit la
     * **première figée** — sans quoi on écrirait « épouse de l'époux de ta tante ».
     */
    public function testUnePieceRapporteeEstNommeeParSonConjoint(): void
    {
        $jeu = $this->jeu(
            ['p1' => Sex::Masculin, 'p2' => Sex::Masculin, 'p4' => Sex::Masculin, 'p5' => Sex::Feminin, 'p9' => Sex::Masculin],
            [
                ['epoux' => ['p2'], 'enfants' => ['p1']],
                ['epoux' => ['p9'], 'enfants' => ['p2', 'p4']],
                ['epoux' => ['p4', 'p5'], 'enfants' => []],
            ],
            'p1',
        );

        $libelles = (new CalculParente())->pourTous($jeu);

        self::assertSame('oncle', $libelles['p4']);
        self::assertSame('épouse de ton oncle', $libelles['p5']);
    }

    /** Sans ancêtre commun ni conjoint du sang, on ne prétend rien. */
    public function testSansAncetreCommunNiConjointOnDitAlliance(): void
    {
        $jeu = $this->jeu(
            ['p1' => Sex::Masculin, 'p2' => Sex::Masculin],
            [['epoux' => ['p2'], 'enfants' => []]],
            'p1',
        );

        self::assertSame('relié par alliance', (new CalculParente())->pourTous($jeu)['p2']);
    }

    /** L'accès unitaire rend la même chose que le calcul complet, et `null` hors de l'arbre. */
    public function testLAccesUnitaireSuitLeCalculComplet(): void
    {
        $jeu = $this->jeu(
            ['p1' => Sex::Masculin, 'p2' => Sex::Feminin],
            [['epoux' => ['p2'], 'enfants' => ['p1']]],
            'p1',
        );

        $service = new CalculParente();

        self::assertSame('mère', $service->pour($jeu, 'p2'));
        self::assertNull($service->pour($jeu, 'p999'));
    }

    /**
     * Les identifiants portent leur préfixe, et c'est ce qui évite la collision :
     * `tree_person.id` et `tree_family.id` sont deux séquences indépendantes, donc la
     * personne 1 et la famille 1 existent toutes les deux.
     */
    public function testLesIdentifiantsDeFamilleNeCollisionnentPasAvecCeuxDePersonne(): void
    {
        self::assertSame('p1', IdentifiantDeNoeud::personne(1));
        self::assertSame('f1', IdentifiantDeNoeud::famille(1));

        $jeu = $this->jeu(
            ['p1' => Sex::Masculin, 'p2' => Sex::Feminin],
            [['epoux' => ['p2'], 'enfants' => ['p1']]],
            'p1',
        );

        // La famille porte `f1` là où la racine porte `p1` : aucun des deux ne doit
        // écraser l'autre dans les libellés, qui ne concernent que les personnes.
        self::assertSame(['p1', 'p2'], array_keys((new CalculParente())->pourTous($jeu)));
    }

    /**
     * @param array<string, Sex>                                      $sexes
     * @param list<array{epoux: list<string>, enfants: list<string>}> $familles
     */
    private function jeu(array $sexes, array $familles, string $ego): JeuDePlacement
    {
        $personnes = [];

        foreach ($sexes as $id => $sexe) {
            $personnes[] = new PersonnePlacable(
                id: $id,
                prenom: strtoupper($id),
                nom: 'Essai',
                usage: '',
                sexe: $sexe,
                nais: '',
                deces: '',
                anNais: null,
                vivant: false,
                generation: 0,
            );
        }

        $blocs = [];
        $rang = 1;

        foreach ($familles as $famille) {
            $id = IdentifiantDeNoeud::famille($rang++);
            $blocs[] = new FamillePlacable(
                id: $id,
                epoux: $famille['epoux'],
                enfants: $famille['enfants'],
                aDivorce: false,
            );
        }

        return new JeuDePlacement($personnes, $blocs, $ego);
    }
}
