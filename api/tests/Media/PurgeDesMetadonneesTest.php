<?php

declare(strict_types=1);

namespace App\Tests\Media;

use App\Module\Core\Exception\DomainException;
use App\Module\Media\Service\PurgeDesMetadonnees;
use PHPUnit\Framework\TestCase;

/**
 * « Une photo de famille non nettoyée publie l'adresse du domicile. ».
 *
 * Le test central du module. Il ne demande pas à GD si elle a fait son travail : il
 * **relit les octets écrits** et cherche lui-même le pointeur d'IFD GPS, en parcourant
 * les segments JPEG depuis le `SOI`. C'est la seule façon de prouver que la coordonnée
 * est partie — une bibliothèque qui changerait de comportement à la prochaine mise à
 * jour ne serait pas prise autrement.
 */
final class PurgeDesMetadonneesTest extends TestCase
{
    private PurgeDesMetadonnees $purge;

    /** @var list<string> */
    private array $aEffacer = [];

    protected function setUp(): void
    {
        $this->purge = new PurgeDesMetadonnees(sys_get_temp_dir());
    }

    protected function tearDown(): void
    {
        foreach ($this->aEffacer as $chemin) {
            @unlink($chemin);
        }
    }

    public function testLaPhotoDEssaiPorteBienDesCoordonneesAvantLaPurge(): void
    {
        // Sans ce cas, le suivant passerait au vert même si la fabrique avait cessé de
        // greffer l'EXIF : on prouverait alors l'absence de ce qui n'a jamais été là.
        $source = FabriqueDeFichiers::jpegAvecGps();

        self::assertTrue(FabriqueDeFichiers::porteUnExif($source), 'Le JPEG d\'essai devrait porter un segment EXIF.');
        self::assertTrue(FabriqueDeFichiers::porteDesCoordonneesGps($source), 'Le JPEG d\'essai devrait porter un IFD GPS.');
    }

    public function testLesCoordonneesGpsDisparaissentDuFichierEcrit(): void
    {
        $chemin = $this->ecrire(FabriqueDeFichiers::jpegAvecGps(240, 160));

        $resultat = $this->purge->purger($chemin, 'image/jpeg');
        $this->aEffacer[] = $resultat->chemin;

        $ecrit = (string) file_get_contents($resultat->chemin);

        self::assertFalse(
            FabriqueDeFichiers::porteDesCoordonneesGps($ecrit),
            'Le fichier réécrit porte encore un pointeur d\'IFD GPS.',
        );
        self::assertFalse(
            FabriqueDeFichiers::porteUnExif($ecrit),
            'Le fichier réécrit porte encore un segment EXIF.',
        );

        // Et le ré-encodage a bien eu lieu : c'est ce que la colonne d'audit affirmera.
        self::assertTrue($resultat->purgee);
        self::assertSame(240, $resultat->largeur);
        self::assertSame(160, $resultat->hauteur);
        self::assertSame('image/jpeg', $resultat->mime);
    }

    public function testLImagePurgeeResteUneImageLisible(): void
    {
        // Une purge qui casserait l'image serait une purge parfaite et un produit inutile.
        $chemin = $this->ecrire(FabriqueDeFichiers::jpegAvecGps(64, 48));

        $resultat = $this->purge->purger($chemin, 'image/jpeg');
        $this->aEffacer[] = $resultat->chemin;

        $taille = getimagesize($resultat->chemin);

        self::assertIsArray($taille);
        self::assertSame(64, $taille[0]);
        self::assertSame(48, $taille[1]);
    }

    /**
     * Les huit orientations EXIF, sur une image 120 × 80 dont le quart haut-gauche est
     * rouge : dimensions rendues, et coin où le rouge doit se retrouver.
     *
     * @return iterable<string, array{int, int, int, int, int}>
     */
    public static function orientations(): iterable
    {
        yield '1 — rien' => [1, 120, 80, 10, 10];
        yield '2 — miroir horizontal' => [2, 120, 80, 110, 10];
        yield '3 — demi-tour' => [3, 120, 80, 110, 70];
        yield '4 — miroir vertical' => [4, 120, 80, 10, 70];
        yield '5 — transposée' => [5, 80, 120, 10, 10];
        yield '6 — quart de tour horaire (téléphone en portrait)' => [6, 80, 120, 70, 10];
        yield '7 — transverse' => [7, 80, 120, 70, 110];
        yield '8 — quart de tour anti-horaire' => [8, 80, 120, 10, 110];
    }

