<?php

declare(strict_types=1);

namespace App\Module\Almanach\Controller;

use App\Module\Almanach\Service\NormaliseurDesAnniversaires;
use App\Module\Core\Security\ReserveAuxMembres;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * La route du calendrier des anniversaires.
 *
 * ```
 * GET /api/anniversaires     MEMBRE   200   (compte en attente : 403 regime_en_attente)
 * ```
 *
 * ## Ce contrôleur ne filtre rien
 *
 * Même règle que {@see CarteController}, et pour la même raison : le cercle est appliqué
 * une seule fois, dans {@see \App\Module\Almanach\Service\FluxVisible}. Cette méthode
 * délègue et répond.
 *
 * ## L'année entière, et aucun paramètre
 *
 * Pas de `?mois=`, pas de `?jour=`. La navigation de mois en mois est un **mouvement
 * d'écran**, pas une requête : le client a toute l'année en mémoire et change de page sans
 * réseau — ce qui est exactement ce qu'on veut sur un téléphone, où la flèche « mois
 * suivant » se presse vite et où la connexion est ce qu'elle est.
 *
 * Le corollaire est que le serveur n'a **aucun jour courant** à connaître. Il ne déplace
 * pas le 29 février, il ne met pas « aujourd'hui » en tête, il ne calcule pas d'âge. Toutes
 * ces décisions supposent un fuseau horaire et une intention d'affichage que seul le client
 * possède.
 */
#[ReserveAuxMembres]
final class AnniversairesController extends AbstractController
{
    /**
     * `Cache-Control: private, no-store` et `Vary: Cookie` : la charge dépend du cercle du
     * lecteur, donc de son cookie de session. Sans `Vary`, un cache partagé peut servir le
     * calendrier d'un membre du cercle à un membre hors cercle.
     */
    #[Route('/api/anniversaires', name: 'almanach_anniversaires', methods: ['GET'])]
    public function anniversaires(NormaliseurDesAnniversaires $normaliseur): JsonResponse
    {
        $reponse = new JsonResponse($normaliseur->vue());
        $reponse->headers->set('Cache-Control', 'private, no-store');
        $reponse->headers->set('Vary', 'Cookie');

        return $reponse;
    }
}
