<?php

declare(strict_types=1);

namespace App\Module\Media\Service;

/**
 * L'orientation EXIF : la lire dans le fichier reçu, et l'**appliquer aux pixels** avant
 * que la purge ne l'efface.
 *
 * ## Pourquoi c'est la purge qui doit s'en charger
 *
 * Un téléphone tenu en portrait n'écrit pas une image en hauteur : il écrit les pixels
 * du capteur, en largeur, et pose dans l'EXIF l'étiquette `Orientation` (0x0112) qui
 * dit au lecteur de les faire pivoter. {@see PurgeDesMetadonnees} réécrit un fichier
 * neuf à partir des seuls pixels — c'est tout son intérêt — et emporte donc l'étiquette
 * avec le reste. Sans ce redressement, chaque photo de famille prise en hauteur
 * ressortait couchée.
 *
 * ## Pourquoi un lecteur maison plutôt qu'`exif_read_data()`
 *
 * L'image Docker installe `exif`, mais le poste de développement ne l'a pas (voir
 * {@see \App\Tests\Media\FabriqueDeFichiers}) : un redressement suspendu à
 * `function_exists('exif_read_data')` serait resté un no-op silencieux partout où l'on
 * teste. Et `exif_read_data()` ne lit pas le WebP, que les téléphones récents produisent.
 * On ne cherche qu'**une** étiquette de l'IFD principal : quarante lignes qui lisent les
 * segments sans charger le fichier, et le même comportement sur toutes les machines.
 *
 * Toute lecture ratée rend `1` (« rien à faire ») : une étiquette illisible ne vaut pas
 * le refus d'une photo, et une image non redressée est le comportement d'avant.
 */
final class OrientationExif
{
    private const int TAG_ORIENTATION = 0x0112;

    /** Au-delà, on cesse de chercher : un en-tête JPEG ne fait pas des mégaoctets. */
    private const int SEGMENTS_MAX = 64;

    /** @return int de 1 à 8, `1` si rien n'est lisible */
    public function lire(string $chemin, string $mime): int
    {
        $flux = @fopen($chemin, 'rb');

        if (false === $flux) {
            return 1;
        }

        try {
            $tiff = match ($mime) {
                'image/jpeg' => $this->tiffDuJpeg($flux),
                'image/webp' => $this->tiffDuWebp($flux),
                default => null,
            };
        } finally {
            fclose($flux);
        }

        return null === $tiff ? 1 : $this->orientationDuTiff($tiff);
    }

    /**
     * Applique une orientation EXIF : rend l'image telle qu'un lecteur l'aurait
     * affichée. Sert aussi à pivoter à la demande — 90° horaires, c'est l'orientation 6.
     *
     * `imagerotate()` tourne dans le sens **anti-horaire** : 270 y est donc un quart de
     * tour horaire. L'image reçue est libérée dès qu'elle est remplacée, pour ne jamais
     * tenir plus de deux images en mémoire (voir `PurgeDesMetadonnees::PIXELS_MAX`).
     */
    public function redresser(\GdImage $image, int $orientation): \GdImage
    {
        // 2, 4, 5, 7 : un miroir, puis (5, 7) un quart de tour. Transposée (5) = miroir
        // horizontal puis quart de tour anti-horaire ; transverse (7) = miroir puis quart
        // de tour horaire.
        if (\in_array($orientation, [2, 5, 7], true)) {
            imageflip($image, \IMG_FLIP_HORIZONTAL);
        } elseif (4 === $orientation) {
            imageflip($image, \IMG_FLIP_VERTICAL);
        }

        $antiHoraire = match ($orientation) {
            3 => 180,
            6, 7 => 270,
            5, 8 => 90,
            default => 0,
        };

        if (0 === $antiHoraire) {
            return $image;
        }

        $transparent = imagecolorallocatealpha($image, 0, 0, 0, 127);
        $tournee = imagerotate($image, $antiHoraire, false === $transparent ? 0 : $transparent);

        if (false === $tournee) {
            throw new \RuntimeException('La rotation de l\'image a échoué.');
        }

        // Une image neuve de GD mélange l'alpha au lieu de le garder : sans ces deux
        // lignes, un PNG détouré pivoté ressortirait sur fond noir.
        imagealphablending($tournee, false);
        imagesavealpha($tournee, true);

        return $tournee;
    }

