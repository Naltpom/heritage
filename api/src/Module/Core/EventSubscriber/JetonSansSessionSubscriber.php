<?php

declare(strict_types=1);

namespace App\Module\Core\EventSubscriber;

use App\Module\Core\Security\RequeteParJetonDApi;
use App\Module\Core\Security\SessionRefuseeAuJetonDApi;
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
 * Reste la session neuve que Symfony attacherait à la place, et qu'une écriture ouvrirait :
 * un cookie sur la réponse, un fichier de session par requête. Elle est remplacée par
 * {@see SessionRefuseeAuJetonDApi}, qui se dit absente et refuse (`403`) qui la lit ou
 * l'écrit. Ce qui sert un jeton n'y vient pas (`hasPreviousSession()` ne demande que son
 * nom) ; ce qui y vient n'a de sens que d'une requête à l'autre, donc pas pour un jeton.
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

        // Le nom reste celui de la session de l'application : `hasPreviousSession()` le
        // cherche parmi les cookies, qu'on vient de vider. La session ainsi lue n'est pas
        // démarrée — Symfony ne l'ouvre qu'au premier accès à ses données. Sans session
        // attachée (hors du noyau HTTP), il n'y a rien à remplacer.
        if ($requete->hasSession()) {
            $requete->setSession(new SessionRefuseeAuJetonDApi($requete->getSession()->getName()));
        }
    }
}
