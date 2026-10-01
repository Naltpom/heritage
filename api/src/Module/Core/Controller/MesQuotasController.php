<?php

declare(strict_types=1);

namespace App\Module\Core\Controller;

use App\Module\Core\Entity\User;
use App\Module\Core\Service\QuotasDUsage;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * `GET /api/session/quotas` — **ses propres** quotas, consommés et effectifs.
 *
 * Les écrans de l'assistant annonçaient « 20 analyses par jour » écrit en dur : depuis
 * que l'administrateur règle une limite compte par compte, ce chiffre pouvait être faux
 * avant même le premier appel. Ils lisent désormais la même charge que la liste des
 * utilisateurs (`QuotasDUsage::etatDuCompte`), réduite au compte connecté.
 *
 * **Lire ne décompte rien** : `etatDuCompte` passe par `consommation()`, qui ne consomme
 * aucun jeton. Sous incarnation, le compte courant est le membre incarné : on voit ses
 * quotas à lui, ceux que ses gestes consommeront.
 */
final class MesQuotasController extends AbstractController
{
    public function __construct(private readonly QuotasDUsage $quotas)
    {
    }

    #[Route('/api/session/quotas', name: 'core_session_quotas', methods: ['GET'])]
    public function lister(#[CurrentUser] User $utilisateur): JsonResponse
    {
        return new JsonResponse(['quotas' => $this->quotas->etatDuCompte($utilisateur)]);
    }
}
