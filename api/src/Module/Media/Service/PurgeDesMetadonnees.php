<?php

declare(strict_types=1);

namespace App\Module\Media\Service;

use App\Module\Core\Exception\ValidationException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Le ré-encodage qui purge les métadonnées — d'abord et avant tout les **coordonnées
 * GPS**.
 *
 * Ce n'est pas une précaution théorique : un appareil photo moderne, et tout téléphone,
 * inscrit dans l'en-tête EXIF la latitude et la longitude de la prise de vue. Une photo
 * de famille prise au domicile et publiée telle quelle donne l'adresse de la maison à
 * quiconque ouvre le fichier — dans une application dont la raison d'être est de réunir
 * des gens apparentés, c'est-à-dire de rassembler au même endroit des personnes qui
 * n'ont pas toutes la même idée de ce qu'elles veulent partager.
 *
 * ## Les images : on ne « retire » rien, on réécrit
 *
 * La méthode n'est pas de supprimer les segments EXIF connus, mais de **décoder les
 * pixels et de réencoder un fichier neuf**. La différence compte : une purge par liste
 * de segments laisse passer ce qu'elle ne connaît pas — XMP, IPTC, ICC porteur de
 * commentaires, segments propriétaires du constructeur, la vignette EXIF intégrée qui
 * est une seconde image avec son propre en-tête. Un ré-encodage ne conserve **que** ce
 * qu'il a su lire : des pixels.
 *
 * La copie sur une toile neuve (`imagecreatetruecolor` + `imagecopy`) plutôt qu'un
 * ré-encodage direct de la ressource décodée est volontaire : elle normalise aussi les
 * images à palette et n'emporte aucune propriété résiduelle de l'objet source.
 *
 * Une seule métadonnée survit, **sous forme de pixels** : l'orientation EXIF, appliquée
 * avant l'encodage par {@see OrientationExif}. L'effacer sans l'appliquer couchait
 * toutes les photos de téléphone prises en hauteur.
 *
 * ## Les PDF : ce qui est traité, et ce qui ne l'est pas
 *
 * Un PDF n'a pas d'EXIF mais a ses propres métadonnées : le dictionnaire `/Info`
 * (auteur, titre, logiciel, dates) et un paquet XMP. Sans rastériseur — ni `imagick`, ni
 * Ghostscript, et le dossier de mission interdit d'ajouter une dépendance qui ne sert
 * pas —, on ne peut pas réécrire un PDF comme on réécrit une image.
 *
 * Ce qui est fait : les valeurs `/Info` et les paquets XMP **présents en clair** dans le
 * fichier sont écrasés, à **longueur d'octets constante**, ce qui laisse la table
 * `xref` valide sans avoir à la reconstruire.
 *
 * Ce qui n'est **pas** fait, et qu'il faut savoir :
 *
 * - les métadonnées placées dans un flux d'objets compressé (`/ObjStm`) ne sont pas
 *   atteintes — les toucher supposerait de décompresser puis de recompresser, donc de
 *   réécrire les offsets ;
 * - l'**EXIF des images incluses dans le PDF** (un scan produit par un téléphone) n'est
 *   pas atteint non plus ;
 * - les fichiers joints, les annotations et les révisions incrémentales ne sont pas
 *   inspectés.
 *
 * C'est pourquoi un PDF ressort avec `exifStripped = false` : la colonne dit la vérité,
 * qui est « ce fichier n'a pas été entièrement réécrit ». **Une preuve déposée en PDF
 * n'est pas garantie exempte de coordonnées.** Si cette garantie devient nécessaire, la
 * réponse n'est pas de raffiner cette classe : c'est de rastériser le PDF, donc
 * d'ajouter une dépendance, donc de prendre une décision qui n'appartient pas à ce
 * module.
 */
