<?php

declare(strict_types=1);

namespace App\Module\Proposal\Service;

use App\Module\Proposal\Exception\DonneesInvalides;

/**
 * Le lien d'une citation — l'adresse où la pièce se consulte en ligne (visionneuse
 * d'archives départementales, notice INSEE…).
 *
 * La fiche l'affiche en `href` : on n'accepte donc que `http` et `https`. Un
 * `javascript:` posé par une demande et validé d'un clic distrait deviendrait un lien
 * piégé sur une fiche que toute la famille ouvre.
 */
final class AdresseDeCitation
{
    public const int LONGUEUR_MAX = 2000;

    public static function controler(?string $url, string $chemin): ?string
    {
        if (null === $url) {
            return null;
        }

        $url = trim($url);
        if ('' === $url) {
            return null;
        }

        $schema = strtolower((string) parse_url($url, \PHP_URL_SCHEME));
        $hote = parse_url($url, \PHP_URL_HOST);

        if (!\in_array($schema, ['http', 'https'], true) || !\is_string($hote) || '' === $hote || 1 === preg_match('/\s/', $url)) {
            throw DonneesInvalides::pourChamp($chemin, 'Le lien d\'une citation est une adresse web complète, en http:// ou https://.');
        }

        if (mb_strlen($url) > self::LONGUEUR_MAX) {
            throw DonneesInvalides::pourChamp($chemin, \sprintf('Le lien d\'une citation tient en %d caractères au plus.', self::LONGUEUR_MAX));
        }

        return $url;
    }
}
