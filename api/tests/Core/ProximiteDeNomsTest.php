<?php

declare(strict_types=1);

namespace App\Tests\Core;

use App\Module\Core\Service\CleDeRecherche;
use App\Module\Core\Service\ProximiteDeNoms;
use App\Module\Membership\Service\NormalisationDeNom;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * « Le même nom, à une faute de frappe près » — la règle partagée par le rattachement d'un
 * compte, la ressemblance dans l'arbre et la ressemblance des propositions.
 *
 * Trois écrans la lisent ; si elle variait de l'un à l'autre, le même « Tilleule » serait
 * rapproché de « Tilleul » ici et pas là. Ces tests la fixent une fois, au noyau.
 */
final class ProximiteDeNomsTest extends TestCase
{
    private ProximiteDeNoms $noms;

    protected function setUp(): void
    {
        $this->noms = new ProximiteDeNoms();
    }

    /** @return iterable<string, array{string, string, bool}> */
    public static function couples(): iterable
    {
        // Le cas réel qui a fixé le seuil, et ses voisins.
        yield 'une lettre de trop, au-delà de quatre lettres' => ['Tilleule', 'Tilleul', true];
        yield 'une lettre de moins' => ['Romari', 'Romarin', true];
        yield 'une lettre changée' => ['Sarriete', 'Sarriette', true];
        // En deçà de cinq lettres, une lettre change la famille.
        yield 'quatre lettres, une de changée' => ['Thym', 'Thyn', false];
        yield 'trois lettres, une de changée' => ['Bon', 'Bou', false];
        yield 'mais l’égalité vaut à toute longueur' => ['Thym', 'THYM', true];
        // Deux fautes ne sont plus une faute de frappe.
        yield 'deux lettres d’écart' => ['Tilleul', 'Tiloul', false];
        // Accents, casse et ponctuation ne comptent pas.
        yield 'accents repliés' => ['Génévrolle', 'GENEVROLLE', true];
        yield 'trait d’union contre espace' => ['Marne-Jaspine', 'marne jaspine', true];
        yield 'vide d’un côté' => ['', 'Tilleul', false];
        yield 'ponctuation seule' => ['---', 'Tilleul', false];
    }

    #[DataProvider('couples')]
    public function testDeuxNomsSontProchesAUneFauteDeFrappePres(string $a, string $b, bool $attendu): void
    {
        self::assertSame($attendu, $this->noms->proche($a, $b));
        self::assertSame($attendu, $this->noms->proche($b, $a), 'La proximité est symétrique.');
    }

    /**
     * Le piège que la classe existe pour éviter : `levenshtein()` compte des **octets**.
     * Sur les chaînes brutes, un `é` en vaut deux, et « Génévrole » serait à trois
     * « fautes » de « Genevrolle ». Replié d'abord, il n'en reste qu'une.
     */
    public function testLaDistanceSeCompteSurDesClesReplieesEtJamaisSurDesOctetsDAccent(): void
    {
        self::assertGreaterThan(1, levenshtein('Génévrole', 'Genevrolle'), 'Le piège : sur les octets bruts, la distance est fausse.');
        self::assertTrue($this->noms->proche('Génévrole', 'Genevrolle'));
    }

    public function testLeSeuilAppartientALAppelant(): void
    {
        self::assertFalse($this->noms->proche('Thym', 'Thyn'), 'Le défaut : cinq lettres.');
        self::assertTrue($this->noms->proche('Thym', 'Thyn', 4), 'Un appelant peut abaisser le seuil pour lui seul.');
        self::assertFalse($this->noms->proche('Tilleule', 'Tilleul', 9), 'Ou le relever.');
    }

    public function testUnPrenomIdentiquePasseAvantUnPrenomSeulementProche(): void
    {
        // « Dolomie » est proche de « Dolomite » et vient en premier ; « Marne » est
        // identique et vient ensuite. C'est « Marne » qu'on doit citer.
        self::assertSame(
            [1, 1, true],
            $this->noms->prenomEnCommun(['Dolomie', 'Marne'], ['DOLOMITE', 'MARNE']),
        );
    }

    public function testUnPrenomProcheSuffitQuandAucunNEstIdentique(): void
    {
        self::assertSame([0, 0, false], $this->noms->prenomEnCommun(['Pyroxenne'], ['Pyroxène']));
    }

    public function testSansPrenomEnCommunIlNYARien(): void
    {
        self::assertNull($this->noms->prenomEnCommun(['Natron'], ['Cobalt', 'Opale']));
        self::assertNull($this->noms->prenomEnCommun([], ['Cobalt']));
    }

    public function testLesJetonsGardentLeurGraphiePourEtreCites(): void
    {
        self::assertSame(
            [['JEAN', 'Jean'], ['MARIE', 'Marie'], ['NOEL', 'Noël']],
            $this->noms->jetonsAvecGraphie('Jean-Marie  Noël'),
        );
        self::assertSame(['JEAN', 'MARIE', 'NOEL'], $this->noms->jetons('Jean-Marie  Noël'));
        self::assertSame([], $this->noms->jetonsAvecGraphie(' - '));
    }

    /**
     * Le rattachement d'un compte délègue désormais ici **en gardant son seuil** : les
     * mêmes questions doivent recevoir les mêmes réponses qu'avant la remontée.
     */
    public function testLaRechercheDeRattachementRendLesMemesReponsesQuAvant(): void
    {
        $rattachement = new NormalisationDeNom(new CleDeRecherche());

        self::assertSame('GENEVROLLE', $rattachement->normaliser('Génévrolle'));
        self::assertSame(['MARNE', 'DOLOMITE'], $rattachement->jetons('Marne Dolomite'));
        self::assertTrue($rattachement->proche('TILLEULE', 'TILLEUL'));
        self::assertFalse($rattachement->proche('THYM', 'THYN'));
        self::assertTrue($rattachement->unPrenomEnCommun(['MARNE'], ['MARNE', 'DOLOMITE']));
        self::assertFalse($rattachement->unPrenomEnCommun(['OPALE'], ['MARNE', 'DOLOMITE']));
    }
}
