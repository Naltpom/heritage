<?php

declare(strict_types=1);

namespace App\Module\Core\Controller;

use App\Module\Core\Exception\ValidationException;
use App\Module\Core\Service\Incarnation;
use App\Module\Core\Service\SessionPayloadBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Se connecter en tant qu'un autre compte, et en revenir — ADR 0010.
 *
 * ```
 * POST   /api/administration/incarnation   {userId}   ROLE_ADMIN   200 · 404 · 409 · 422
 * DELETE /api/session/incarnation                     connecté     200 · 409
 * ```
 *
 * **Le retour ne vit pas sous `/api/administration`**, et ce n'est pas un oubli : pendant
 * l'incarnation, le compte connecté est le membre, qui n'a pas `ROLE_ADMIN`. Une route de
 * retour derrière `access_control: ROLE_ADMIN` serait une porte qu'on ne peut plus rouvrir
 * de l'intérieur.
 *
 * Les deux rendent la charge de session **du compte désormais connecté** : le front la
 * pose telle quelle, bandeau compris.
 */
final class IncarnationController extends AbstractController
{
    public function __construct(
        private readonly Incarnation $incarnation,
        private readonly SessionPayloadBuilder $charge,
    ) {
    }

    #[Route('/api/administration/incarnation', name: 'core_admin_incarnation', methods: ['POST'])]
    public function commencer(Request $requete): JsonResponse
    {
        $corps = json_decode($requete->getContent(), true);
        $userId = \is_array($corps) ? ($corps['userId'] ?? null) : null;

        if (!\is_int($userId)) {
            throw new ValidationException(['userId' => 'Un identifiant de compte est attendu.']);
        }

        return $this->reponse($this->charge->construire($this->incarnation->commencer($userId)));
    }

    #[Route('/api/session/incarnation', name: 'core_session_incarnation_quitter', methods: ['DELETE'])]
    public function terminer(): JsonResponse
    {
        return $this->reponse($this->charge->construire($this->incarnation->terminer()));
    }

    /** @param array<string, mixed> $charge */
    private function reponse(array $charge): JsonResponse
    {
        $reponse = new JsonResponse($charge);
        $reponse->headers->set('Cache-Control', 'private, no-store');

        return $reponse;
    }
}
