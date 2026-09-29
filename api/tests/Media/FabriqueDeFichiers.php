<?php

declare(strict_types=1);

namespace App\Tests\Media;

use PHPUnit\Framework\Assert;

/**
 * Fabrique les fichiers d'essai du module : des images réelles, avec de vraies
 * métadonnées à purger.
 *
 * **Pourquoi construire l'EXIF à la main plutôt que joindre une photo au dépôt.** Deux
 * raisons, dans cet ordre : aucune photo de famille ne doit entrer dans le dépôt (§ 9 du
 * dossier), et un fichier binaire joint ne dit pas ce qu'il contient — celui qui relit le
 * test six mois plus tard ne sait pas quelles métadonnées il est censé perdre. Ici, le
 * bloc GPS est écrit octet par octet juste au-dessus de l'assertion qui vérifie sa
 * disparition.
 *
 * L'extension `exif` n'est pas installée sur la machine de développement, et
 * `exif_read_data()` n'existe donc pas. C'est une chance : le test relit **lui-même** les
 * segments du fichier écrit, ce qui est précisément ce que demande la consigne — vérifier
 * en relisant, pas en faisant confiance à la bibliothèque qui vient d'écrire.
 */
final class FabriqueDeFichiers
{
    /** Le pointeur vers l'IFD GPS dans l'IFD principal d'un bloc TIFF/EXIF. */
    public const int TAG_POINTEUR_GPS = 0x8825;

    /**
     * Un JPEG de la taille demandée, portant un bloc EXIF avec des **coordonnées GPS**.
     *
     * 46° 12′ 45″ N, 2° 18′ 00″ E — quelque part en Fougerie, comme les actes du projet.
     * C'est exactement le cas que le dossier décrit : « une photo de famille prise au
     * domicile et publiée telle quelle donne l'adresse de la maison ».
     */
    public static function jpegAvecGps(int $largeur = 120, int $hauteur = 90): string
    {
        return self::greffer(self::jpegNu($largeur, $hauteur), self::blocExifGps());
    }

    /**
     * Un JPEG comme en écrit un téléphone : les pixels du capteur, et une étiquette
     * `Orientation` qui dit comment les tourner.
     *
     * Le quart **haut-gauche** est rouge, le reste bleu : c'est ce coin qu'on suit pour
     * vérifier le sens de la rotation, et non les seules dimensions — une rotation dans le
     * mauvais sens a les mêmes dimensions que la bonne.
     */
    public static function jpegOriente(int $orientation, int $largeur = 120, int $hauteur = 80): string
    {
        return self::greffer(self::jpegAvecCoinRouge($largeur, $hauteur), self::blocExifOrientation($orientation));
    }

    /** Le quart haut-gauche rouge, le reste bleu. */
    public static function jpegAvecCoinRouge(int $largeur, int $hauteur): string
    {
        $image = imagecreatetruecolor($largeur, $hauteur);

        if (false === $image) {
            throw new \RuntimeException('GD n\'a pas pu créer l\'image d\'essai.');
        }

        imagefilledrectangle($image, 0, 0, $largeur, $hauteur, (int) imagecolorallocate($image, 0, 0, 255));
        imagefilledrectangle($image, 0, 0, intdiv($largeur, 2) - 1, intdiv($hauteur, 2) - 1, (int) imagecolorallocate($image, 255, 0, 0));

        ob_start();
        imagejpeg($image, null, 95);
        $octets = (string) ob_get_clean();

        return $octets;
    }

    /**
     * Un WebP « étendu » réduit à ce que lit le lecteur d'orientation : l'en-tête RIFF,
     * un morceau quelconque de longueur **impaire** (pour éprouver l'alignement) puis le
     * morceau `EXIF`. Il n'est pas décodable — il n'a pas à l'être pour ce qu'on teste.
     */
    public static function webpAvecOrientation(int $orientation): string
    {
        $tiff = substr(self::blocExifOrientation($orientation), 10);
        $morceaux = 'ICCP'.pack('V', 3)."abc\x00".'EXIF'.pack('V', \strlen($tiff)).$tiff;

        return 'RIFF'.pack('V', 4 + \strlen($morceaux)).'WEBP'.$morceaux;
    }

    /** Couleur dominante d'un pixel : `rouge` ou `bleu`, robuste aux artefacts JPEG. */
    public static function couleurA(\GdImage $image, int $x, int $y): string
    {
        $c = imagecolorsforindex($image, (int) imagecolorat($image, $x, $y));

        return $c['red'] > $c['blue'] ? 'rouge' : 'bleu';
    }

