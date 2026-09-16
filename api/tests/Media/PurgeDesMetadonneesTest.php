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
