<?php

declare(strict_types=1);

namespace App\Module\Soutien\Controller;

use App\Module\Core\Security\ReserveAuxMembres;
use App\Module\Soutien\Service\BilanDuSoutien;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * « Soutenir le site » — les dons Liberapay qui paient le serveur.
 *
 * ```
 * GET /api/soutien   le lien de don, le rythme du moment, l'historique par mois et par année
 * ```
 *
 * Réservée aux comptes **actifs**, comme le reste de ce qui n'est pas l'inscription : la
 * page est une affaire de famille. Ce qu'elle montre est par ailleurs public sur Liberapay.
 *
 * ## Une lecture qui écrit
 *
 * Le premier affichage de la journée pose le relevé du jour ({@see \App\Module\Soutien\Service\ReleveDuJour}).
 * C'est une écriture sous un `GET`, dite franchement — comme la page « Questions » — :
 * idempotente, une par jour au plus, et sans rien qui dépende du lecteur.
 */
#[Route('/api/soutien')]
#[ReserveAuxMembres]
final class SoutienController extends AbstractController
{
    public function __construct(
        private readonly BilanDuSoutien $bilan,
    ) {
    }

    #[Route('', name: 'soutien_bilan', methods: ['GET'])]
    public function bilan(): JsonResponse
    {
        $reponse = new JsonResponse($this->bilan->charge());
        $reponse->headers->set('Cache-Control', 'private, no-store');

        return $reponse;
    }
}
