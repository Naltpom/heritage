<?php

declare(strict_types=1);

namespace App\Tests\Media;

use App\Module\Media\Service\StockageMedia;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Le nom de fichier est aléatoire, et il est revalidé à chaque accès.
 *
 * Le second point est celui qui compte : le nom vient de la base, et une base est une
 * entrée comme une autre. Si un jour un chemin quelconque se retrouve dans
 * `storage_name` — import mal écrit, injection ailleurs —, il ne doit pas devenir une
 * lecture arbitraire du système de fichiers.
 */
final class StockageMediaTest extends TestCase
{
    private string $racine;
    private StockageMedia $stockage;

    protected function setUp(): void
    {
        $this->racine = sys_get_temp_dir().'/heritage-stockage-'.bin2hex(random_bytes(6));
        $this->stockage = new StockageMedia($this->racine);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->racine.'/*/*/*') ?: [] as $fichier) {
            @unlink($fichier);
        }
    }

    public function testLeNomTireNApprendRienSurLeContenu(): void
    {
        $premier = $this->stockage->nomAleatoire('image/jpeg');
        $second = $this->stockage->nomAleatoire('image/jpeg');

        self::assertMatchesRegularExpression(StockageMedia::NOM_VALIDE, $premier);
        self::assertNotSame($premier, $second, 'Deux tirages ne doivent pas se rencontrer.');
        self::assertStringEndsWith('.jpg', $premier);
        self::assertStringEndsWith('.pdf', $this->stockage->nomAleatoire('application/pdf'));
    }

    #[DataProvider('nomsHostiles')]
    public function testUnNomQuiNEstPasUnNomNOuvreAucunFichier(string $nom): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->stockage->chemin($nom);
    }

    /** @return iterable<string, array{string}> */
    public static function nomsHostiles(): iterable
    {
        yield 'remontée relative' => ['../../../../etc/passwd'];
        yield 'remontée déguisée' => ['a1b2c3d4e5f60718293a4b5c6d7e8f90/../../.env'];
        yield 'chemin absolu' => ['/etc/shadow'];
        yield 'chemin windows' => ['C:\\Windows\\win.ini'];
        yield 'octet nul' => ["a1b2c3d4e5f60718293a4b5c6d7e8f90.jpg\x00.php"];
        yield 'extension non prévue' => ['a1b2c3d4e5f60718293a4b5c6d7e8f90.php'];
        yield 'majuscules' => ['A1B2C3D4E5F60718293A4B5C6D7E8F90.jpg'];
        yield 'joker' => ['*.jpg'];
        yield 'vide' => [''];
    }

    public function testLeStockageEstEclateEtHorsRacineWeb(): void
    {
        $nom = $this->stockage->nomAleatoire('image/png');
        $this->stockage->deposerDesOctets('des octets', $nom);

        $chemin = $this->stockage->chemin($nom);

        self::assertFileExists($chemin);
        // Éclaté sur les deux premiers caractères : un répertoire à dizaines de milliers
        // d'entrées rend le diagnostic pénible sur un VPS modeste.
        self::assertStringContainsString('/originaux/'.substr($nom, 0, 2).'/', str_replace('\\', '/', $chemin));
        // Et jamais sous `public/` : aucun serveur ne sert ce chemin.
        self::assertStringNotContainsString('/public/', str_replace('\\', '/', $chemin));
    }

    public function testOriginalEtVignetteNeSeMarchentPasDessus(): void
    {
        $nom = $this->stockage->nomAleatoire('image/jpeg');

        $this->stockage->deposerDesOctets('original', $nom);
        $this->stockage->deposerDesOctets('vignette', $nom, vignette: true);

        self::assertSame('original', file_get_contents($this->stockage->chemin($nom)));
        self::assertSame('vignette', file_get_contents($this->stockage->chemin($nom, vignette: true)));

        // Supprimer l'un ne touche pas l'autre : c'est le sous-arbre qui les sépare, pas
        // le nom.
        $this->stockage->supprimer($nom);
        self::assertFalse($this->stockage->existe($nom));
        self::assertTrue($this->stockage->existe($nom, vignette: true));
    }
}
