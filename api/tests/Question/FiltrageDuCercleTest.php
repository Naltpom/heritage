<?php

declare(strict_types=1);

namespace App\Tests\Question;

use App\Module\Core\Security\CercleDeVisibiliteInterface;
use App\Module\Question\Service\LacunesVisibles;
use PHPUnit\Framework\TestCase;

/**
 * **Le cercle de visibilité n'est interrogé que depuis UN fichier de ce module.**.
 *
 * C'est le garde-fou structurel de l'ADR 0003 appliqué à `Question`, et il est copié —
 * volontairement, à l'identique — de `tests/Almanach/FiltrageDuCercleTest.php`. Le motif
 * y est écrit et vaut mot pour mot ici : *« deux endroits qui décident de la
 * confidentialité, et le second serait celui qu'on oublierait de corriger »*.
 *
 * ## Pourquoi un test de forme plutôt qu'un test de comportement
 *
 * Parce qu'un second point de filtrage **ne fait rougir aucun test de comportement le
 * jour où il apparaît**. Il commence toujours par être juste : quelqu'un ajoute un
 * `if ($cercle->voitPersonne(...))` dans un contrôleur, la sortie est correcte, tout
 * passe. C'est six mois plus tard, quand la règle change d'un côté et pas de l'autre,
 * que la fuite arrive — et à ce moment-là plus personne ne sait qu'il y avait deux
 * endroits.
 *
 * Ce module a **quatre** endroits où la tentation existe : le détecteur (« ne détectons
 * que ce qui est visible »), le dépôt (« ajoutons la clause au SQL, c'est plus
 * rapide »), l'orchestrateur, et le contrôleur du jalon 3. Aucun des quatre n'a le
 * droit.
 *
 * ## Le témoin de vie
 *
 * `FICHIERS_ATTENDUS_AU_MOINS` garde le balayage lui-même : un `RecursiveDirectory`
 * pointé sur un dossier renommé rendrait zéro fichier, donc zéro citation, donc un vert
 * parfait qui ne vérifie plus rien. Un test qui ne peut annoncer qu'un seul résultat
 * n'informe de rien.
 */
final class FiltrageDuCercleTest extends TestCase
{
    /** 20 fichiers mesurés au 2026-09-23 ; le plancher garde le balayage, pas le module. */
    private const int FICHIERS_ATTENDUS_AU_MOINS = 15;

    public function testLeFiltreDuCercleNEstEcritQuAUnSeulEndroitDuModule(): void
    {
        $racine = \dirname(__DIR__, 2).'/src/Module/Question';
        $fichiers = self::fichiersPhp($racine);

        self::assertGreaterThanOrEqual(
            self::FICHIERS_ATTENDUS_AU_MOINS,
            \count($fichiers),
            \sprintf(
                'Le balayage n’a vu que %d fichiers sous src/Module/Question, or on en attend au moins %d. '
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
            ['Service/'.self::nomCourt(LacunesVisibles::class).'.php'],
            $citants,
            "Le cercle de visibilité doit être interrogé depuis UN SEUL fichier de ce module.\n"
            ."Le détecteur, le dépôt, l'orchestrateur et le contrôleur reçoivent ou rendent des listes\n"
            ."déjà filtrées : ils ne décident de rien. Un second point de filtrage est celui qu'on\n"
            ."oubliera de corriger — et il ne fera rougir aucun test de comportement le jour où il\n"
            .'apparaîtra. Fichiers qui nomment l’interface : '.implode(', ', $citants),
        );
    }

    /**
     * Le nom est-il écrit dans le **code** — et non dans un commentaire ?
     *
     * On compare le dernier segment de chaque jeton de nom : les trois formes
     * (`CercleDeVisibiliteInterface` seul, importé, ou pleinement qualifié dans le
     * corps) sont la même dépendance.
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
