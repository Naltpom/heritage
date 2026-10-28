<?php

declare(strict_types=1);

/**
 * **Les tests qui exigent PostgreSQL ont-ils réellement tourné ?**
 *
 * ── Pourquoi ce script existe ───────────────────────────────────────────────
 *
 * Trois cas de `EmpreinteEntreMoteursTest` n'ont jamais tourné nulle part : ni sur la
 * machine du commanditaire, où `pdo_pgsql` n'est pas chargée, ni en intégration
 * continue, où aucun service PostgreSQL n'était monté. Ils ne tombaient pas pour
 * autant : ils se **sautaient**, et un saut est vert.
 *
 * Monter le service ne suffit donc pas à prouver quoi que ce soit. Si le DSN est
 * faux, si l'ensemencement échoue à moitié, si l'extension manque, les trois cas se
 * sauteront de nouveau et la porte 2 restera **exactement aussi verte qu'avant**.
 * Une machine de plus, zéro mesure de plus, et rien pour le signaler.
 *
 * D'où ce contrôle : on relit la sortie JUnit que `phpunit.dist.xml` écrit déjà, et
 * on exige un **décompte**. C'est la même leçon que l'en-tête de `phpunit.dist.xml`
 * a tirée d'un `rmdir()` qui avalait la ligne de résumé : « un harnais qui ne sait
 * pas dire combien de tests il a exécutés est un harnais qu'on croit sur parole ».
 *
 * ── Ce qu'il n'exige pas ────────────────────────────────────────────────────
 *
 * `PlacementNonRegressionTest` et `PlacementFournisseurDoctrineTest` sont dans le même
 * groupe `donnees-reelles` et continuent de se sauter : il leur faut `graphe.json`, hors
 * dépôt parce qu'il contient des personnes vivantes (ADR 0006). Aucun réglage de CI ne
 * les fera tourner sur un dépôt public, et ce script ne les réclame pas — il nommerait
 * sinon une exigence qu'on ne peut pas satisfaire. (`PlacementBaseImporteeTest`, qui
 * était dans ce cas, est parti avec l'import GEDCOM — ADR 0018.)
 *
 * ── Une classe de plus par contrainte absente du schéma des tests ───────────
 *
 * Le schéma des tests est construit depuis le mapping Doctrine, qui ne porte pas les `CHECK`
 * (`ContraintesDuModeleTest`). Quand une règle tient dans une contrainte de ce genre, ou dans
 * ce que seul PostgreSQL montre (la définition d'un index partiel), un test la vérifie sur la
 * base migrée, et il entre ici : sinon il se sauterait en silence, comme les trois premiers.
 */

const RAPPORT = __DIR__.'/../../api/var/log/phpunit.xml';

/** Les classes surveillées, et le nombre de cas que chacune doit exécuter. */
const CLASSES = [
    'App\Tests\Tree\EmpreinteEntreMoteursTest' => 3,
    'App\Tests\Proposal\IndexDIdempotencePostgresTest' => 3,
    'App\Tests\Proposal\RetraitDUnBrouillonPostgresTest' => 2,
];

function echouer(string $message): never
{
    fwrite(STDERR, "::error::{$message}\n");
    exit(1);
}

if (!is_file(RAPPORT)) {
    echouer(sprintf(
        'Rapport JUnit absent (%s) : phpunit n\'a pas tourné, ou n\'a pas écrit son journal.',
        RAPPORT,
    ));
}

$xml = simplexml_load_file(RAPPORT);

if (false === $xml) {
    echouer('Rapport JUnit illisible : '.RAPPORT);
}

$total = 0;

foreach (CLASSES as $classe => $attendus) {
    $suites = $xml->xpath(sprintf('//testsuite[@name=%s]', escaperXpath($classe)));

    if (!\is_array($suites) || [] === $suites) {
        echouer(sprintf(
            '%s n\'apparaît pas dans le rapport JUnit. La classe a été renommée, exclue, '
            .'ou la suite ne l\'a pas collectée — dans les trois cas elle ne garde plus rien.',
            $classe,
        ));
    }

    $suite = $suites[0];
    $executes = (int) $suite['tests'];

    /* Les sauts se comptent sur les éléments, pas sur l'attribut : selon la version de
       PHPUnit l'attribut `skipped` est absent du rapport, et un attribut absent lu comme
       un entier vaut zéro — soit exactement la réponse rassurante qu'on ne veut pas. */
    $sautes = \count((array) $suite->xpath('.//skipped'));
    $echecs = \count((array) $suite->xpath('.//failure'));
    $erreurs = \count((array) $suite->xpath('.//error'));

    printf(
        "%s : %d cas exécutés, %d sautés, %d échecs, %d erreurs.\n",
        $classe,
        $executes,
        $sautes,
        $echecs,
        $erreurs,
    );

    if ($sautes > 0) {
        echouer(sprintf(
            '%d cas de %s se sont SAUTÉS. Le service PostgreSQL est monté mais ces tests ne '
            .'le touchent pas : DSN faux, extension pdo_pgsql absente, ou ensemencement vide. '
            .'Un saut ici est un vert qui ne mesure rien.',
            $sautes,
            $classe,
        ));
    }

    if ($executes < $attendus) {
        echouer(sprintf(
            '%s n\'a exécuté que %d cas sur %d attendus.',
            $classe,
            $executes,
            $attendus,
        ));
    }

    $total += $executes;
}

printf("Les %d cas sur PostgreSQL ont bien tourné.\n", $total);

/** Un littéral XPath sûr, y compris si le nom porte une apostrophe. */
function escaperXpath(string $valeur): string
{
    if (!str_contains($valeur, "'")) {
        return "'".$valeur."'";
    }

    return 'concat(\''.str_replace("'", '\', "\'", \'', $valeur).'\')';
}
