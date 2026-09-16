<?php

declare(strict_types=1);

namespace App\Module\Media\Service;

/**
 * Ce que {@see PurgeDesMetadonnees} rend : un fichier temporaire propre, et ce qu'on a
 * appris de lui en le nettoyant.
 *
 * `purgee` est recopié tel quel dans `med_media.exif_stripped`, qui est une **colonne
 * d'audit** : elle doit permettre de répondre, des mois plus tard, à « est-on certain
 * qu'aucune coordonnée GPS n'est partie avec cette photo de famille ». On ne la met donc
 * à `true` que lorsque le fichier a été **entièrement réécrit** à partir de ses seuls
 * pixels — un nettoyage partiel rend `false`, et la nuance est le tout de la colonne.
 */
final readonly class ResultatDePurge
{
    public function __construct(
        /** Chemin d'un fichier temporaire, à déposer puis à oublier. */
        public string $chemin,
        public string $mime,
        public ?int $largeur,
        public ?int $hauteur,
        public ?int $pages,
        public bool $purgee,
    ) {
    }
}
