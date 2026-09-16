<?php

declare(strict_types=1);

namespace App\Module\Core\Controller;

use App\Module\Core\Service\AuditService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * `GET /api/administration/journal` — § 10.3 du contrat d'API.
 *
 * **Une seule route, en lecture, réservée à `ROLE_ADMIN`.** C'est l'intégralité de la
 * surface HTTP du journal d'audit : il n'existe aucune route qui le modifie, aucune qui
 * l'efface, aucune qui le purge. C'est ce qui le rend inaltérable côté applicatif — cette
 * absence, et le refus d'`UPDATE`/`DELETE` posé dans
 * {@see \App\Module\Core\EventSubscriber\AuditDoctrineSubscriber}.
 *
 * `access_control` met déjà tout `^/api/administration` derrière `ROLE_ADMIN` ;
 * l'attribut ci-dessous le redit au niveau de la route. Redondant et voulu : le jour où
 * quelqu'un déplace ce contrôleur sous un autre préfixe, la protection le suit. Le
 * journal expose les IP, les agents utilisateurs et les libellés de personnes vivantes.
 */
#[IsGranted('ROLE_ADMIN')]
final class AuditController extends AbstractController
{
    public function __construct(private readonly AuditService $audit)
    {
    }

    #[Route('/api/administration/journal', name: 'core_admin_journal', methods: ['GET'])]
    public function lister(Request $requete): JsonResponse
    {
        $acteur = $requete->query->get('actorUserId');

        return new JsonResponse($this->audit->lister(
            [
                'action' => $requete->query->getString('action') ?: null,
                'actorUserId' => is_numeric($acteur) ? (int) $acteur : null,
                'subjectType' => $requete->query->getString('subjectType') ?: null,
                'subjectId' => $requete->query->getString('subjectId') ?: null,
            ],
            $requete->query->getInt('page', 1),
            $requete->query->getInt('parPage', AuditService::PAR_PAGE_DEFAUT),
        ));
    }
}
