<?php

declare(strict_types=1);

namespace App\Module\Core\EventSubscriber;

use App\Module\Core\Security\JetonDApiAuthentifie;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Event\AuthenticationTokenCreatedEvent;

/**
 * Recopie les attributs du jeton d'API — id, libellé, portée — du `UserBadge` sur le jeton
 * de sécurité, au moment où il naît (ADR 0021 § 7).
 *
 * Le badge meurt avec l'authentification ; le jeton de sécurité vit toute la requête, et
 * c'est lui que lisent ensuite `AuditDoctrineSubscriber` (les colonnes `api_token_id` et
 * `api_token_label`) et, par {@see JetonDApiAuthentifie::depuisLeJetonDeSecurite()}, tout ce
 * qui doit savoir qu'une requête vient d'un jeton. Le jeton de sécurité reste celui de
 * l'`access_token` de Symfony : l'utilisateur est le compte du jeton, l'auteur des écritures
 * aussi.
 *
 * Une authentification sans ces attributs (mot de passe, clé d'accès, Google) passe sans
 * qu'on y touche.
 */
final class AttributsDuJetonDApiSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [AuthenticationTokenCreatedEvent::class => 'surJetonCree'];
    }

    public function surJetonCree(AuthenticationTokenCreatedEvent $event): void
    {
        $badge = $event->getPassport()->getBadge(UserBadge::class);

        if (!$badge instanceof UserBadge) {
            return;
        }

        $jetonDApi = JetonDApiAuthentifie::depuisAttributs($badge->getAttributes());

        if (null === $jetonDApi) {
            return;
        }

        $jetonDeSecurite = $event->getAuthenticatedToken();
        foreach ($jetonDApi->attributs() as $nom => $valeur) {
            $jetonDeSecurite->setAttribute($nom, $valeur);
        }
    }
}