    /** Un JPEG sans aucune métadonnée ajoutée. */
    public static function jpegNu(int $largeur = 120, int $hauteur = 90): string
    {
        $image = imagecreatetruecolor($largeur, $hauteur);

        if (false === $image) {
            throw new \RuntimeException('GD n\'a pas pu créer l\'image d\'essai.');
        }

        $couleur = imagecolorallocate($image, 180, 140, 90);

        if (false !== $couleur) {
            imagefilledrectangle($image, 0, 0, $largeur, $hauteur, $couleur);
        }

        ob_start();
        imagejpeg($image, null, 90);
        $octets = (string) ob_get_clean();
        imagedestroy($image);

        return $octets;
    }

    /** Un PNG avec canal alpha, pour vérifier que la purge ne l'aplatit pas sur du noir. */
    public static function pngTransparent(int $largeur = 60, int $hauteur = 40): string
    {
        $image = imagecreatetruecolor($largeur, $hauteur);

        if (false === $image) {
            throw new \RuntimeException('GD n\'a pas pu créer l\'image d\'essai.');
        }

        imagealphablending($image, false);
        imagesavealpha($image, true);
        $transparent = imagecolorallocatealpha($image, 0, 0, 0, 127);

        if (false !== $transparent) {
            imagefilledrectangle($image, 0, 0, $largeur, $hauteur, $transparent);
        }

        ob_start();
        imagepng($image);
        $octets = (string) ob_get_clean();
        imagedestroy($image);

        return $octets;
    }

    /**
     * Un PDF minimal mais valide, portant un `/Info` bavard et un paquet XMP.
     *
     * Écrit sans table `xref` correcte : les lecteurs tolèrent la reconstruction, et ce
     * qui est testé ici n'est pas la validité du PDF mais la conservation de sa longueur
     * et la disparition de ses métadonnées en clair.
     */
    public static function pdfAvecMetadonnees(string $auteur = 'Natron Romarin'): string
    {
        $xmp = '<?xpacket begin="" id="W5M0MpCehiHzreSzNTczkc9d"?>'
            .'<x:xmpmeta xmlns:x="adobe:ns:meta/"><rdf:RDF '
            .'xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">'
            .'<rdf:Description dc:creator="'.$auteur.'" /></rdf:RDF></x:xmpmeta>'
            .'<?xpacket end="w"?>';

        return "%PDF-1.4\n"
            ."1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n"
            ."2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n"
            ."3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] >>\nendobj\n"
            ."4 0 obj\n<< /Author (".$auteur.') /Creator (Appareil photo XYZ) '
            ."/Producer (Scanner de mairie) >>\nendobj\n"
            .'5 0 obj
<< /Type /Metadata /Subtype /XML /Length '.\strlen($xmp).' >>
stream
'.$xmp."
endstream
endobj\n"
            ."trailer\n<< /Root 1 0 R /Info 4 0 R >>\n%%EOF\n";
    }

    /**
     * Un fichier dont l'extension ment : il s'appellera `acte.jpg` et n'est pas une image.
     *
     * ── Ce que cette fabrique rendait avant, et pourquoi c'était intestable ─────────
     *
     * Elle rendait une porte dérobée PHP — la signature de webshell la plus banale, une
     * fonction d'évaluation dynamique appliquée à un paramètre de requête — au motif,
     * défendable, qu'un test refusant un fichier texte anodin ne prouverait pas
     * grand-chose. Les deux tests qui s'en servaient passaient en fait pour la raison
     * exactement inverse de celle qu'ils prétendaient établir.
     *
     * L'antivirus de Windows reconnaît cette signature et **bloque la lecture** du
     * fichier à l'instant où il est écrit. Mesuré, trois exécutions de suite :
     *
     * ```
     * file_put_contents($p, <la charge d'alors>);
     * filesize($p);                              // 49   — le fichier existe
     * file_get_contents($p, false, null, 0, 16); // false — il est illisible
     * (new DetecteurDeType())->detecter($p);     // null
     * ```
     *
     * `DetecteurDeType::detecter()` sortait donc sur le `return null` de son garde
     * d'entrée, **avant d'avoir regardé le moindre octet**. Le test lisait `null`,
     * concluait « le module a refusé le script », et passait au vert. Vérifié en
     * remplaçant le corps de `depuisLOctets()` par un `throw` : l'exception n'est jamais
     * atteinte et les deux tests restent verts. Un test qui ne peut pas échouer ne prouve
     * rien — et ces deux-là étaient des tests de sécurité.
     *
     * Le défaut s'étend au texte qui le décrit : une première rédaction de ce bloc citait
     * la charge en toutes lettres, et **ce fichier-ci est devenu illisible à son tour**,
     * cassant la suite entière. D'où la périphrase ci-dessus. Ne la remplacez pas par
     * l'original : ce qui compte se lit, la signature ne doit pas exister dans le dépôt.
     *
     * ── Pourquoi du texte suffit, et couvre autant ──────────────────────────────────
     *
     * Le détecteur n'est pas un antivirus : il ne cherche pas ce qui est dangereux, il
     * compare les premiers octets à **quatre signatures** et refuse tout le reste. Une
     * charge qui n'est aucune des quatre l'éprouve exactement autant qu'une porte dérobée
     * — le chemin parcouru dans le code est le même, au `return` près. Ce que la porte
     * dérobée ajoutait était du réalisme de narration, pas de la couverture, et elle le
     * payait d'une dépendance à la clémence de l'antivirus du poste.
     *
     * La charge ci-dessous ne porte **aucune signature exécutable** : ni balise PHP, ni
     * shebang, ni en-tête PE ou ELF. Elle se relit sur n'importe quelle machine, et
     * `TeleversementMediaTest` la fait passer par le même `415` que le polyglotte de
     * `pdfPrecedeDeRebut()` — qui est, lui, le cas réellement retors.
     *
     * Tenté d'y remettre une charge « plus réaliste » ? Lisez `verifierRelisible()`.
     */
    public static function texteDeguiseEnImage(): string
    {
        return "Ceci n'est pas une image : aucune des quatre signatures de la liste blanche.\n";
    }

