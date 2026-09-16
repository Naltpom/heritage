<?php

declare(strict_types=1);

namespace App\Module\Core\Controller;

use App\Module\Core\Entity\User;
use App\Module\Core\Service\NotificationService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * `/api/notifications` — § 8 du contrat d'API.
 *
 * Accès `ATTENTE` sur les quatre routes, et ce n'est pas un oubli : **un compte en
 * attente de validation reçoit la notification de son activation** (§ 12.1). Le lui
 * fermer reviendrait à ne jamais lui apprendre qu'il est activé — rien ne le lui pousse,
 * il n'y a ni file ni Redis, et c'est cette notification qui lui dit de recharger sa
 * session.
 *
 * Aucune route ne prend un identifiant de destinataire : le destinataire est **toujours**
 * le lecteur courant. Une notification d'un autre compte rend `404` (§ 15.3) — son
 * existence même dirait qu'il se passe quelque chose sur une fiche que le lecteur ne voit
 * peut-être pas.
 */
final class NotificationController extends AbstractController
{
    public function __construct(private readonly NotificationService $notifications)
    {
    }

    #[Route('/api/notifications', name: 'core_notifications', methods: ['GET'])]
    public function lister(Request $requete, #[CurrentUser] User $lecteur): JsonResponse
    {
        return new JsonResponse($this->notifications->lister(
            $lecteur,
            $requete->query->getBoolean('nonLues'),
            $requete->query->getInt('page', 1),
            $requete->query->getInt('parPage', NotificationService::PAR_PAGE_DEFAUT),
        ));
    }

    /**
     * Le compteur de la cloche, appelé à **chaque** chargement de page — d'où son index
     * partiel dédié (`idx_core_notif_non_lues`, § 2.5 du modèle).
     */
    #[Route('/api/notifications/compteur', name: 'core_notifications_compteur', methods: ['GET'])]
    public function compteur(#[CurrentUser] User $lecteur): JsonResponse
    {
        return new JsonResponse(['nonLues' => $this->notifications->compterNonLues($lecteur)]);
    }

    #[Route('/api/notifications/{id}/lue', name: 'core_notification_lue', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function marquerLue(int $id, #[CurrentUser] User $lecteur): JsonResponse
    {
        return new JsonResponse($this->notifications->normaliser(
            $this->notifications->marquerLue($lecteur, $id),
        ));
    }

    #[Route('/api/notifications/lues', name: 'core_notifications_lues', methods: ['POST'])]
    public function marquerToutesLues(#[CurrentUser] User $lecteur): JsonResponse
    {
        return new JsonResponse(['marquees' => $this->notifications->marquerToutesLues($lecteur)]);
    }
}
