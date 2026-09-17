<?php

declare(strict_types=1);

namespace App\Module\Almanach\Controller;

use App\Module\Almanach\Service\NormaliseurDeLaCarte;
use App\Module\Core\Security\RegimeVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * La route de la carte des lieux.
 *
 * ```
 * GET /api/carte     ATTENTE   200
 * ```
 *
 * ## Ce contrôleur ne filtre rien
 *
 * « Le cercle de visibilité est appliqué dans la sérialisation, pas dans le contrôleur »
 * (§ 8 du dossier de mission). Cette méthode délègue et répond. Tout le filtrage vit dans
 * {@see \App\Module\Almanach\Service\FluxVisible}, qui est le point **unique** du module ;
 * le jour où un `if` de confidentialité apparaîtrait ici, il y aurait deux endroits qui en
 * décident, et le second serait celui qu'on oublierait de corriger.
 *
 * ## `ATTENTE`, comme la chronologie
 *
 * Un compte en attente de validation lit l'arbre ancien pendant plusieurs jours (§ 12.1) ;
 * son régime `Regime::Aucun` ne lui montre que des personnes décédées, et sa carte ne porte
 * donc que des lieux de défunts. Ce n'est pas une faveur : c'est ce que le cercle décide,
 * en un seul endroit, sans qu'aucune ligne de ce module n'ait à connaître les régimes.
 *
 * ## Aucun paramètre de requête — et c'est une décision, pas un manque
 *
 * Pas de `?genre=`, pas de `?debut=`, pas de `?bbox=`. Les pastilles et le curseur de temps
 * sont des **réglages de rendu** : le client reçoit la charge entière et filtre en mémoire.
 * Les ouvrir coûterait un aller-retour par clic, une surface de validation sur une route de
 * lecture, et surtout une ambiguïté sur `resume` — « ces compteurs valent-ils avant ou
 * après mon filtre ? ». Ici la réponse est écrite et ne bouge pas : **avant tout filtre
 * client, après le cercle**.
 */
#[IsGranted(RegimeVoter::ATTENTE)]
final class CarteController extends AbstractController
{
    /**
     * `Cache-Control: private, no-store` et `Vary: Cookie` : la charge dépend du cercle du
     * lecteur, donc de son cookie de session. Sans `Vary`, un cache partagé peut servir la
     * carte d'un membre du cercle à un membre hors cercle — même précaution que sur
     * `GET /api/arbre/graphe` et `GET /api/chronologie`, et elle ne se devine pas.
     *
     * Pas d'`ETag` : il faudrait une empreinte incorporant l'arbre, `tree_place` **et** le
     * cercle du lecteur, et un `ETag` qui oublie une de ces entrées sert la carte de
     * quelqu'un d'autre. On s'en passe tant que personne n'a mesuré qu'il manque.
     */
    #[Route('/api/carte', name: 'almanach_carte', methods: ['GET'])]
    public function carte(NormaliseurDeLaCarte $normaliseur): JsonResponse
    {
        $reponse = new JsonResponse($normaliseur->vue());
        $reponse->headers->set('Cache-Control', 'private, no-store');
        $reponse->headers->set('Vary', 'Cookie');

        return $reponse;
    }
}
