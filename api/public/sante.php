<?php

declare(strict_types=1);

/*
 * Cible de la sonde de bonne santé du conteneur (`HEALTHCHECK` du Dockerfile).
 *
 * POURQUOI UN FICHIER, ET PAS LA RACINE DE L'APPLICATION.
 *
 * La sonde visait `http://localhost:8000/`. Aucune route n'y répond : Symfony levait donc
 * une `NotFoundHttpException` toutes les dix secondes, et ces fausses erreurs étaient le
 * seul contenu du journal. Retrouver une ligne utile demandait d'en traverser deux cents.
 * Une sonde qui remplit le journal d'erreurs qu'elle a elle-même provoquées coûte plus
 * cher que l'absence de sonde : elle rend illisible le peu qu'on journalise.
 *
 * Ce fichier est servi directement par FrankenPHP, SANS amorcer le noyau Symfony. Il
 * prouve donc ce qu'une sonde doit prouver — que Caddy écoute et que PHP s'exécute — et
 * rien de plus. Il n'ouvre pas la base : une sonde qui dépend de la base fait redémarrer
 * un conteneur sain quand c'est la base qui tousse.
 *
 * Il n'expose aucune information : ni version, ni environnement, ni chemin. Et il n'est
 * pas joignable depuis le front, dont le nginx ne relaie que `/api`.
 *
 * À REMPLACER par la route `/api/sante` du module Core quand elle existera, si l'on veut
 * une sonde qui atteste aussi que le noyau démarre et que la base répond. Ce sera un
 * autre contrat — plus informatif, mais qui redémarre le conteneur pour des pannes qui
 * ne sont pas les siennes. Le choix devra être explicite.
 */

/*
 * L'empreinte et la date de construction de l'IMAGE, gravées par le Dockerfile.
 *
 * Elles ne disent rien d'exploitable par un attaquant — ni version applicative, ni
 * chemin, ni environnement — et elles répondent à la seule question qu'on se pose
 * quand une correction semble sans effet : « le conteneur sert-il bien le code que
 * je viens d'écrire ? »
 *
 * Comparer avec `sh docker/empreinte.sh` lancé depuis `api/` sur le dépôt. Deux
 * valeurs différentes = l'image est périmée, il faut reconstruire. C'est le seul
 * moyen de rendre visible une divergence que le conteneur ne peut pas détecter
 * lui-même : il n'a pas accès au dépôt.
 */
$lire = static function (string $fichier): string {
    $valeur = @file_get_contents('/usr/local/share/heritage/'.$fichier);

    return \is_string($valeur) ? trim($valeur) : 'inconnue';
};

header('Content-Type: application/json');
header('Cache-Control: no-store');

echo json_encode([
    'etat' => 'ok',
    'empreinte' => $lire('empreinte'),
    'construite_le' => $lire('construite-le'),
], \JSON_THROW_ON_ERROR);
