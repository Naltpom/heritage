<?php

declare(strict_types=1);

namespace App\Tests\Media;

use App\Module\Media\Service\PurgeDesMetadonnees;
use PHPUnit\Framework\TestCase;

/**
 * Le seuil en pixels tient-il dans la mémoire que l'image de l'API accorde réellement ?
 *
 * ── POURQUOI CE TEST EXISTE ────────────────────────────────────────────────────────
 *
 * `PIXELS_MAX` a valu 40 Mpx en face d'un `memory_limit` de 128 Mio : le garde-fou
 * promettait cinq fois ce qu'il pouvait tenir. Entre le vrai plafond et le sien, il
 * laissait passer une image que le processus ne survivait pas à décoder — l'utilisateur
 * recevait un 500 là où cette classe existe précisément pour rendre un 422.
 *
 * Le défaut n'était visible nulle part, parce que les deux nombres vivaient dans deux
 * mondes qui ne se parlent pas : l'un dans une constante PHP, l'autre dans un réglage
 * que **personne n'avait écrit** (l'image n'embarquait aucun php.ini, la limite était le
 * défaut compilé). Ce test met les deux nombres dans la même phrase, et refait la
 * division.
 *
 * ── CE QU'IL LIT, ET POURQUOI CETTE SOURCE-LÀ ──────────────────────────────────────
 *
 * Il relit `docker/php.ini`, c'est-à-dire **le fichier que le Dockerfile copie dans
 * l'image**, et non `ini_get('memory_limit')` : la suite tourne sur le PHP du poste ou
 * du runner, dont la limite n'a aucun rapport avec celle du conteneur. Interroger
 * l'environnement d'exécution donnerait un vert qui ne parle pas du conteneur.
 *
 * Ce test ne prouve donc PAS que le conteneur applique cette limite — c'est un autre
 * contrôle, `.github/scripts/verifier-les-limites-php.sh`, qui interroge `php -i` dans
 * le conteneur. Les deux ensemble tiennent la chaîne : celui-ci lie la constante au
 * fichier, l'autre lie le fichier au conteneur.
 */
final class PlafondDePixelsTest extends TestCase
{
    /**
     * Coût mémoire d'un pixel pendant {@see PurgeDesMetadonnees::purgerImage()}.
     *
     * 8 et non 4 : la source décodée et la toile neuve sont vivantes en même temps, à
     * 4 octets par pixel chacune. Mesuré sur PHP 8.4 + GD, séquence isolée —
     * 1 Mpx → 8 388 608 o, 2 Mpx → 16 777 216 o, 4 Mpx → 31 457 280 o,
     * 6 Mpx → 48 234 496 o, soit 7,86 à 8,39 o/px selon l'arrondi de l'allocateur.
     */
    private const int OCTETS_PAR_PIXEL = 8;

    /**
     * Part du `memory_limit` réservée à ce qui n'est pas l'image.
     *
     * Le noyau Symfony occupe 6 Mio au seul démarrage (mesuré) ; s'y ajoutent Doctrine
     * et son unité de travail, l'écriture du journal d'audit, le tampon d'encodage de
     * sortie. La moitié est une réserve large, et c'est voulu : un garde-fou calé au
     * millimètre sur le budget n'est plus un garde-fou.
     */
    private const float PART_RESERVEE = 0.5;

