<?php

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Doctrine\Bundle\FixturesBundle\DoctrineFixturesBundle;
use Doctrine\Bundle\MigrationsBundle\DoctrineMigrationsBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\MakerBundle\MakerBundle;
use Symfony\Bundle\MonologBundle\MonologBundle;
use Symfony\Bundle\SecurityBundle\SecurityBundle;

// ATTENTION : une recette Flex (`composer require`) RÉÉCRIT ce fichier et perd la
// garde `class_exists` du bas. C'est arrivé à l'installation de monolog-bundle, et
// ça a rouvert un défaut déjà corrigé. Après tout `composer require`, relire ce
// fichier avant de commettre.

$bundles = [
    FrameworkBundle::class => ['all' => true],
    SecurityBundle::class => ['all' => true],
    DoctrineBundle::class => ['all' => true],
    DoctrineMigrationsBundle::class => ['all' => true],

    // La journalisation n'est pas un agrément de développement : sans elle, une `500`
    // en production ne laisse aucune trace, et on rétro-conçoit la panne à la main.
    // Une soirée y est passée. En 'all' pour cette raison.
    MonologBundle::class => ['all' => true],

    // Enregistré en 'all', et non en dev/test comme le propose la recette Flex.
    //
    // Les données réelles ne sont pas versionnées : tout le monde — développement,
    // tests, intégration continue, et la pile Docker de démonstration — repose sur
    // des fixtures anonymisées à graine fixe. Or `compose.yaml` fait tourner la pile
    // de développement avec APP_ENV=prod par défaut. En dev/test seulement, la
    // commande `doctrine:fixtures:load` n'existait tout simplement pas dans le
    // conteneur, et un dépôt fraîchement cloné n'était pas démarrable.
    //
    // Le paquet est en `require` et non en `require-dev` : il est présent dans les
    // deux images, l'enregistrer en 'all' n'ajoute donc aucune dépendance.
    //
    // À savoir : `doctrine:fixtures:load` purge la base. La commande existe donc
    // aussi en production réelle. C'est un outil d'administration, au même titre
    // que `doctrine:database:drop` que Doctrine expose déjà — la protection est la
    // sauvegarde et la discipline d'exploitation, pas l'absence de la commande.
    DoctrineFixturesBundle::class => ['all' => true],
];

// MakerBundle est en `require-dev` : il est ABSENT de l'image de production, bâtie
// avec `composer install --no-dev`. Sans cette garde, démarrer ce conteneur avec
// APP_ENV=dev — ce que fait n'importe qui pour lancer une commande de mise au point —
// échoue sur :
//
//     ClassNotFoundError : Attempted to load class "MakerBundle"
//                          from namespace "Symfony\Bundle\MakerBundle".
//
// Le noyau doit tolérer l'absence d'un outil qui n'est qu'un outil. On teste donc
// la présence de la classe plutôt que de la déclarer inconditionnellement.
if (class_exists(MakerBundle::class)) {
    $bundles[MakerBundle::class] = ['dev' => true];
}

return $bundles;
