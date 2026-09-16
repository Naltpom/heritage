<?php

declare(strict_types=1);

namespace App\Tests\Gedcom;

use App\Module\Gedcom\Dto\FichierGedcom;
use App\Module\Gedcom\Service\CollecteurAnomalies;
use App\Module\Gedcom\Service\LecteurGedcom;
use PHPUnit\Framework\TestCase;

/**
 * Le lexeur, sur des lignes écrites à la main.
 *
 * Les deux premiers tests portent sur le piège qui a coûté le plus cher dans ce projet :
 * `CONC` recolle **sans séparateur**, `CONT` ajoute un saut de ligne. Les inverser
 * transforme les notes de recherche — les lignes les plus précieuses du fichier — en
 * bouillie, et rien ne le signale.
 */
final class LecteurGedcomTest extends TestCase
{
    public function testConcRecolleSansSeparateur(): void
    {
        $fichier = $this->lire("0 @I1@ INDI\n1 NOTE Un mot coupé en deux : indivi\n2 CONC sible.\n");

        self::assertSame('Un mot coupé en deux : indivisible.', $fichier->enregistrements[0]->texteDe('NOTE'));
    }

    public function testContAjouteUneLigne(): void
    {
        $fichier = $this->lire("0 @I1@ INDI\n1 NOTE Première ligne.\n2 CONT Seconde ligne.\n");

        self::assertSame("Première ligne.\nSeconde ligne.", $fichier->enregistrements[0]->texteDe('NOTE'));
    }

    /**
     * L'arbre, et non la pile de drapeaux des scripts d'origine : `1 SOUR / 2 DATA /
     * 3 TEXT` descend à trois niveaux, et c'est exactement là que le drapeau se trompe.
     */
    public function testLesNiveauxImbriquesFormentUnArbre(): void
    {
        $fichier = $this->lire(<<<'GED'
            0 @I1@ INDI
            1 SOUR @S1@
            2 DATA
            3 TEXT Transcription.
            2 EVEN Record
            3 ROLE 123:456-:
            1 SEX M

            GED);

        $indi = $fichier->enregistrements[0];
        $sour = $indi->enfant('SOUR');

        self::assertNotNull($sour);
        self::assertSame('Transcription.', $sour->enfant('DATA')?->texteDe('TEXT'));
        self::assertSame('123:456-:', $sour->enfant('EVEN')?->texteDe('ROLE'));
        // Le `1 SEX` qui suit doit être revenu au niveau 1, pas s'être accroché au ROLE.
        self::assertSame('M', $indi->texteDe('SEX'));
    }

    /**
     * L'arobase littérale s'écrit `@@` dans la norme 5.5.1. Sans déséchappement,
     * l'adresse de courriel du fichier réel ressort avec deux arobases.
     */
    public function testLArobaseLitteraleEstDesechappee(): void
    {
        $fichier = $this->lire("0 @I1@ INDI\n1 RESI\n2 EMAIL julien@@exemple.test\n");

        self::assertSame('julien@exemple.test', $fichier->enregistrements[0]->enfant('RESI')?->texteDe('EMAIL'));
    }

    /**
     * Un pointeur n'est pas du texte : il ne contient pas de `@@` et ne doit pas bouger.
     *
     * `@F500001@` est une xref **littérale à dessein** (§ 9.1) : une xref seule énonce
     * qu'il existe un enregistrement numéroté dans un arbre, sans nom, sans date et sans
     * lieu. Elle ne devient une information qu'accolée au GEDCOM réel, qui n'est pas
     * versionné. Ne la substituez pas.
     */
    public function testUnPointeurNEstPasAltere(): void
    {
        $fichier = $this->lire("0 @I1@ INDI\n1 FAMC @F500001@\n");

        self::assertSame('@F500001@', $fichier->enregistrements[0]->texteDe('FAMC'));
    }

    /**
     * On refuse le fichier plutôt que de laisser des losanges s'installer en base.
     *
     * Le `README.md` du chantier documente un fichier d'origine invalide en UTF-8, un
     * caractère accentué coupé en deux par le découpage de lignes de MyHeritage. Un
     * caractère remplacé par un losange est une donnée perdue qu'on ne remarque que des
     * mois plus tard, sur une fiche qu'on n'ouvre jamais.
     */
    public function testUnOctetIndecodableFaitEchouerLaLecture(): void
    {
        $anomalies = new CollecteurAnomalies();

        $this->expectException(\RuntimeException::class);

        try {
            (new LecteurGedcom())->analyserOctets("0 HEAD\n1 NOTE caf\xE9 coupé\n", $anomalies);
        } finally {
            self::assertSame(1, $anomalies->compte(CollecteurAnomalies::ENCODAGE_INVALIDE));
        }
    }

    /** Le BOM est légal en tête de fichier, et ferait échouer la grammaire sur `0 HEAD`. */
    public function testLeBomEstRetire(): void
    {
        $fichier = $this->lire("\u{FEFF}0 HEAD\n1 CHAR UTF-8\n");

        self::assertSame('HEAD', $fichier->enregistrements[0]->tag);
        self::assertSame('UTF-8', $fichier->charset);
    }

    public function testLeJeuDeCaracteresAnnonceEstRetenu(): void
    {
        self::assertSame('UTF-8', $this->lire("0 HEAD\n1 CHAR UTF-8\n0 TRLR\n")->charset);
    }

    /**
     * Une ligne hors grammaire est presque toujours la suite d'une valeur qu'un
     * exporteur a coupée sans émettre de `CONT`. On la raccroche plutôt que de la jeter,
     * et on le signale.
     */
    public function testUneLigneHorsGrammaireEstRaccrocheeEtSignalee(): void
    {
        $anomalies = new CollecteurAnomalies();
        $fichier = (new LecteurGedcom())->analyserOctets("0 @I1@ INDI\n1 NOTE Début.\nsuite orpheline\n", $anomalies);

        self::assertSame("Début.\nsuite orpheline", $fichier->enregistrements[0]->texteDe('NOTE'));
        self::assertSame(1, $anomalies->compte(CollecteurAnomalies::ENCODAGE_INVALIDE));
    }

    /** Les tags propriétaires à souligné sont de la grammaire valide : `_MARNM` en dépend. */
    public function testLesTagsProprietairesSontAcceptes(): void
    {
        $fichier = $this->lire("0 @I1@ INDI\n1 NAME Hélène /Thym/\n2 _MARNM Persil\n");

        self::assertSame('Persil', $fichier->enregistrements[0]->enfant('NAME')?->texteDe('_MARNM'));
    }

    private function lire(string $octets): FichierGedcom
    {
        return (new LecteurGedcom())->analyserOctets($octets, new CollecteurAnomalies());
    }
}
