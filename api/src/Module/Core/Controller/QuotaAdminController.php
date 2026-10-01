<?php

declare(strict_types=1);

namespace App\Module\Core\Controller;

use App\Module\Core\Exception\ValidationException;
use App\Module\Core\Service\BasculeVoirComme;
use App\Module\Core\Service\QuotasDUsage;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * `/api/administration/quotas` — les quotas **partagés par toute l'installation**.
 *
 * Aujourd'hui un seul : le rattachement assisté, qui compte sur une clé unique pour tous
 * les administrateurs (il borne une dépense de l'installation, pas l'activité d'une
 * personne). Sa clé reste globale ; seule sa limite devient réglable. Les quotas par
 * compte, eux, se règlent sur `PATCH /api/administration/utilisateurs/{id}/quotas`.
 *
 * Même double garde que {@see UserAdminController} : `access_control` sur
 * `^/api/administration`, et l'attribut sur la classe.
 */
#[IsGranted('ROLE_ADMIN')]
final class QuotaAdminController extends AbstractController
{
    public function __construct(
        private readonly QuotasDUsage $quotas,
        private readonly BasculeVoirComme $bascule,
    ) {
    }

    #[Route('/api/administration/quotas', name: 'core_admin_quotas', methods: ['GET'])]
    public function lister(): JsonResponse
    {
        return new JsonResponse(['quotas' => $this->quotas->etatGlobal()]);
    }

    #[Route('/api/administration/quotas', name: 'core_admin_quotas_modifier', methods: ['PATCH'])]
    public function modifier(Request $requete): JsonResponse
    {
        $this->bascule->refuserSiActive();

        $corps = json_decode($requete->getContent(), true);

        if (!\is_array($corps) || !\array_key_exists('limites', $corps)) {
            throw new ValidationException(['limites' => 'Un objet { "limites": { clé : limite ou null } } est attendu.']);
        }

        $this->quotas->personnaliser(null, $corps['limites']);

        return new JsonResponse(['quotas' => $this->quotas->etatGlobal()]);
    }
}
