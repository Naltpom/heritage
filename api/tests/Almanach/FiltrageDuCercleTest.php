<?php

declare(strict_types=1);

namespace App\Tests\Almanach;

use App\Module\Almanach\Service\FluxVisible;
use App\Module\Core\Security\CercleDeVisibiliteInterface;
use PHPUnit\Framework\TestCase;

/**
 * **Le cercle de visibilité n'est interrogé qu'à un seul endroit de ce module**, et c'est
 * ce fichier qui le garde.
 *
 * ## Pourquoi un test de structure et non un test de comportement
 *
 * Les tests de `CarteTest` et d'`AnniversairesTest` prouvent que le filtre **marche
 * aujourd'hui**. Ils ne prouvent pas qu'il restera unique : le jour où quelqu'un ajoutera
 * un troisième écran et recopiera trois lignes de filtrage dans son normaliseur, tous
 * resteront verts. Et c'est précisément le moment où le défaut naît — non pas quand le
 * second filtre est faux, mais quand il existe :
 *
 * > deux endroits qui décident de la confidentialité, et le second serait celui qu'on
 * > oublierait de corriger.
 *
 * ## L'unité comptée est le **fichier**, et c'est un choix
 *
 * Une dépendance vers une interface s'écrit en deux jetons — l'import et le type du
 * paramètre — et un troisième si quelqu'un préfère le nom pleinement qualifié. Compter les
 * jetons ferait donc rougir ce test pour une raison **cosmétique** (un import réécrit) et
 * le laisserait vert pour une raison **réelle** (un second filtre écrit avec le FQCN en
 * ligne, sans import). Le fichier est la bonne unité : c'est « un endroit ».
 *
 * Le message d'échec nomme les fichiers en cause plutôt que d'annoncer un nombre — un test
 * de structure qui dit « attendu 1, obtenu 2 » envoie son lecteur chercher où.
 *
 * ## Les commentaires ne comptent pas
 *
 * Le balayage passe par `PhpToken::tokenize()` et écarte les jetons ignorables, comme le
 * fait `tests/Frontieres/BalayageDesFrontieres`. Sans cela, l'en-tête de {@see FluxVisible}
 * — qui **cite** le nom de l'interface pour expliquer la règle — compterait, et documenter
 * la règle la ferait échouer.
 */
final class FiltrageDuCercleTest extends TestCase
{
    /**
     * Plancher d'assiette. Le module compte 14 fichiers PHP à l'écriture de ce test ; un
     * `glob` cassé ou une racine changée rendraient le contrôle muet, et **un test muet est
     * pire qu'absent, puisqu'il occupe la place**. Règle de la maison : tout contrôle qui
     * itère sur un ensemble découvert affirme la taille de cet ensemble.
     */
    private const int FICHIERS_ATTENDUS_AU_MOINS = 10;

    public function testLeFiltreDuCercleNEstEcritQuAUnSeulEndroitDuModule(): void
    {
        $racine = \dirname(__DIR__, 2).'/src/Module/Almanach';
        $fichiers = self::fichiersPhp($racine);

        self::assertGreaterThanOrEqual(
            self::FICHIERS_ATTENDUS_AU_MOINS,
            \count($fichiers),
            \sprintf(
                'Le balayage n’a vu que %d fichiers sous src/Module/Almanach, or on en attend au moins %d. '
                .'Un balayage devenu vide passerait au vert en ne vérifiant plus rien.',
                \count($fichiers),
                self::FICHIERS_ATTENDUS_AU_MOINS,
            ),
        );

        $court = self::nomCourt(CercleDeVisibiliteInterface::class);
        $citants = [];

        foreach ($fichiers as $chemin) {
            $code = file_get_contents($chemin);
            self::assertIsString($code, 'Fichier illisible : '.$chemin);

            if (self::leCodeNomme($code, $court)) {
                $citants[] = str_replace('\\', '/', substr($chemin, \strlen($racine) + 1));
            }
        }

        sort($citants);

        self::assertSame(
            ['Service/'.self::nomCourt(FluxVisible::class).'.php'],
            $citants,
            "Le cercle de visibilité doit être interrogé depuis UN SEUL fichier de ce module.\n"
            ."Les deux normaliseurs et les deux contrôleurs reçoivent un flux déjà filtré : ils mettent\n"
            ."en forme, ils ne décident de rien. Un second point de filtrage est celui qu'on oubliera de\n"
            ."corriger — et il ne fera rougir aucun test de comportement le jour où il apparaîtra.\n"
            .'Fichiers qui nomment l’interface : '.implode(', ', $citants),
        );
    }

    /**
     * Le nom est-il écrit dans le **code** — et non dans un commentaire ?
     *
     * On compare le dernier segment de chaque jeton de nom : `CercleDeVisibiliteInterface`
     * seul, `App\Module\Core\Security\CercleDeVisibiliteInterface` importé, ou écrit en
     * pleinement qualifié dans le corps. Les trois formes sont la même dépendance.
     */
    private static function leCodeNomme(string $code, string $court): bool
    {
        foreach (\PhpToken::tokenize($code) as $jeton) {
            if ($jeton->isIgnorable()) {
                continue;
            }

            if (!$jeton->is([\T_STRING, \T_NAME_QUALIFIED, \T_NAME_FULLY_QUALIFIED])) {
                continue;
            }

            $segments = explode('\\', $jeton->text);

            if (end($segments) === $court) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private static function fichiersPhp(string $racine): array
    {
        $fichiers = [];

        $entrees = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($racine, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($entrees as $entree) {
            \assert($entree instanceof \SplFileInfo);

            if ($entree->isFile() && 'php' === $entree->getExtension()) {
                $fichiers[] = $entree->getPathname();
            }
        }

        sort($fichiers);

        return $fichiers;
    }

    /** @param class-string $classe */
    private static function nomCourt(string $classe): string
    {
        $segments = explode('\\', $classe);

        return end($segments);
    }
}
