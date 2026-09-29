<?php

declare(strict_types=1);

namespace App\Tests\Media;

use App\Module\Media\Service\OrientationExif;
use PHPUnit\Framework\TestCase;

/**
 * Le lecteur d'orientation, hors GD : ce qu'il lit, et surtout ce qu'il rend quand il
 * ne lit rien — `1`, jamais une exception qui ferait refuser une photo.
 */
final class OrientationExifTest extends TestCase
{
    /** @var list<string> */
    private array $aEffacer = [];

    protected function tearDown(): void
    {
        foreach ($this->aEffacer as $chemin) {
            @unlink($chemin);
        }
    }

    public function testLOrientationDUnJpegPetitBoutiste(): void
    {
        self::assertSame(6, (new OrientationExif())->lire($this->ecrire(FabriqueDeFichiers::jpegOriente(6)), 'image/jpeg'));
    }

    public function testUnJpegSansOrientationRendUn(): void
    {
        // Un EXIF présent mais sans l'étiquette : le bloc GPS gros-boutiste de la fabrique.
        self::assertSame(1, (new OrientationExif())->lire($this->ecrire(FabriqueDeFichiers::jpegAvecGps()), 'image/jpeg'));
        self::assertSame(1, (new OrientationExif())->lire($this->ecrire(FabriqueDeFichiers::jpegNu()), 'image/jpeg'));
    }

    public function testLOrientationDUnWebp(): void
    {
        // `exif_read_data()` ne sait pas lire le WebP : c'est une des deux raisons du
        // lecteur maison.
        self::assertSame(8, (new OrientationExif())->lire($this->ecrire(FabriqueDeFichiers::webpAvecOrientation(8)), 'image/webp'));
    }

    public function testUneValeurHorsBornesOuUnFichierTronqueRendUn(): void
    {
        $lecteur = new OrientationExif();

        self::assertSame(1, $lecteur->lire($this->ecrire(FabriqueDeFichiers::jpegOriente(9)), 'image/jpeg'));
        self::assertSame(1, $lecteur->lire($this->ecrire(substr(FabriqueDeFichiers::jpegOriente(6), 0, 20)), 'image/jpeg'));
        self::assertSame(1, $lecteur->lire($this->ecrire('pas une image'), 'image/jpeg'));
        self::assertSame(1, $lecteur->lire($this->ecrire(FabriqueDeFichiers::pngTransparent()), 'image/png'));
    }

    private function ecrire(string $octets): string
    {
        $chemin = sys_get_temp_dir().'/heritage-orientation-'.bin2hex(random_bytes(6));
        file_put_contents($chemin, $octets);
        $this->aEffacer[] = $chemin;

        return $chemin;
    }
}
