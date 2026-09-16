<?php

declare(strict_types=1);

namespace App\Module\Core\Controller;

use App\Module\Core\Entity\User;
use App\Module\Core\Exception\ValidationException;
use App\Module\Core\Service\BasculeVoirComme;
use App\Module\Core\Service\SessionPayloadBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * `/api/administration/voir-comme` — la bascule du § 10.4 du contrat d'API.
 *
 * Les deux routes rendent **la charge de session** (§ 2.2), pas un accusé de réception :
 * le front a besoin du `cercle` emprunté et du `voirComme` pour afficher son bandeau
 * permanent. Sans ce bandeau, un administrateur qui ne voit plus la moitié des fiches
 * conclura à un bogue.
 *
 * Ce contrôleur ne décide de rien : toute la mécanique et ses trois pièges sont dans
 * {@see BasculeVoirComme} — **lire son en-tête**, en particulier le piège de l'`ETag`,
 * qui fait que l'outil censé vérifier la confidentialité peut cesser de vérifier quoi
 * que ce soit sans que rien ne tombe.
 */
#[IsGranted('ROLE_ADMIN')]
final class VoirCommeController extends AbstractController
{
    public function __construct(
        private readonly BasculeVoirComme $bascule,
        private readonly SessionPayloadBuilder $charge,
    ) {
    }

    #[Route('/api/administration/voir-comme', name: 'core_admin_voir_comme', methods: ['POST'])]
    public function activer(Request $requete, #[CurrentUser] User $administrateur): JsonResponse
    {
        $corps = json_decode($requete->getContent(), true);
        $memberId = \is_array($corps) ? ($corps['memberId'] ?? null) : null;

        if (!\is_int($memberId)) {
            throw new ValidationException(['memberId' => 'Un identifiant de membre est attendu.']);
        }

        // Lève `404` si le membre est inconnu — ou si `Membership` n'est pas branché :
        // emprunter un cercle qu'on ne sait pas calculer ne vérifierait rien.
        $this->bascule->activer($memberId);

        return new JsonResponse($this->charge->construire($administrateur));
    }

    #[Route('/api/administration/voir-comme', name: 'core_admin_voir_comme_quitter', methods: ['DELETE'])]
    public function desactiver(#[CurrentUser] User $administrateur): JsonResponse
    {
        $this->bascule->desactiver();

        // Le front recharge alors la session **et** le graphe : l'`ETag` du graphe
        // change avec le lecteur effectif, donc l'ancien rendu n'est plus valable.
        return new JsonResponse($this->charge->construire($administrateur));
    }
}
