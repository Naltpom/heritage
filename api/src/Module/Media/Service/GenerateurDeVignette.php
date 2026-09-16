<?php

declare(strict_types=1);

namespace App\Module\Media\Service;

use Psr\Log\LoggerInterface;

/**
 * La vignette, générée à l'enregistrement.
 *
 * **Pourquoi à l'enregistrement et pas à la volée.** Les images d'archives de ce projet
 * montent à 3 000 px de large ; une fiche de personne en aligne facilement une dizaine.
 * Servir les originaux dans une liste, c'est demander au navigateur de décoder
 * 100 Mpx pour afficher des carrés de 160 px — sur un poste modeste, la page rame et
 * l'utilisateur croit que l'application est lente. La générer à l'enregistrement coûte
 * une fois ce qu'une génération à la volée coûterait à chaque affichage.
 *
 * Elle est produite à partir du fichier **déjà purgé** : elle n'a donc aucune métadonnée
 * à hériter, et il n'y a pas deux chemins de purge à maintenir.
 *
 * **Les PDF n'ont pas de vignette**, et c'est assumé : les rastériser demanderait
 * `imagick` ou Ghostscript, une dépendance que le dossier de mission demande de ne pas
 * ajouter sans nécessité. `thumbnail_name` reste `NULL` et l'interface affiche un
 * substitut — c'est exactement ce que la colonne prévoit.
 *
 * Un échec de génération n'est **jamais** fatal : un média sans vignette reste un média
 * valide, alors qu'un téléversement refusé parce que la vignette n'a pas pu être écrite
 * ferait perdre une preuve d'archive pour une image d'agrément.
 */
final class GenerateurDeVignette
{
    /** Côté maximal. 320 px couvre l'affichage en rétine d'une vignette de 160 px. */
    public const int COTE_MAX = 320;

    public function __construct(
        private readonly StockageMedia $stockage,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param string $cheminSource fichier déjà purgé, déjà déposé dans le stockage
     *
     * @return string|null le nom de la vignette, ou `null` si elle n'a pas pu être faite
     */
    public function generer(string $cheminSource, string $mime): ?string
    {
        if ('application/pdf' === $mime) {
            return null;
        }

        try {
            return $this->genererImage($cheminSource, $mime);
        } catch (\Throwable $erreur) {
            $this->logger->warning('Vignette non générée : {raison}', ['raison' => $erreur->getMessage()]);

            return null;
        }
    }

    private function genererImage(string $cheminSource, string $mime): ?string
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

        try {
            $largeur = imagesx($source);
            $hauteur = imagesy($source);
            $facteur = min(1.0, self::COTE_MAX / max($largeur, $hauteur));

            // Une image déjà plus petite que la vignette n'est pas agrandie : on
            // recopie sa taille. Agrandir produirait un fichier plus lourd que
            // l'original pour une image plus floue.
            $largeurCible = max(1, (int) round($largeur * $facteur));
            $hauteurCible = max(1, (int) round($hauteur * $facteur));

            $toile = imagecreatetruecolor($largeurCible, $hauteurCible);

            if (false === $toile) {
                return null;
            }

            // La vignette d'un PNG ou d'un WebP garde son canal alpha ; celle d'un JPEG
            // n'en a pas. On sort donc dans le format d'entrée plutôt qu'en JPEG
            // systématique, qui aplatirait un portrait détouré sur du noir.
            $avecAlpha = 'image/jpeg' !== $mime;

            if ($avecAlpha) {
                imagealphablending($toile, false);
                imagesavealpha($toile, true);
                $transparent = imagecolorallocatealpha($toile, 0, 0, 0, 127);

                if (false !== $transparent) {
                    imagefilledrectangle($toile, 0, 0, $largeurCible, $hauteurCible, $transparent);
                }
            }

            imagecopyresampled($toile, $source, 0, 0, 0, 0, $largeurCible, $hauteurCible, $largeur, $hauteur);

            $nom = $this->stockage->nomAleatoire($mime);
            $temporaire = tempnam(sys_get_temp_dir(), 'vig_');

            if (false === $temporaire) {
                imagedestroy($toile);

                return null;
            }

            $ecrit = match ($mime) {
                'image/jpeg' => imagejpeg($toile, $temporaire, 82),
                'image/png' => imagepng($toile, $temporaire, 6),
                'image/webp' => imagewebp($toile, $temporaire, 82),
                default => false,
            };

            imagedestroy($toile);

            if (!$ecrit) {
                @unlink($temporaire);

                return null;
            }

            $this->stockage->deposer($temporaire, $nom, vignette: true);

            return $nom;
        } finally {
            imagedestroy($source);
        }
    }
}