    /**
     * Le cas plus retors : un PDF précédé de rebut, que la spécification tolère sur
     * 1 024 octets. On ne le tolère pas — c'est la marge dans laquelle se loge un
     * polyglotte.
     */
    public static function pdfPrecedeDeRebut(): string
    {
        return "GIF89a\n".self::pdfAvecMetadonnees();
    }

    /**
     * **Le garde-fou** : un fichier d'essai qui ne se relit pas fait échouer le test
     * bruyamment, au lieu de le laisser glisser vers un `null` qui ressemble à un succès.
     *
     * C'est le défaut raconté dans `texteDeguiseEnImage()`, rendu incapable de se
     * reproduire en silence. Toute charge écrite sur disque pour être reniflée passe par
     * ici **avant la première assertion** : c'est la seule façon de distinguer « le module
     * a refusé ce fichier » de « personne n'a jamais pu lire ce fichier ».
     *
     * Le message est long exprès. Celui qui le verra sera au milieu d'une suite rouge sur
     * une machine qu'il ne connaît pas, et la mauvaise réaction — assouplir l'assertion —
     * est aussi la plus tentante.
     */
    public static function verifierRelisible(string $chemin): void
    {
        clearstatcache(true, $chemin);

        $taille = @filesize($chemin);
        $tete = @file_get_contents($chemin, false, null, 0, 16);

        Assert::assertIsString($tete, \sprintf(
            "La charge d'essai « %s » a bien été écrite (filesize = %s) mais ne peut PAS être relue.\n"
            ."C'est presque toujours l'antivirus du poste : la charge porte une signature qu'il\n"
            ."intercepte, et il verrouille le fichier. NE PAS considérer le test comme vert, NE PAS\n"
            ."assouplir l'assertion qui suit, NE PAS retirer ce garde : c'est la CHARGE qu'il faut\n"
            .'remplacer par une charge sans signature exécutable. Voir le journal de `texteDeguiseEnImage()`.',
            $chemin,
            var_export($taille, true),
        ));
    }

    // -------------------------------------------------------------------------------
    // Lecture des segments, pour les assertions
    // -------------------------------------------------------------------------------

    /**
     * Le JPEG porte-t-il un segment `APP1` de type `Exif` ?
     *
     * Parcourt les marqueurs depuis `SOI` plutôt que de chercher la chaîne `Exif` au
     * hasard dans le fichier : des octets d'image compressée peuvent contenir n'importe
     * quelle suite de quatre caractères, et une assertion qui se laisse tromper par ça
     * ne prouve rien.
     */
    public static function porteUnExif(string $jpeg): bool
    {
        return [] !== self::segmentsExif($jpeg);
    }

