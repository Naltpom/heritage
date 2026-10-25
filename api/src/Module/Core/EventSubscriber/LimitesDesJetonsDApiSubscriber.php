<?php

declare(strict_types=1);

namespace App\Module\Core\EventSubscriber;

use App\Module\Core\Security\JetonDApiAuthentifie;
use App\Module\Core\Security\RequeteParJetonDApi;
use App\Module\Core\Service\LimiteursDesJetonsDApi;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/**
 * Pose les deux plafonds du jeton d'API (ADR 0021, § 1.9 du contrat) ; les chiffres et les
 * raisons sont dans {@see LimiteursDesJetonsDApi}.
 *
 * - **Avant l'authentification** : sur une requête que prend `api_jetons`
 *   ({@see RequeteParJetonDApi}), l'IP qui a épuisé ses échecs reçoit `429` sans que le
 *   jeton soit cherché. `kernel.request` plutôt que `CheckPassportEvent` : ce dernier n'a
 *   lieu qu'une fois le jeton lu et le compte chargé — le bon jeton d'un attaquant bloqué
 *   serait déjà reconnu. Priorité 10 : avant le retrait des cookies (9) et le pare-feu (8).
 * - **Après l'authentification réussie** : `LoginSuccessEvent`, qui porte le jeton de
 *   sécurité donc l'id du jeton d'API. Une exception levée ici sort du pare-feu comme celle
 *   de `login_throttling` et passe par `ApiExceptionSubscriber`. Une connexion par cookie
 *   ou par mot de passe n'a pas d'attributs de jeton : elle n'est jamais comptée.
 */
final readonly class LimitesDesJetonsDApiSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private LimiteursDesJetonsDApi $limiteurs,
        private RequeteParJetonDApi $requeteParJeton,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['avantLAuthentification', 10],
            LoginSuccessEvent::class => 'apresLAuthentification',
        ];
    }

    public function avantLAuthentification(RequestEvent $event): void
    {
        if (!$event->isMainRequest() || !$this->requeteParJeton->matches($event->getRequest())) {
            return;
        }

        $this->limiteurs->verifierLesEchecs($event->getRequest());
    }

    public function apresLAuthentification(LoginSuccessEvent $event): void
    {
        $jeton = JetonDApiAuthentifie::depuisLeJetonDeSecurite($event->getAuthenticatedToken());

        if (null !== $jeton) {
            $this->limiteurs->compterUneRequete($jeton->id);
        }
    }
}