    public function testLePlafondDePixelsTientDansLeBudgetMemoireDeLImage(): void
    {
        $limite = self::memoireDeLImage();
        $budget = (int) ($limite * (1 - self::PART_RESERVEE));
        $autorises = intdiv($budget, self::OCTETS_PAR_PIXEL);
        $demandes = self::plafondDePixels() * self::OCTETS_PAR_PIXEL;

        self::assertLessThanOrEqual(
            $autorises,
            self::plafondDePixels(),
            \sprintf(
                "Le garde-fou promet plus que la mémoire disponible.\n".
                "  memory_limit (docker/php.ini) ... %d octets (%.0f Mio)\n".
                "  réservé au reste de la requête .. %.0f %%\n".
                "  budget image .................... %d octets (%.0f Mio)\n".
                "  PIXELS_MAX ...................... %d px, soit %d octets (%.0f Mio) à %d o/px\n".
                "  plafond soutenable .............. %d px\n".
                'Corrigez PIXELS_MAX, ou relevez memory_limit dans api/docker/php.ini — et refaites le calcul dans les deux commentaires.',
                $limite,
                $limite / 1048576,
                self::PART_RESERVEE * 100,
                $budget,
                $budget / 1048576,
                self::plafondDePixels(),
                $demandes,
                $demandes / 1048576,
                self::OCTETS_PAR_PIXEL,
                $autorises,
            ),
        );
    }

    /**
     * Le seuil ne doit pas non plus être ridiculement bas.
     *
     * Sans ce cas, `PIXELS_MAX = 1` passerait le test précédent au vert : on aurait
     * prouvé qu'un garde-fou fermé tient dans la mémoire, ce qui est vrai et inutile.
     * Les images d'archives du projet montent à 3 000 px de large, soit moins de
     * 10 Mpx ; le seuil doit rester franchement au-dessus de ce besoin constaté.
     */
    public function testLePlafondDePixelsResteAuDessusDesImagesDuProjet(): void
    {
        self::assertGreaterThanOrEqual(
            20_000_000,
            self::plafondDePixels(),
            'Le seuil est descendu sous 20 Mpx : les scans d\'actes du projet (≈ 10 Mpx) n\'ont plus de marge.',
        );
    }

    /**
     * Le fichier de limites est-il bien celui que le Dockerfile copie dans l'image ?
     *
     * Un `docker/php.ini` que le Dockerfile ne copierait plus laisserait ce test au vert
     * en mesurant un fichier que le conteneur ne lit pas — exactement le défaut que la
     * règle « un artefact dérivé n'est jamais corrigé par la correction de sa source »
     * décrit. Le contrôle est grossier, et c'est son mérite : il tient en une ligne et
     * il attrape la suppression du `COPY`.
     */
    public function testLeDockerfileCopieBienCeFichierDansLImage(): void
    {
        $dockerfile = file_get_contents(self::racineApi().'/Dockerfile');

        self::assertIsString($dockerfile);
        self::assertMatchesRegularExpression(
            '#^COPY\s+docker/php\.ini\s+/usr/local/etc/php/conf\.d/#m',
            $dockerfile,
            'api/Dockerfile ne copie plus docker/php.ini dans le répertoire balayé par PHP : '
            .'les limites mesurées ici ne sont plus celles du conteneur.',
        );
    }

    /** Le `memory_limit`, en octets, tel que le fichier livré dans l'image le pose. */
    private static function memoireDeLImage(): int
    {
        $chemin = self::racineApi().'/docker/php.ini';

        self::assertFileExists($chemin, 'api/docker/php.ini a disparu : l\'image repartirait sur les défauts compilés de PHP.');

        $contenu = file_get_contents($chemin);
        self::assertIsString($contenu);

        // Les lignes de commentaire d'un .ini commencent par « ; ». Le motif exige le
        // début de ligne pour ne pas capter le calcul écrit en commentaire au-dessus.
        self::assertSame(
            1,
            preg_match('/^\s*memory_limit\s*=\s*(\d+)([KMG]?)\s*$/mi', $contenu, $trouve),
            'Aucune directive memory_limit active dans api/docker/php.ini.',
        );

        return (int) $trouve[1] * match (strtoupper($trouve[2])) {
            'K' => 1024,
            'M' => 1048576,
            'G' => 1073741824,
            default => 1,
        };
    }

    private static function plafondDePixels(): int
    {
        $constante = new \ReflectionClassConstant(PurgeDesMetadonnees::class, 'PIXELS_MAX');

        return (int) $constante->getValue();
    }

    private static function racineApi(): string
    {
        return \dirname(__DIR__, 2);
    }
}
