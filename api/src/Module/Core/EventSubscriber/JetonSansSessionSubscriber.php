<?php

declare(strict_types=1);

namespace App\Module\Core\EventSubscriber;

use App\Module\Core\Security\RequeteParJetonDApi;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Une requête par jeton d'API est jugée **sur son jeton seul** : ses cookies sont ignorés
 * (ADR 0021 § 4 — le Bearer fait foi, sans session).
 *
 * Le pare-feu `api_jetons` est sans état : il ne lit pas le jeton de sécurité gardé en
 * session, et une requête qui porte cookie et Bearer est authentifiée par le Bearer. Mais
 * la session reste ouverte au reste de l'application, qui y lit et y écrit sans savoir par
 * où l'utilisateur est entré : la bascule « voir comme un membre », la preuve d'identité
 * récente. Sous un jeton, la session du cookie y prêterait son état — une preuve d'identité
 * récente que le jeton n'a pas donnée — et un jeton qui écrirait en session ferait poser au
 * cookie un état qu'il n'a pas choisi.
 *
 * Retirer les cookies de la requête avant tout le reste coupe ce lien à la racine : la
 * session part sans identifiant, `hasPreviousSession()` répond non, et rien ne lit ni
 * n'écrit la session du cookie. Le cookie n'est pas invalidé pour autant : il ressert,
 * intact, à la requête suivante qui viendra sans Bearer.
 *
 * Priorité 9 : après la pose de la fabrique de session (`SessionListener`, 128), qui ne lit
 * le cookie qu'au premier accès à la session, et avant le pare-feu (8).
 */
final readonly class JetonSansSessionSubscriber implements EventSubscriberInterface
{
    public function __construct(private RequeteParJetonDApi $requeteParJeton)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['surRequete', 9]];
    }

    public function surRequete(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $requete = $event->getRequest();

        if (!$this->requeteParJeton->matches($requete)) {
            return;
        }

        $requete->cookies->replace();
        $requete->headers->remove('Cookie');
    }
}
