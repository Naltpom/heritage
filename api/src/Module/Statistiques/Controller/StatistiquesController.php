<?php

declare(strict_types=1);

namespace App\Module\Statistiques\Controller;

use App\Module\Core\Security\ReserveAuxMembres;
use App\Module\Statistiques\Service\ChantierDeLArbre;
use App\Module\Statistiques\Service\PorteeDesStatistiques;
use App\Module\Statistiques\Service\StatistiquesVisibles;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Les deux routes de l'onglet **Explorer › Stats**.
 *
 * ```
 * GET /api/explorer/stats                   MEMBRE  200   (compte en attente : 403 regime_en_attente)
 * GET /api/administration/stats/chantier    ADMIN   200   (membre : 403)
 * ```
 *
 * Les deux prennent `?portee=arbre|tout` (décision du 05/10/2026) : `arbre`, le défaut, ne
 * compte que l'arbre du lecteur ; `tout` couvre tout son périmètre et n'est accordé qu'à
 * l'administration — un membre qui le demande reçoit `arbre`, sans erreur. La portée
 * retenue revient dans la charge (`portee`). Ce n'est pas un filtre de confidentialité : le
 * périmètre et le cercle s'appliquent dans les deux cas, dans `StatistiquesVisibles`.
 *
 * ## Ce contrôleur ne filtre rien
 *
 * Comme la carte : le cercle s'applique en un seul endroit,
 * {@see StatistiquesVisibles}. Un `if` de confidentialité ici ferait deux endroits qui en
 * décident.
 *
 * ## Deux routes et non une charge à drapeau
 *
 * Le chantier (fiches à compléter, activité des membres) est réservé à l'administration.
 * Le servir dans la charge commune avec un « `admin: true` » que l'écran lirait, ce serait
 * l'envoyer à tout le monde et compter sur l'écran pour le taire. Il vit donc sous
 * `/api/administration`, que `security.yaml` ferme déjà à tout autre rôle — et
 * `#[IsGranted]` le redit ici, pour que le jour où le préfixe bougerait, la route ne
 * s'ouvre pas.
 *
 * ## Le cache
 *
 * `private, no-store` et `Vary: Cookie`, comme toutes les lectures qui dépendent du
 * cercle : un cache partagé ne doit jamais servir les statistiques d'un lecteur à un autre.
 * Le cache côté serveur est celui du **relevé** (l'arbre non filtré), voir
 * `ReleveEnCache` ; le calcul par lecteur coûte quelques millisecondes.
 */
final class StatistiquesController extends AbstractController
{
    #[Route('/api/explorer/stats', name: 'statistiques_explorer', methods: ['GET'])]
    #[ReserveAuxMembres]
    public function stats(Request $requete, StatistiquesVisibles $statistiques): JsonResponse
    {
        return $this->prive(new JsonResponse($statistiques->pourLeLecteur($this->portee($requete))));
    }

    #[Route('/api/administration/stats/chantier', name: 'statistiques_chantier', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function chantier(Request $requete, ChantierDeLArbre $chantier): JsonResponse
    {
        return $this->prive(new JsonResponse($chantier->vue($this->portee($requete))));
    }

    private function portee(Request $requete): PorteeDesStatistiques
    {
        $demandee = PorteeDesStatistiques::tryFrom((string) $requete->query->get('portee', ''));

        return PorteeDesStatistiques::Tout === $demandee && $this->isGranted('ROLE_ADMIN')
            ? PorteeDesStatistiques::Tout
            : PorteeDesStatistiques::Arbre;
    }

    private function prive(JsonResponse $reponse): JsonResponse
    {
        $reponse->headers->set('Cache-Control', 'private, no-store');
        $reponse->headers->set('Vary', 'Cookie');

        return $reponse;
    }
}