    /**
     * Le JPEG porte-t-il un **pointeur d'IFD GPS** (tag 0x8825) ?
     *
     * C'est la question qui compte : pas « reste-t-il un en-tête EXIF » mais « reste-t-il
     * des coordonnées ».
     */
    public static function porteDesCoordonneesGps(string $jpeg): bool
    {
        foreach (self::segmentsExif($jpeg) as $exif) {
            // 6 octets « Exif\0\0 », puis l'en-tête TIFF.
            $tiff = substr($exif, 6);

            if (\strlen($tiff) < 8) {
                continue;
            }

            $grosBoutiste = str_starts_with($tiff, 'MM');
            $offsetIfd = self::mot32($tiff, 4, $grosBoutiste);

            if ($offsetIfd < 8 || $offsetIfd + 2 > \strlen($tiff)) {
                continue;
            }

            $entrees = self::mot16($tiff, $offsetIfd, $grosBoutiste);

            for ($i = 0; $i < $entrees; ++$i) {
                $position = $offsetIfd + 2 + ($i * 12);

                if ($position + 12 > \strlen($tiff)) {
                    break;
                }

                if (self::TAG_POINTEUR_GPS === self::mot16($tiff, $position, $grosBoutiste)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Les charges utiles des segments `APP1` commençant par `Exif\0\0`.
     *
     * @return list<string>
     */
    private static function segmentsExif(string $jpeg): array
    {
        $trouves = [];

        if (!str_starts_with($jpeg, "\xFF\xD8")) {
            return $trouves;
        }

        $position = 2;
        $longueur = \strlen($jpeg);

        while ($position + 4 <= $longueur) {
            if ("\xFF" !== $jpeg[$position]) {
                break;
            }

            $marqueur = \ord($jpeg[$position + 1]);

            // `SOS` : à partir d'ici commencent les données compressées, il n'y a plus
            // de segment à lire.
            if (0xDA === $marqueur || 0xD9 === $marqueur) {
                break;
            }

            $taille = self::mot16($jpeg, $position + 2, true);

            if ($taille < 2) {
                break;
            }

            $charge = substr($jpeg, $position + 4, $taille - 2);

            if (0xE1 === $marqueur && str_starts_with($charge, "Exif\x00\x00")) {
                $trouves[] = $charge;
            }

            $position += 2 + $taille;
        }

        return $trouves;
    }

    // -------------------------------------------------------------------------------
    // Écriture du bloc EXIF
    // -------------------------------------------------------------------------------

    /** Insère un segment APP1 juste après le `SOI`. */
    private static function greffer(string $jpeg, string $app1): string
    {
        return substr($jpeg, 0, 2).$app1.substr($jpeg, 2);
    }

    /**
     * Le segment APP1 complet : `FF E1`, longueur, `Exif\0\0`, puis un bloc TIFF
     * gros-boutiste dont l'IFD principal ne contient qu'un pointeur vers un IFD GPS.
     */
    private static function blocExifGps(): string
    {
        // L'IFD GPS commence après l'en-tête TIFF (8) et l'IFD principal (2 + 12 + 4).
        $offsetGps = 26;
        // Il compte quatre entrées : 2 + 4 × 12 + 4 = 54 octets.
        $offsetDonnees = $offsetGps + 54;

        $ifd0 = pack('n', 1)
            .self::entree(self::TAG_POINTEUR_GPS, 4, 1, pack('N', $offsetGps))
            .pack('N', 0);

        $ifdGps = pack('n', 4)
            // GPSLatitudeRef = « N », en ASCII sur deux octets, logé dans la valeur.
            .self::entree(0x0001, 2, 2, "N\x00\x00\x00")
            // GPSLatitude : trois rationnels, donc 24 octets, donc un renvoi.
            .self::entree(0x0002, 5, 3, pack('N', $offsetDonnees))
            .self::entree(0x0003, 2, 2, "E\x00\x00\x00")
            .self::entree(0x0004, 5, 3, pack('N', $offsetDonnees + 24))
            .pack('N', 0);

        // 46° 12′ 45″ et 2° 18′ 00″.
        $latitude = pack('NNNNNN', 46, 1, 12, 1, 45, 1);
        $longitude = pack('NNNNNN', 2, 1, 18, 1, 0, 1);

        $tiff = "MM\x00\x2A".pack('N', 8).$ifd0.$ifdGps.$latitude.$longitude;
        $charge = "Exif\x00\x00".$tiff;

        return "\xFF\xE1".pack('n', \strlen($charge) + 2).$charge;
    }

    /**
     * Un segment APP1 dont l'IFD principal ne porte que l'étiquette `Orientation`
     * (0x0112, SHORT, compte 1), en **petit-boutiste** — celui qu'écrivent la plupart
     * des téléphones, et l'autre moitié du lecteur que le bloc GPS n'éprouve pas.
     */
    private static function blocExifOrientation(int $orientation): string
    {
        $ifd0 = pack('v', 1).pack('vvV', 0x0112, 3, 1).pack('v', $orientation)."\x00\x00".pack('V', 0);
        $charge = "Exif\x00\x00II\x2A\x00".pack('V', 8).$ifd0;

        return "\xFF\xE1".pack('n', \strlen($charge) + 2).$charge;
    }

    private static function entree(int $tag, int $type, int $compte, string $valeur): string
    {
        return pack('nnN', $tag, $type, $compte).$valeur;
    }

    private static function mot16(string $octets, int $position, bool $grosBoutiste): int
    {
        $lu = unpack($grosBoutiste ? 'n' : 'v', substr($octets, $position, 2));

        return false === $lu ? 0 : (int) $lu[1];
    }

    private static function mot32(string $octets, int $position, bool $grosBoutiste): int
    {
        $lu = unpack($grosBoutiste ? 'N' : 'V', substr($octets, $position, 4));

        return false === $lu ? 0 : (int) $lu[1];
    }
}
