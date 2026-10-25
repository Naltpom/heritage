<?php

declare(strict_types=1);

namespace App\Module\Core\Security;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestMatcherInterface;

/**
 * Le `request_matcher` du pare-feu `api_jetons` (`config/packages/security.yaml`, ADR 0021
 * § 4) : une requête `/api` qui porte `Authorization: Bearer …`.
 *
 * ## Pourquoi le schéma `Bearer`, et pas la seule présence de l'en-tête
 *
 * Le pare-feu `api_jetons` est placé **avant** `main` et le premier pare-feu qui
 * correspond prend toute la requête : ce qu'il prend, `main` ne le voit plus, et le cookie
 * de session n'y est pas lu. Un `HeaderRequestMatcher('Authorization')` lui donnerait aussi
 * les requêtes qui portent un `Authorization: Basic …` posé par un intermédiaire (un mot
 * de passe HTTP devant un serveur de préproduction, une extension de navigateur) : le front,
 * qui vit de son cookie, recevrait alors des `401` qu'il ne comprendrait pas. Seul le
 * schéma `Bearer` désigne un jeton d'API.
 *
 * ## Pourquoi `Bearer` sans tenir compte de la casse
 *
 * Le nom d'un schéma d'authentification est insensible à la casse (RFC 9110 § 11.1). Une
 * requête qui porte `bearer …` **entend** présenter un jeton : elle est jugée par
 * `api_jetons`, jamais par le cookie qu'elle porterait aussi. L'extracteur de Symfony,
 * lui, n'accepte que `Bearer` à la lettre : `bearer …` rend donc le `401` commun — un refus,
 * jamais un repli silencieux sur la session.
 *
 * ## Une requête qui porte cookie et Bearer
 *
 * **Le Bearer fait foi, sans session** : ce matcher l'envoie au pare-feu sans état, qui
 * ne lit pas le jeton de sécurité de la session, et
 * {@see \App\Module\Core\EventSubscriber\JetonSansSessionSubscriber} lui retire ses cookies
 * pour que rien, plus loin, ne lise ni n'écrive la session du cookie.
 */
final class RequeteParJetonDApi implements RequestMatcherInterface
{
    public function matches(Request $request): bool
    {
        if (!str_starts_with($request->getPathInfo(), '/api')) {
            return false;
        }

        $entete = $request->headers->get('Authorization');

        return null !== $entete && 1 === preg_match('/^Bearer(?:\s|$)/i', $entete);
    }
}
