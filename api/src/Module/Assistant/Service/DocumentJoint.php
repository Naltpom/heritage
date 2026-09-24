<?php

declare(strict_types=1);

namespace App\Module\Assistant\Service;

/**
 * Un document joint à l'analyse : ses octets et leur type **reniflé**, jamais annoncé.
 *
 * Les octets vivent le temps de la requête et **ne sont stockés nulle part** par
 * l'analyse — ni sur disque, ni dans le cache de l'analyse, ni au journal. Les
 * métadonnées d'une photo (EXIF, GPS) partent telles quelles au modèle, qui en a parfois
 * besoin pour lire un acte photographié de travers ; c'est pour cela que rien d'autre
 * ne les garde. Joindre ces documents comme preuves est un geste séparé du membre
 * (`POST /api/medias`), qui passe, lui, par la purge des métadonnées du module `Media`.
 */
final readonly class DocumentJoint
{
    public function __construct(
        public string $typeMime,
        public string $octets,
        public string $nom,
    ) {
    }
}
