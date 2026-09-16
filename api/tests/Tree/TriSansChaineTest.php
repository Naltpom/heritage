<?php

declare(strict_types=1);

namespace App\Tests\Tree;

use App\Module\Tree\Serializer\EtatVivant;
use App\Module\Tree\Service\VueDeLArbrePourChronologie;
use App\Module\Tree\Service\VueDeLArbrePourMembership;
use PHPUnit\Framework\TestCase;

/**
 * `QueryBuilder::orderBy()` ne reçoit plus de chaîne `'ASC'` / `'DESC'` dans les trois
 * services chauds de `Tree`.
 *
 * ## Pourquoi ce test est un test de SOURCE et pas un test de comportement
 *
 * C'est la question qu'il faut se poser avant d'en écrire un pareil, et ici la réponse a
 * été mesurée plutôt que supposée. La dépréciation a bien été observée — cinquante entrées
 * « User Deprecated » dans `docker logs heritage-api-1`, doctrine/orm issue 11313 :
 *
 *     Passing string as $order to Doctrine\ORM\QueryBuilder::orderBy() is deprecated,
 *     use an instance of SortDirection instead.
 *
 * Mais elle n'est PAS observable depuis la suite. Vérification faite en remettant `'ASC'`
 * à `VueDeLArbrePourMembership.php:146` : `tests/Tree/AdaptateursPourLesAutresModulesTest`
 * reste vert, 10 tests, 46 assertions — alors que `phpunit.dist.xml` porte pourtant
 * `failOnDeprecation="true"` et déclare `Doctrine\Deprecations\Deprecation::trigger` comme
 * déclencheur. La couche de dépréciation de Doctrine n'est simplement pas armée dans
 * l'environnement de test ; elle l'est dans le conteneur, par doctrine-bundle.
 *
 * Un test de comportement serait donc **un test qui ne peut pas échouer** : exactement ce
 * qu'on cherche à ne pas écrire. On lit donc la source, qui elle ne ment pas.
 *
 * ## Pourquoi ce n'est pas une liste de chemins recopiée
 *
 * Les trois classes sont nommées par `::class` et leur fichier retrouvé par réflexion. Un
 * déplacement ou un renommage casse la **compilation** (phpstan les résout), au lieu de
 * laisser trois chaînes de chemins pointer dans le vide et ce test passer au vert sur un
 * ensemble devenu vide — la panne silencieuse type de ce genre de garde.
 *
 * ## Ce que ce test ne couvre pas, et c'est assumé
 *
 * 48 appels passant encore une chaîne subsistent ailleurs dans `src/` (Core, Media,
 * Proposal, et le reste de Tree). Ils ne sont pas dans le périmètre de ce lot et plusieurs
 * de leurs fichiers sont en cours d'édition par d'autres. Aucun décompte global n'est donc
 * affirmé ici : il rougirait chaque fois qu'un autre en corrige un, ce qui punirait
 * précisément le bon geste. La taille affirmée plus bas est celle du seul ensemble que ce
 * lot possède.
 */
final class TriSansChaineTest extends TestCase
{
    /**
     * Les trois fichiers corrigés, et le nombre d'appels de tri que chacun porte.
     *
     * Le nombre est affirmé, et pas seulement l'absence de chaîne : sans lui, supprimer
     * les appels — ou déplacer la requête ailleurs — rendrait ce test vert sur un ensemble
     * vide. « Un test qui ne peut pas échouer ne prouve rien », et un parcours dont on
     * n'affirme pas la cardinalité en est un.
     *
     * @return array<class-string, int>
     */
    private static function appelsAttendus(): array
    {
        return [
            VueDeLArbrePourChronologie::class => 3,
            EtatVivant::class => 1,
            // `:146` est traversé à CHAQUE connexion : c'est la ligne qui produisait le
            // plus d'entrées au journal.
            VueDeLArbrePourMembership::class => 4,
        ];
    }

    public function testLesTroisServicesNePassentPlusDeChaineDeTri(): void
    {
        $attendus = self::appelsAttendus();

        self::assertCount(3, $attendus, 'Ce lot couvre trois fichiers ; la table en décrit un autre nombre.');

        foreach ($attendus as $classe => $nombre) {
            $source = self::source($classe);

            self::assertSame(
                $nombre,
                preg_match_all('/->(?:add)?[Oo]rderBy\(/', $source),
                \sprintf(
                    '%s doit porter %d appels de tri. Un nombre différent veut dire qu’un appel a '
                    .'été ajouté ou retiré depuis, et que ce test ne décrit plus ce qu’il garde.',
                    $classe,
                    $nombre,
                ),
            );

            $chaines = [];
            preg_match_all('/->(?:add)?[Oo]rderBy\([^)]*,\s*\'(?:ASC|DESC)\'\s*\)/', $source, $chaines);

            self::assertSame(
                [],
                $chaines[0],
                \sprintf(
                    '%s passe encore une chaîne à orderBy()/addOrderBy(). Doctrine le déprécie '
                    .'(doctrine/orm issue 11313) et le supprimera à la prochaine version majeure : '
                    .'la seconde forme attendue est `\\SortDirection::Ascending` / `::Descending`, '
                    .'une énumération globale fournie par symfony/polyfill-php86. Rien ne casse '
                    .'aujourd’hui — c’est bien pour ça que seul ce test le dira.',
                    $classe,
                ),
            );
        }
    }

    /**
     * La contrepartie positive du test ci-dessus.
     *
     * Sans elle, remplacer les appels par n'importe quoi d'autre — un `Expr\OrderBy` monté
     * à la main, ou un tri déplacé dans le DQL — passerait pour une correction. On exige
     * la forme voulue, pas seulement l'absence de l'ancienne.
     */
    public function testLaFormeRetenueEstBienLEnumeration(): void
    {
        foreach (self::appelsAttendus() as $classe => $nombre) {
            self::assertSame(
                $nombre,
                preg_match_all('/->(?:add)?[Oo]rderBy\([^)]*,\s*\\\\SortDirection::(?:Ascending|Descending)\s*\)/', self::source($classe)),
                \sprintf('Les %d appels de %s doivent tous passer \\SortDirection.', $nombre, $classe),
            );
        }
    }

    /** @param class-string $classe */
    private static function source(string $classe): string
    {
        // Par réflexion, et non par un chemin en dur : voir l'en-tête. Un renommage doit
        // casser la compilation, pas vider silencieusement l'ensemble parcouru.
        $fichier = (new \ReflectionClass($classe))->getFileName();

        self::assertIsString($fichier, \sprintf('Le fichier de %s doit être lisible.', $classe));

        $source = file_get_contents($fichier);

        self::assertIsString($source, \sprintf('Le fichier de %s doit être lisible.', $classe));

        return $source;
    }
}