    /**
     * Le bloc TIFF du premier segment `APP1 Exif`, lu segment par segment depuis `SOI`
     * et arrêté à `SOS` : au-delà ne viennent que des données compressées.
     *
     * @param resource $flux
     */
    private function tiffDuJpeg($flux): ?string
    {
        if ("\xFF\xD8" !== fread($flux, 2)) {
            return null;
        }

        for ($i = 0; $i < self::SEGMENTS_MAX; ++$i) {
            $entete = fread($flux, 4);

            if (!\is_string($entete) || 4 !== \strlen($entete) || "\xFF" !== $entete[0]) {
                return null;
            }

            $marqueur = \ord($entete[1]);
            $longueur = (int) unpack('n', $entete, 2)[1] - 2;

            if (0xDA === $marqueur || 0xD9 === $marqueur || $longueur < 0) {
                return null;
            }

            if (0xE1 === $marqueur && $longueur > 6) {
                $charge = (string) fread($flux, $longueur);

                if (str_starts_with($charge, "Exif\x00\x00")) {
                    return substr($charge, 6);
                }

                continue;
            }

            if (0 !== fseek($flux, $longueur, \SEEK_CUR)) {
                return null;
            }
        }

        return null;
    }

    /**
     * Le bloc TIFF du morceau `EXIF` d'un conteneur RIFF/WebP. Certains encodeurs le
     * préfixent de `Exif\0\0` comme en JPEG, d'autres non : on accepte les deux.
     *
     * @param resource $flux
     */
    private function tiffDuWebp($flux): ?string
    {
        $entete = fread($flux, 12);

        if (!\is_string($entete) || 12 !== \strlen($entete)
            || !str_starts_with($entete, 'RIFF') || 'WEBP' !== substr($entete, 8, 4)) {
            return null;
        }

        for ($i = 0; $i < self::SEGMENTS_MAX; ++$i) {
            $morceau = fread($flux, 8);

            if (!\is_string($morceau) || 8 !== \strlen($morceau)) {
                return null;
            }

            $longueur = (int) unpack('V', $morceau, 4)[1];

            if ('EXIF' === substr($morceau, 0, 4)) {
                $charge = (string) fread($flux, min($longueur, 65_536));

                return str_starts_with($charge, "Exif\x00\x00") ? substr($charge, 6) : $charge;
            }

            // Les morceaux RIFF sont alignés sur deux octets.
            if (0 !== fseek($flux, $longueur + ($longueur % 2), \SEEK_CUR)) {
                return null;
            }
        }

        return null;
    }

    /** L'étiquette `Orientation` de l'IFD principal, bornée à 1..8. */
    private function orientationDuTiff(string $tiff): int
    {
        if (\strlen($tiff) < 8) {
            return 1;
        }

        $gros = str_starts_with($tiff, 'MM');

        if (!$gros && !str_starts_with($tiff, 'II')) {
            return 1;
        }

        $offset = $this->mot($tiff, 4, 4, $gros);

        if ($offset < 8 || $offset + 2 > \strlen($tiff)) {
            return 1;
        }

        $entrees = $this->mot($tiff, $offset, 2, $gros);

        for ($i = 0; $i < $entrees; ++$i) {
            $position = $offset + 2 + $i * 12;

            if ($position + 12 > \strlen($tiff)) {
                break;
            }

            if (self::TAG_ORIENTATION === $this->mot($tiff, $position, 2, $gros)) {
                // Type SHORT, compte 1 : la valeur tient dans les deux premiers octets
                // du champ de valeur, alignée à gauche quel que soit le boutisme.
                $valeur = $this->mot($tiff, $position + 8, 2, $gros);

                return $valeur >= 1 && $valeur <= 8 ? $valeur : 1;
            }
        }

        return 1;
    }

    private function mot(string $octets, int $position, int $taille, bool $gros): int
    {
        $format = 2 === $taille ? ($gros ? 'n' : 'v') : ($gros ? 'N' : 'V');
        $lu = @unpack($format, $octets, $position);

        return false === $lu ? 0 : (int) $lu[1];
    }
}
