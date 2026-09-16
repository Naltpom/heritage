<?php

declare(strict_types=1);

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

// Pas de garde `method_exists()` ici : symfony/dotenv est une dépendance ferme du
// projet, la vérification est morte et PHPStan la signale à juste titre.
(new Dotenv())->bootEnv(dirname(__DIR__).'/.env');

if ($_SERVER['APP_DEBUG']) {
    umask(0000);
}

/*
 * =============================================================================
 *  RIEN N'EST PARTAGÉ ENTRE DEUX PROCESSUS DE TEST
 * =============================================================================
 *
 * Sur cette machine il y a en permanence plusieurs suites PHPUnit en vol — une
 * par agent — et `vendor/bin/phpunit` sans argument exécute TOUTE la suite. Trois
 * ressources étaient partagées, et chacune produisait des pannes qui ne
 * ressemblaient pas à leur cause :
 *
 *   1. le fichier SQLite de `.env.test`
 *   2. les pools de cache sur le système de fichiers (compteurs de limitation)
 *   3. le conteneur compilé, dans `var/cache/test/`
 *
 * Les symptômes, tous trompeurs, aucun ne désignant sa cause :
 *
 *     SQLSTATE[HY000]: General error: 1 table core_audit_log already exists
 *     SQLSTATE[HY000]: General error: 1 no such table: core_user
 *     Cannot rename "...App3A6A.tmp" to "...Container.xml.meta" : Accès refusé
 *     des tests qui passent ou échouent selon l'ordre, à code identique
 *     rm var/data_test.db → « Device or resource busy »
 *
 * On cherche longtemps un bogue qui n'existe pas. Le numéro de processus dans le
 * nom de chaque ressource suffit à rendre les suites étanches. Mesure à l'appui :
 * deux exécutions simultanées rendaient 76 erreurs identiques dues au seul point 3.
 *
 * L'isolation passe par `$_ENV`/`$_SERVER` et non par `.env.test`, qui est commun
 * aux sept modules et ne sait pas calculer un PID. Elle est posée AVANT l'amorçage
 * du noyau : `%env(resolve:DATABASE_URL)%` est résolu à l'exécution et non à la
 * compilation, donc rien n'est figé dans un conteneur en cache.
 */

$racine = dirname(__DIR__);
$pid = getmypid();

/**
 * SQLite écrit à côté du fichier principal — journal, write-ahead log, mémoire
 * partagée. Les oublier laisse une base à moitié vivante derrière soi.
 *
 * @return list<string>
 */
$fichiersSqlite = static fn (string $base): array => [$base, $base.'-journal', $base.'-wal', $base.'-shm'];

$effacerBase = static function (string $base) use ($fichiersSqlite): void {
    foreach ($fichiersSqlite($base) as $fichier) {
        if (is_file($fichier)) {
            @unlink($fichier);
        }
    }
};

$effacerDossier = static function (string $dossier): void {
    if (!is_dir($dossier)) {
        return;
    }

    $entrees = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dossier, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($entrees as $entree) {
        \assert($entree instanceof SplFileInfo);
        $entree->isDir() ? @rmdir($entree->getPathname()) : @unlink($entree->getPathname());
    }

    @rmdir($dossier);
};

// 1. La base de données.
$baseDeTest = $racine.'/var/data_test_'.$pid.'.db';
$urlDeTest = 'sqlite:///'.$baseDeTest;
$_ENV['DATABASE_URL'] = $urlDeTest;
$_SERVER['DATABASE_URL'] = $urlDeTest;

// 2. Les pools de cache. Les compteurs de `login_throttling` et des limiteurs de
//    débit vivent là : deux suites concurrentes se comptent mutuellement leurs
//    tentatives, et un test « blocage après 5 essais » dépend du voisinage. Vider
//    le pool au démarrage ne suffit pas, un autre processus le repeuple entre deux
//    requêtes. Lu par `config/packages/cache.yaml` en `when@test`.
$poolsDeTest = $racine.'/var/cache/test-pools/'.$pid;
$_ENV['TEST_CACHE_DIR'] = $poolsDeTest;
$_SERVER['TEST_CACHE_DIR'] = $poolsDeTest;