final class PurgeDesMetadonnees
{
    /**
     * Plafond de surface, en pixels.
     *
     * Sans ce plafond, un PNG de 50 Ko déclarant 30 000 × 30 000 pixels demande
     * plusieurs gibioctets à la première ligne de décodage et emporte le processus. Le
     * plafond de taille en octets (§ 7.1) ne protège pas de ça — c'est tout l'intérêt
     * d'une bombe de décompression que d'être petite sur le réseau.
     *
     * ── LE CALCUL. Un seuil sans son raisonnement se re-dérègle ─────────────────────
     *
     * Le coût n'est pas de 4 octets par pixel mais de **8** : {@see purgerImage()} tient
     * DEUX images GD vivantes en même temps — la source décodée et la toile neuve —, en
     * couleurs vraies, donc 4 octets par pixel chacune. Mesuré sur PHP 8.4 + GD, en
     * isolant la séquence exacte de cette méthode :
     *
     *     1 000 000 px →  8 388 608 octets → 8,39 o/px
     *     2 000 000 px → 16 777 216 octets → 8,39 o/px
     *     4 000 000 px → 31 457 280 octets → 7,86 o/px
     *     6 000 000 px → 48 234 496 octets → 8,04 o/px
     *
     * (Les écarts autour de 8,00 sont l'arrondi de `memory_get_usage(true)` à la taille
     * de bloc de l'allocateur. La valeur théorique 4 + 4 est confirmée.)
     *
     * D'où la répartition, à partir du `memory_limit` que `docker/php.ini` pose :
     *
     *     512 Mio   memory_limit de l'image
     *   − 256 Mio   réservés à tout ce qui n'est pas l'image : noyau Symfony (6 Mio
     *               mesurés au seul démarrage), Doctrine et son unité de travail,
     *               l'écriture du journal d'audit, le tampon d'encodage de sortie, et
     *               la marge qui fait qu'un garde-fou garde
     *   = 256 Mio   budget réel de l'image
     *   ÷ 8 o/px    = 33 554 432 pixels
     *
     * Arrondi EN DESSOUS : **32 Mpx**. Jamais au-dessus du budget.
     *
     * ── CE QUE VALAIT CE SEUIL AVANT, ET POURQUOI ON ÉCRIT LE CALCUL ────────────────
     *
     * Il valait 40 Mpx, c'est-à-dire ~320 Mo demandés, contre un `memory_limit` qui
     * était alors le défaut compilé de PHP — 128 Mio, dont ~64 Mio disponibles pour
     * l'image par le même partage. **Le garde-fou promettait cinq fois ce qu'il pouvait
     * tenir** : entre 8 et 40 Mpx, il laissait passer une image que le processus ne
     * survivait pas à décoder. L'utilisateur n'obtenait donc pas le 422 que cette classe
     * existe pour rendre, mais un 500 — un garde-fou qui, dans sa plage la plus utile,
     * transforme un refus propre en panne.
     *
     * Le nombre est lié à `docker/php.ini` et le lien est assertionné :
     * {@see \App\Tests\Media\PlafondDePixelsTest} relit le `memory_limit` du fichier
     * livré dans l'image et refait la division. Baisser la limite ou monter le seuil
     * sans toucher à l'autre fait rougir la CI.
     *
     * Marge réelle pour ce projet : les images d'archives montent à 3 000 px de large,
     * soit moins de 10 Mpx. 32 Mpx reste trois fois au-dessus du besoin constaté.
     */
    private const int PIXELS_MAX = 32_000_000;

    /** Qualité de ré-encodage. Assez haut pour un acte d'état civil lu à l'écran. */
    private const int QUALITE_JPEG = 88;
    private const int QUALITE_WEBP = 88;

    /** Les clés du dictionnaire `/Info` qui portent de l'information sur l'auteur ou la machine. */
    private const string CLES_INFO = 'Author|Title|Subject|Keywords|Creator|Producer|CreationDate|ModDate';

    /**
     * `var/` plutôt que le temporaire du système : en conteneur, `/tmp` est souvent
     * minuscule, et un acte numérisé de 18 Mio y échouerait sans message utile.
     *
     * Injecté par attribut, pour la raison expliquée sur {@see StockageMedia}.
     */
    public function __construct(
        #[Autowire(param: 'app.media.temporaire')]
        private readonly string $repertoireTemporaire,
        private readonly OrientationExif $orientation = new OrientationExif(),
    ) {
    }

    /**
     * @param string $chemin fichier reçu, non modifié par cet appel
     * @param string $mime   le type **réellement constaté**, jamais celui annoncé
     */
    public function purger(string $chemin, string $mime): ResultatDePurge
    {
        return 'application/pdf' === $mime
            ? $this->purgerPdf($chemin)
            : $this->purgerImage($chemin, $mime, $this->orientation->lire($chemin, $mime));
    }

