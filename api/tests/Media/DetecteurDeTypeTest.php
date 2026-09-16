<?php

declare(strict_types=1);

namespace App\Tests\Media;

use App\Module\Media\Service\DetecteurDeType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Le type réel se lit dans les octets, jamais dans le nom.
 *
 * Ces cas sont des cas d'attaque, pas des cas d'usage : ils décrivent ce qu'un client
 * hostile envoie, et ce que le module doit en faire.
 */
final class DetecteurDeTypeTest extends TestCase
{
    private DetecteurDeType $detecteur;

    protected function setUp(): void
    {
        $this->detecteur = new DetecteurDeType();
    }

    public function testUnFichierNonImageRenommeEnJpegNEstPasUneImage(): void
    {
        // Le fichier s'appelle `acte.jpg`, le client annonce `image/jpeg`. Les deux
        // mentent, et ni l'un ni l'autre n'est consulté.
        //
        // La charge est du texte et non la porte dérobée PHP d'autrefois : voir le journal
        // de `FabriqueDeFichiers::texteDeguiseEnImage()`. Ce test passait jusqu'ici parce
        // que l'antivirus rendait sa charge ILLISIBLE — `detecter()` sortait sur son garde
        // d'entrée sans lire un octet, et rendait `null` pour la mauvaise raison.
        $chemin = $this->ecrire(FabriqueDeFichiers::texteDeguiseEnImage(), '.jpg');

        // Le fichier est-il vraiment lu ? Sans cette ligne, `assertNull` ci-dessous est
        // satisfaite aussi bien par « refusé » que par « jamais ouvert ».
        self::assertSame(
            "Ceci n'est",
            file_get_contents($chemin, false, null, 0, 10),
            'La charge doit être relisible, sinon le test qui suit ne prouve rien.',
        );

        self::assertNull($this->detecteur->detecter($chemin));
    }

    public function testUnPngRenommeEnJpegEstUnPng(): void
    {
        // Le miroir du cas précédent, et il compte autant : l'extension qui ment n'est
        // pas toujours une attaque. Un fichier légitime mal nommé doit être **accepté**,
        // sous son vrai type — c'est lui qui part en base, « si la liste blanche change
        // un jour, on sait ce qu'on a déjà accepté ».
        $chemin = $this->ecrire(FabriqueDeFichiers::pngTransparent(), '.jpg');

        self::assertSame('image/png', $this->detecteur->detecter($chemin));
    }

    public function testUnPdfPrecedeDeRebutEstRefuse(): void
    {
        // La spécification PDF tolère 1 024 octets avant `%PDF-`. C'est la marge dans
        // laquelle se loge un polyglotte : un fichier que le module lit comme un PDF et
        // qu'un autre logiciel lit comme un GIF, ou comme autre chose.
        $chemin = $this->ecrire(FabriqueDeFichiers::pdfPrecedeDeRebut(), '.pdf');

        self::assertNull($this->detecteur->detecter($chemin));
    }

    public function testUnFichierVideNEstRien(): void
    {
        self::assertNull($this->detecteur->detecter($this->ecrire('', '.jpg')));
    }

    public function testUnCheminInexistantNEstRien(): void
    {
        self::assertNull($this->detecteur->detecter(sys_get_temp_dir().'/absent-'.bin2hex(random_bytes(4))));
    }

    /** @param non-empty-string $octets */
    #[DataProvider('signatures')]
    public function testLesQuatreSignaturesDeLaListeBlanche(string $octets, ?string $attendu): void
    {
        self::assertSame($attendu, $this->detecteur->depuisLOctets($octets));
    }

    /** @return iterable<string, array{string, string|null}> */
    public static function signatures(): iterable
    {
        yield 'jpeg' => ["\xFF\xD8\xFF\xE0".str_repeat("\x00", 12), 'image/jpeg'];
        yield 'png' => ["\x89PNG\r\n\x1A\n".str_repeat("\x00", 8), 'image/png'];
        yield 'webp' => ['RIFF'.pack('V', 100).'WEBPVP8 ', 'image/webp'];
        yield 'pdf' => ['%PDF-1.7'.str_repeat(' ', 8), 'application/pdf'];

        // Un RIFF qui n'est pas un WebP : un WAV, par exemple. Le conteneur est le même,
        // le contenu non — vérifier les quatre premiers octets seulement suffirait à se
        // faire prendre.
        yield 'riff non webp' => ['RIFF'.pack('V', 100).'WAVEfmt ', null];

        yield 'gif' => ['GIF89a'.str_repeat("\x00", 10), null];
        yield 'zip' => ["PK\x03\x04".str_repeat("\x00", 12), null];
        yield 'elf' => ["\x7FELF".str_repeat("\x00", 12), null];
        yield 'html' => ['<!DOCTYPE html><html>', null];
    }

    /**
     * Écrit une charge d'essai **et vérifie qu'elle se relit**.
     *
     * Le garde n'est pas décoratif : tous les cas d'attaque de ce fichier attendent `null`,
     * et `detecter()` rend aussi `null` quand il n'a pas pu ouvrir le fichier. Sans cette
     * vérification, un fichier verrouillé par l'antivirus du poste rend chaque assertion de
     * cette classe vraie sans que rien ne soit éprouvé.
     */
    private function ecrire(string $octets, string $suffixe): string
    {
        $chemin = sys_get_temp_dir().'/heritage-detect-'.bin2hex(random_bytes(6)).$suffixe;
        file_put_contents($chemin, $octets);

        register_shutdown_function(static fn () => @unlink($chemin));

        if ('' !== $octets) {
            FabriqueDeFichiers::verifierRelisible($chemin);
        }

        return $chemin;
    }
}
