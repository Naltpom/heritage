<?php

declare(strict_types=1);

namespace App\Module\Media\Service;

use App\Module\Media\Entity\Media;

/**
 * Les URL `/fichier` et `/vignette` d'un média, **versionnées**.
 *
 * ## Pourquoi une version, alors que l'`ETag` suit déjà le contenu
 *
 * Les octets sont servis en `private, no-cache` avec le `sha256` pour `ETag` : un
 * navigateur qui revalide voit bien le changement. Mais une page déjà ouverte ne
 * revalide pas — une `<img>` dont l'URL n'a pas bougé est reprise du cache mémoire du
 * document, sans requête. Après une rotation, la fiche montrerait l'image d'avant
 * jusqu'au rechargement. Changer l'URL est la seule chose que toutes les `<img>` du front
 * remarquent sans une ligne de code de plus.
 *
 * ## Pourquoi dérivée du nom de stockage
 *
 * Il change à chaque réécriture ({@see Media::remplacerLeFichier()}) et à elle seule.
 * Le `sha256` est réservé au déposant et aux administrateurs ({@see NormaliseurMedia}) :
 * le mettre dans une URL que tout lecteur reçoit défera cette réserve. `updatedAt` n'a
 * que la seconde en base, et deux quarts de tour cliqués dans la même seconde
 * rendraient la même URL. Le nom, lui, est 128 bits aléatoires : en publier 48 bits
 * d'empreinte n'aide ni à le deviner ni à énumérer le stockage.
 *
 * Les quatre endroits qui composent ces URL passent par ici — un seul qui l'oublierait
 * servirait l'ancienne image sur l'écran qu'il alimente.
 */
final class AdresseDuMedia
{
    public static function fichier(Media $media): string
    {
        return self::composer($media, 'fichier');
    }

    public static function vignette(Media $media): string
    {
        return self::composer($media, 'vignette');
    }

    public static function version(Media $media): string
    {
        return substr(hash('sha256', $media->getStorageName()), 0, 12);
    }

    private static function composer(Media $media, string $segment): string
    {
        return \sprintf('/api/medias/%d/%s?v=%s', (int) $media->getId(), $segment, self::version($media));
    }
}