    /**
     * Le même ré-encodage, avec un quart de tour imposé au lieu de l'orientation EXIF.
     *
     * Passer par ici plutôt que par un second chemin GD garde un seul endroit pour le
     * plafond de pixels, les qualités d'encodage et la conservation de l'alpha. Le
     * fichier stocké a déjà été purgé : il n'a plus d'EXIF, donc rien ne se cumule.
     *
     * @param 90|180|270 $degresHoraires
     */
    public function pivoter(string $chemin, string $mime, int $degresHoraires): ResultatDePurge
    {
        // 6, 3 et 8 sont exactement les orientations EXIF « quart de tour horaire »,
        // « demi-tour » et « quart de tour anti-horaire ».
        $orientation = match ($degresHoraires) {
            90 => 6,
            180 => 3,
            270 => 8,
        };

        return $this->purgerImage($chemin, $mime, $orientation);
    }

    /** @param int $orientation valeur EXIF 1..8 à appliquer aux pixels avant l'encodage */
    private function purgerImage(string $chemin, string $mime, int $orientation): ResultatDePurge
    {
        $taille = @getimagesize($chemin);

        if (false === $taille || $taille[0] < 1 || $taille[1] < 1) {
            // Les octets d'en-tête disaient « image » et le décodeur ne la lit pas :
            // fichier tronqué, ou en-tête posé devant autre chose. Ni l'un ni l'autre
            // n'est un type refusé — c'est un fichier invalide.
            throw new ValidationException(['fichier' => 'Ce fichier ne peut pas être lu comme une image.']);
        }

        [$largeur, $hauteur] = [$taille[0], $taille[1]];

        if ($largeur * $hauteur > self::PIXELS_MAX) {
            throw new ValidationException(['fichier' => \sprintf('Image trop grande : %d × %d pixels, maximum %d pixels au total.', $largeur, $hauteur, self::PIXELS_MAX)]);
        }

        $source = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($chemin),
            'image/png' => @imagecreatefrompng($chemin),
            'image/webp' => @imagecreatefromwebp($chemin),
            default => throw new \InvalidArgumentException(\sprintf('Type d\'image non géré : %s', $mime)),
        };

        if (false === $source) {
            throw new ValidationException(['fichier' => 'Ce fichier ne peut pas être lu comme une image.']);
        }

        $toile = imagecreatetruecolor($largeur, $hauteur);

        // JPEG n'a pas de canal alpha ; PNG et WebP en ont un, et une toile neuve
        // est noire opaque. Sans ces trois lignes, un portrait détouré ressort sur
        // fond noir — la purge ne doit pas abîmer l'image qu'elle nettoie.
        if ('image/jpeg' !== $mime) {
            imagealphablending($toile, false);
            imagesavealpha($toile, true);
            $transparent = imagecolorallocatealpha($toile, 0, 0, 0, 127);

            if (false !== $transparent) {
                imagefilledrectangle($toile, 0, 0, $largeur, $hauteur, $transparent);
            }
        }

        imagecopy($toile, $source, 0, 0, 0, 0, $largeur, $hauteur);

        // La source est lâchée AVANT le redressement : `imagerotate()` alloue une
        // troisième image, et le calcul de `PIXELS_MAX` n'en prévoit que deux vivantes.
        // (Depuis PHP 8, `imagedestroy()` ne libère rien — seule la dernière référence
        // qui tombe le fait.)
        unset($source);

        // L'orientation EXIF part avec le reste des métadonnées : elle doit donc être
        // appliquée aux pixels, sinon la photo prise en hauteur ressort couchée. Les
        // dimensions rendues sont celles d'APRÈS le redressement — c'est l'image que
        // tout le monde verra.
        $toile = $this->orientation->redresser($toile, $orientation);

        $destination = $this->fichierTemporaire();
        $ecrit = $this->encoder($toile, $destination, $mime);
        [$largeur, $hauteur] = [imagesx($toile), imagesy($toile)];
        unset($toile);

        if (!$ecrit) {
            @unlink($destination);

            throw new \RuntimeException('Le ré-encodage de l\'image a échoué.');
        }

