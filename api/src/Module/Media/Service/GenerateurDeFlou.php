<?php

declare(strict_types=1);

namespace App\Module\Media\Service;

/**
 * La variante **floue** d'une photo de personne vivante — la réciprocité des portraits
 * (ADR 0016) : sans photo de lui, un lecteur ne reçoit des vivants que ceci.
 *
 * ## Irréversible, et c'est tout le sujet
 *
 * Un flou gaussien seul se déconvolue en partie. On commence donc par **réduire l'image à
 * une douzaine de pixels** de côté — l'information disparaît là, pour de bon —, puis on la
 * ré-agrandit et on lisse : l'œil voit une silhouette et des couleurs, le fichier ne
 * contient rien de plus. Le flou est fait **côté serveur** : l'image nette ne quitte jamais
 * le serveur, et aucun CSS ni aucune console ne peut la retrouver.
 *
 * La sortie est toujours un JPEG : le ré-encodage efface au passage toute métadonnée, et
 * la transparence d'un portrait détouré n'a pas d'importance à ce niveau de flou.
 */
final readonly class GenerateurDeFlou
{
    /** Le côté de l'image réduite : ce qui reste d'information, au plus. */
    public const int COTE_REDUIT = 12;

    /** Le côté de l'image servie : celui d'une vignette. */
    public const int COTE_SERVI = 320;

    public function __construct(private StockageMedia $stockage)
    {
    }

    /**
     * Le chemin de la variante floue, générée au premier besoin et gardée en cache.
     * `null` quand l'image ne se lit pas : l'appelant rend alors un `404`, jamais l'original.
     */
    public function pour(string $nomDeStockage, string $mime): ?string
    {
        $chemin = $this->stockage->cheminDuFlou($nomDeStockage);

        if (is_file($chemin)) {
            return $chemin;
        }

        $octets = $this->flouter($this->stockage->chemin($nomDeStockage), $mime);

        if (null === $octets) {
            return null;
        }

        $this->stockage->deposerLeFlou($octets, $nomDeStockage);

        return $chemin;
    }

    /** Les octets JPEG de la variante floue, ou `null` si l'image ne se lit pas. */
    public function flouter(string $cheminSource, string $mime): ?string
    {
        $source = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($cheminSource),
            'image/png' => @imagecreatefrompng($cheminSource),
            'image/webp' => @imagecreatefromwebp($cheminSource),
            default => false,
        };

        if (false === $source) {
            return null;
        }

        $largeur = imagesx($source);
        $hauteur = imagesy($source);
        $ratio = $largeur / max(1, $hauteur);

        // 1 — la réduction, où l'information se perd.
        $petiteL = max(1, (int) round($ratio >= 1 ? self::COTE_REDUIT : self::COTE_REDUIT * $ratio));
        $petiteH = max(1, (int) round($ratio >= 1 ? self::COTE_REDUIT / $ratio : self::COTE_REDUIT));
        $petite = imagecreatetruecolor($petiteL, $petiteH);

        // 2 — le ré-agrandissement, lissé.
        $grandeL = max(1, (int) round($ratio >= 1 ? self::COTE_SERVI : self::COTE_SERVI * $ratio));
        $grandeH = max(1, (int) round($ratio >= 1 ? self::COTE_SERVI / $ratio : self::COTE_SERVI));
        $grande = imagecreatetruecolor($grandeL, $grandeH);

        if (false === $petite || false === $grande) {
            return null;
        }

        // Fond blanc : une transparence aplatie sur du noir assombrirait le flou.
        $blanc = imagecolorallocate($petite, 255, 255, 255);
        if (false !== $blanc) {
            imagefilledrectangle($petite, 0, 0, $petiteL, $petiteH, $blanc);
        }

        imagecopyresampled($petite, $source, 0, 0, 0, 0, $petiteL, $petiteH, $largeur, $hauteur);
        imagecopyresampled($grande, $petite, 0, 0, 0, 0, $grandeL, $grandeH, $petiteL, $petiteH);

        for ($i = 0; $i < 6; ++$i) {
            imagefilter($grande, \IMG_FILTER_GAUSSIAN_BLUR);
        }

        ob_start();
        imagejpeg($grande, null, 70);
        $octets = (string) ob_get_clean();

        return '' === $octets ? null : $octets;
    }
}