    /**
     * Le défaut d'origine : l'orientation partait avec l'EXIF sans être appliquée, et une
     * photo de téléphone prise en hauteur ressortait couchée.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('orientations')]
    public function testLOrientationExifEstAppliqueeAuxPixels(int $orientation, int $largeur, int $hauteur, int $x, int $y): void
    {
        $chemin = $this->ecrire(FabriqueDeFichiers::jpegOriente($orientation));

        $resultat = $this->purge->purger($chemin, 'image/jpeg');
        $this->aEffacer[] = $resultat->chemin;

        // Les dimensions rendues sont celles d'après redressement : ce sont elles qui
        // partent en base.
        self::assertSame($largeur, $resultat->largeur);
        self::assertSame($hauteur, $resultat->hauteur);

        $image = imagecreatefromjpeg($resultat->chemin);
        self::assertNotFalse($image);
        self::assertSame($largeur, imagesx($image));
        self::assertSame($hauteur, imagesy($image));
        self::assertSame('rouge', FabriqueDeFichiers::couleurA($image, $x, $y), 'Le coin rouge n\'est pas là où l\'orientation le met.');

        // Et l'étiquette elle-même est partie : un lecteur qui l'appliquerait encore
        // tournerait l'image une seconde fois.
        self::assertFalse(FabriqueDeFichiers::porteUnExif((string) file_get_contents($resultat->chemin)));
    }

    public function testPivoterTourneDansLeSensHoraire(): void
    {
        $chemin = $this->ecrire(FabriqueDeFichiers::jpegAvecCoinRouge(120, 80));

        $resultat = $this->purge->pivoter($chemin, 'image/jpeg', 90);
        $this->aEffacer[] = $resultat->chemin;

        self::assertSame(80, $resultat->largeur);
        self::assertSame(120, $resultat->hauteur);

        $image = imagecreatefromjpeg($resultat->chemin);
        self::assertNotFalse($image);
        self::assertSame('rouge', FabriqueDeFichiers::couleurA($image, 70, 10), 'Un quart de tour horaire emmène le coin haut-gauche en haut à droite.');
    }

    public function testPivoterUnPngGardeSaTransparence(): void
    {
        $chemin = $this->ecrire(FabriqueDeFichiers::pngTransparent(30, 20));

        $resultat = $this->purge->pivoter($chemin, 'image/png', 270);
        $this->aEffacer[] = $resultat->chemin;

        self::assertSame(20, $resultat->largeur);
        self::assertSame(30, $resultat->hauteur);

        $image = imagecreatefrompng($resultat->chemin);
        self::assertNotFalse($image);
        self::assertSame(127, imagecolorsforindex($image, (int) imagecolorat($image, 5, 5))['alpha']);
    }

    public function testLaTransparenceDUnPngSurvitALaPurge(): void
    {
        // Le piège du ré-encodage : une toile neuve est noire opaque. Sans la mise en
        // place explicite du canal alpha, un portrait détouré ressortirait sur fond noir.
        $chemin = $this->ecrire(FabriqueDeFichiers::pngTransparent(20, 20));

        $resultat = $this->purge->purger($chemin, 'image/png');
        $this->aEffacer[] = $resultat->chemin;

        $image = imagecreatefrompng($resultat->chemin);
        self::assertNotFalse($image);

        $couleur = imagecolorat($image, 5, 5);
        self::assertNotFalse($couleur);
        $composantes = imagecolorsforindex($image, $couleur);
        imagedestroy($image);

        self::assertSame(127, $composantes['alpha'], 'Le pixel devrait être resté entièrement transparent.');
    }

    public function testUnFichierIllisibleCommeImageEstRefuse(): void
    {
        // Les octets d'en-tête disent « JPEG », le décodeur ne lit rien : fichier tronqué
        // ou en-tête posé devant autre chose. C'est un `422`, pas un `415` — le type
        // n'est pas en cause, le contenu l'est.
        $chemin = $this->ecrire("\xFF\xD8\xFF\xE0".str_repeat("\x00", 64));

        $this->expectException(DomainException::class);

        try {
            $this->purge->purger($chemin, 'image/jpeg');
        } catch (DomainException $erreur) {
            self::assertSame(422, $erreur->statutHttp());
            self::assertSame('donnees_invalides', $erreur->codeErreur());

            throw $erreur;
        }
    }

    public function testLesMetadonneesEnClairDUnPdfSontEffaceesSansChangerSaLongueur(): void
    {
        $source = FabriqueDeFichiers::pdfAvecMetadonnees('Natron Romarin');
        $chemin = $this->ecrire($source);

        $resultat = $this->purge->purger($chemin, 'application/pdf');
        $this->aEffacer[] = $resultat->chemin;

        $ecrit = (string) file_get_contents($resultat->chemin);

        self::assertStringNotContainsString('Natron Romarin', $ecrit);
        self::assertStringNotContainsString('Scanner de mairie', $ecrit);
        self::assertStringNotContainsString('Appareil photo XYZ', $ecrit);

        // La longueur est conservée : c'est ce qui laisse la table `xref` valide sans
        // avoir à la reconstruire. Une substitution qui raccourcirait le fichier
        // décalerait tous les offsets et rendrait le PDF illisible.
        self::assertSame(\strlen($source), \strlen($ecrit), 'La purge PDF a changé la longueur du fichier.');

        // Le PDF reste un PDF, et le compte de pages est lu.
        self::assertStringStartsWith('%PDF-', $ecrit);
        self::assertSame(1, $resultat->pages);
    }

    public function testUnPdfNAffirmeJamaisAvoirEtePurge(): void
    {
        // `exif_stripped` est une colonne d'audit : elle ne doit pas affirmer une
        // garantie qu'on n'a pas. Un PDF n'est pas entièrement réécrit — l'EXIF des
        // images qu'il contient, et tout ce qui est dans un flux compressé, survit.
        $chemin = $this->ecrire(FabriqueDeFichiers::pdfAvecMetadonnees());

        $resultat = $this->purge->purger($chemin, 'application/pdf');
        $this->aEffacer[] = $resultat->chemin;

        self::assertFalse($resultat->purgee);
    }

    private function ecrire(string $octets): string
    {
        $chemin = sys_get_temp_dir().'/heritage-purge-'.bin2hex(random_bytes(6));
        file_put_contents($chemin, $octets);
        $this->aEffacer[] = $chemin;

        return $chemin;
    }
}