        return new ResultatDePurge($destination, $mime, $largeur, $hauteur, null, true);
    }

    /**
     * L'encodage de sortie se fait dans le **format d'entrée** : réencoder un PNG à
     * canal alpha en JPEG aplatirait la transparence sur du noir, et la purge n'a pas à
     * abîmer l'image qu'elle nettoie.
     */
    private function encoder(\GdImage $toile, string $destination, string $mime): bool
    {
        return match ($mime) {
            'image/jpeg' => imagejpeg($toile, $destination, self::QUALITE_JPEG),
            'image/png' => imagepng($toile, $destination, 6),
            'image/webp' => imagewebp($toile, $destination, self::QUALITE_WEBP),
            default => false,
        };
    }

    private function purgerPdf(string $chemin): ResultatDePurge
    {
        $octets = @file_get_contents($chemin);

        if (!\is_string($octets)) {
            throw new ValidationException(['fichier' => 'Ce fichier ne peut pas être lu.']);
        }

        $nettoye = $this->effacerXmp($this->effacerInfo($octets));

        // Garde-fou : toute la méthode repose sur la conservation des offsets. Si une
        // substitution a changé la longueur, la table `xref` ne pointe plus au bon
        // endroit et le fichier est cassé. On préfère un PDF non nettoyé — dont la
        // colonne d'audit dira qu'il n'est pas nettoyé — à un PDF illisible.
        if (\strlen($nettoye) !== \strlen($octets)) {
            $nettoye = $octets;
        }

        $destination = $this->fichierTemporaire();

        if (false === @file_put_contents($destination, $nettoye)) {
            throw new \RuntimeException('Écriture du PDF nettoyé impossible.');
        }

        return new ResultatDePurge(
            $destination,
            'application/pdf',
            null,
            null,
            $this->compterLesPages($nettoye),
            // Jamais `true` sur un PDF : voir l'en-tête de classe. La colonne d'audit
            // ne doit pas affirmer une garantie qu'on n'a pas.
            false,
        );
    }

    /** Écrase les valeurs `/Info` en clair, à longueur constante. */
    private function effacerInfo(string $octets): string
    {
        $motif = '/\/(?:'.self::CLES_INFO.')\s*(\((?:\\\\.|[^()\\\\])*\)|<[0-9A-Fa-f\s]*>)/s';

        $resultat = preg_replace_callback(
            $motif,
            static function (array $trouve): string {
                $complet = $trouve[0];
                $valeur = $trouve[1];
                $longueur = \strlen($valeur);

                if ($longueur < 2) {
                    return $complet;
                }

                // `(texte)` devient `(      )`, `<48656C6C6F>` devient `<0000000000>` :
                // même nombre d'octets, donc mêmes offsets dans la table `xref`.
                $vide = str_starts_with($valeur, '(')
                    ? '('.str_repeat(' ', $longueur - 2).')'
                    : '<'.str_repeat('0', $longueur - 2).'>';

                return substr($complet, 0, \strlen($complet) - $longueur).$vide;
            },
            $octets,
        );

        return \is_string($resultat) ? $resultat : $octets;
    }

    /**
     * Remplace le contenu des paquets XMP en clair par un paquet vide, complété par des
     * espaces jusqu'à la longueur d'origine.
     *
     * Le remplissage par des espaces n'est pas un bricolage : la spécification XMP
     * prévoit explicitement qu'un paquet soit suivi d'un rembourrage d'espaces, pour
     * qu'un éditeur puisse réécrire les métadonnées sans déplacer le reste du fichier.
     * On se sert de la même propriété dans l'autre sens.
     */
    private function effacerXmp(string $octets): string
    {
        $vide = '<x:xmpmeta xmlns:x="adobe:ns:meta/"><rdf:RDF xmlns:rdf='
            .'"http://www.w3.org/1999/02/22-rdf-syntax-ns#"></rdf:RDF></x:xmpmeta>';

        $resultat = preg_replace_callback(
            '/<\?xpacket begin=.*?\?>.*?<\?xpacket end=.*?\?>/s',
            static function (array $trouve) use ($vide): string {
                $longueur = \strlen($trouve[0]);

                return $longueur >= \strlen($vide)
                    ? $vide.str_repeat(' ', $longueur - \strlen($vide))
                    : str_repeat(' ', $longueur);
            },
            $octets,
        );

        return \is_string($resultat) ? $resultat : $octets;
    }

    /**
     * Compte les pages en clair. Rend `null` plutôt que `0` quand le compte n'est pas
     * lisible — dans un PDF à flux d'objets compressés, `/Type /Page` n'apparaît pas
     * littéralement, et annoncer « 0 page » serait un mensonge là où « on ne sait pas »
     * est la vérité. La colonne est `NULL`able exactement pour ça.
     */
    private function compterLesPages(string $octets): ?int
    {
        $compte = preg_match_all('#/Type\s*/Page[^s]#', $octets);

        return \is_int($compte) && $compte > 0 ? min($compte, 32767) : null;
    }

    private function fichierTemporaire(): string
    {
        $chemin = @tempnam($this->repertoireTemporaire, 'med_');

        if (false === $chemin) {
            throw new \RuntimeException('Impossible de créer un fichier temporaire pour la purge.');
        }

        return $chemin;
    }
}
