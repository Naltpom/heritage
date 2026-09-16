<?php

declare(strict_types=1);

namespace App\Module\Core\EventSubscriber;

use App\Module\Core\Entity\User;
use App\Module\Core\Enum\UserStatus;
use App\Module\Core\Http\ErrorEnvelope;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Ferme la session d'un compte suspendu **en cours de route**.
 *
 * {@see \App\Module\Core\Security\UserChecker} refuse la connexion d'un compte suspendu,
 * mais Symfony n'appelle le `user_checker` que sur une authentification **interactive** :
 * une session ouverte avant la suspension continuerait sinon à lire l'arbre jusqu'à son
 * expiration. Sur une application qui contient des données de personnes vivantes, « je
 * suspends ce compte » doit prendre effet à la requête suivante, pas dans deux semaines.
 *
 * Priorité 5 : après le pare-feu (`kernel.request` priorité 8), donc avec un jeton déjà
 * posé. Les deux chemins publics sont exclus explicitement — les interroger forcerait
 * l'authentification et annulerait le `lazy: true` du pare-feu, seul endroit où il sert.
 */
final class AccountStatusSubscriber implements EventSubscriberInterface
{
    /** Les chemins `PUBLIC_ACCESS` du § 1.6 du contrat. */
    private const array CHEMINS_PUBLICS = ['/api/connexion', '/api/inscription'];

    public function __construct(
        private readonly Security $security,
        private readonly TokenStorageInterface $tokenStorage,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['surRequete', 5]];
    }

    public function surRequete(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $chemin = $event->getRequest()->getPathInfo();

        if (!str_starts_with($chemin, '/api')) {
            return;
        }
        foreach (self::CHEMINS_PUBLICS as $public) {
            if (str_starts_with($chemin, $public)) {
                return;
            }
        }

        $utilisateur = $this->security->getUser();

        if (!$utilisateur instanceof User || UserStatus::Suspendu !== $utilisateur->getStatus()) {
            return;
        }

        // On ne se contente pas de refuser : on ferme la session, sinon chaque requête
        // suivante repaie le même contrôle et le cookie reste un jeton valide côté
        // serveur.
        $this->tokenStorage->setToken(null);
        $requete = $event->getRequest();
        if ($requete->hasSession() && $requete->getSession()->isStarted()) {
            $requete->getSession()->invalidate();
        }

        $event->setResponse(ErrorEnvelope::response(
            Response::HTTP_UNAUTHORIZED,
            'compte_suspendu',
            'Ce compte est suspendu. Contactez l’administrateur de l’arbre.',
        ));
    }
}
