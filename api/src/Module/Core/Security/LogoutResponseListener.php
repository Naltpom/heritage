<?php

declare(strict_types=1);

namespace App\Module\Core\Security;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Security\Http\Event\LogoutEvent;

/**
 * `POST /api/deconnexion` est interceptée par le listener `logout` du pare-feu — le
 * contrôleur n'est jamais exécuté. Sans réponse posée ici, Symfony redirige ; le contrat
 * (§ 2) exige `{"deconnecte": true}`.
 */
#[AsEventListener(event: LogoutEvent::class)]
final class LogoutResponseListener
{
    public function __invoke(LogoutEvent $event): void
    {
        $event->setResponse(new JsonResponse(['deconnecte' => true]));
    }
}