// 3. Le conteneur compilé. Lu par `App\Kernel::getCacheDir()`. Coût : une
//    compilation par processus, et seulement en test. Moins cher qu'une suite qui ment.
// Une vraie variable d'environnement l'emporte : un poste qui ne fait tourner
// qu'une suite à la fois, ou la CI, peut vouloir garder un conteneur compilé
// partagé et chaud plutôt que d'en payer un par processus. C'est alors une
// décision prise, pas un défaut subi — et le nettoyage ne touche pas ce dossier-là.
$noyauImpose = getenv('TEST_KERNEL_CACHE_DIR');
$noyauEstImpose = \is_string($noyauImpose) && '' !== $noyauImpose;
$noyauDeTest = $noyauEstImpose ? $noyauImpose : $racine.'/var/cache/test-'.$pid;
$_ENV['TEST_KERNEL_CACHE_DIR'] = $noyauDeTest;
$_SERVER['TEST_KERNEL_CACHE_DIR'] = $noyauDeTest;

// Un PID est recyclé par le système. Un reliquat d'une exécution précédente
// donnerait une base déjà peuplée — donc des « table already exists » dès le
// premier test — et un conteneur compilé périmé pour un code qui a changé depuis.
$effacerBase($baseDeTest);
$effacerDossier($poolsDeTest);
if (!$noyauEstImpose) {
    $effacerDossier($noyauDeTest);
}

/*
 * Balayage des restes d'exécutions mortes.
 *
 * Le nettoyage de fin de processus ne s'exécute pas quand un processus est tué net
 * — et sur cette machine, ça arrive : suites interrompues, délais dépassés, agents
 * relancés. Chaque conteneur compilé abandonné pèse quelques dizaines de mégaoctets,
 * et on en a compté dix-neuf en une session.
 *
 * Le critère est l'ÂGE et non la vivacité du processus : demander au système si un
 * PID vit encore n'est pas portable, alors qu'un dossier de test inchangé depuis
 * deux heures ne peut appartenir à aucune suite en cours — la plus lente d'entre
 * elles se compte en minutes. La marge est large exprès : effacer le cache d'une
 * suite vivante lui coûterait une recompilation, pas une corruption, mais autant
 * ne pas le faire.
 */
$limiteAge = time() - 7200;

foreach ((array) glob($racine.'/var/cache/test-*', \GLOB_ONLYDIR) as $vieuxCache) {
    if (\is_string($vieuxCache) && $vieuxCache !== $noyauDeTest && (int) @filemtime($vieuxCache) < $limiteAge) {
        $effacerDossier($vieuxCache);
    }
}

foreach ((array) glob($racine.'/var/data_test_*.db') as $vieilleBase) {
    if (\is_string($vieilleBase) && $vieilleBase !== $baseDeTest && (int) @filemtime($vieilleBase) < $limiteAge) {
        $effacerBase($vieilleBase);
    }
}

/*
 * Nettoyage en fin de processus. `register_shutdown_function` et non un
 * `tearDownAfterClass` : il se déclenche aussi quand un test échoue, quand la
 * suite est interrompue et quand une erreur fatale coupe l'exécution — c'est
 * précisément dans ces cas-là qu'on laisse des fichiers derrière soi.
 *
 * Un processus tué net (SIGKILL) laisse malgré tout ses dossiers ; ils sont dans
 * `var/`, qui est ignoré par Git, et le passage suivant du même PID les efface.
 */
register_shutdown_function(static function () use ($effacerBase, $effacerDossier, $baseDeTest, $poolsDeTest, $noyauDeTest, $noyauEstImpose): void {
    // PHPUnit installe son propre gestionnaire d'erreurs et transforme le moindre
    // avertissement en exception. Or ce nettoyage s'exécute APRÈS le dernier test :
    // il n'y a plus de cas de test sur la pile, et PHPUnit lève alors
    //
    //     NoTestCaseObjectOnCallStackException: Cannot find TestCase object on call stack
    //
    // — une erreur fatale qui masque le résultat de TOUTE la suite. Il suffit d'un
    // `rmdir()` sur un dossier que Windows tient encore ouvert pour la déclencher.
    // Le `@` ne protège pas : le gestionnaire de PHPUnit voit aussi les erreurs
    // supprimées. On le neutralise donc le temps du ménage, et on le remet.
    set_error_handler(static fn (): bool => true);

    try {
        $effacerBase($baseDeTest);
        $effacerDossier($poolsDeTest);

        if (!$noyauEstImpose) {
            $effacerDossier($noyauDeTest);
        }
    } finally {
        restore_error_handler();
    }
});
