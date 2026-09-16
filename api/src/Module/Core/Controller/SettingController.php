<?php

declare(strict_types=1);

namespace App\Module\Core\Controller;

use App\Module\Core\Entity\User;
use App\Module\Core\Exception\ValidationException;
use App\Module\Core\Service\BasculeVoirComme;
use App\Module\Core\Service\SettingService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * `GET` et `PATCH /api/administration/reglages` — § 10.2 du contrat d'API.
 *
 * C'est par ici que `cercle.generations` — le `N` du cercle de visibilité — se change
 * sans redéploiement, daté, tracé au journal d'audit et réversible (§ 6.2 du dossier de
 * mission, ADR 0003).
 *
 * **Rien n'est à invalider après un `PATCH`** : `{N}` est un segment de la clé de cache
 * du cercle, les anciennes entrées deviennent inatteignables d'elles-mêmes (§ 2.3 du
 * modèle). En revanche le **front** doit recharger la session et le graphe, sans quoi il
 * affichera l'ancien filtrage jusqu'au prochain rechargement de page.
 */
#[IsGranted('ROLE_ADMIN')]
final class SettingController extends AbstractController
{
    public function __construct(
        private readonly SettingService $reglages,
        private readonly BasculeVoirComme $bascule,
    ) {
    }

    /** Tableau nu : liste bornée par nature, une vingtaine de lignes (§ 1.5). */
    #[Route('/api/administration/reglages', name: 'core_admin_reglages', methods: ['GET'])]
    public function lister(): JsonResponse
    {
        return new JsonResponse(array_map(
            $this->reglages->normaliser(...),
            $this->reglages->tous(),
        ));
    }

    #[Route('/api/administration/reglages/{cle}', name: 'core_admin_reglage_modifier', methods: ['PATCH'])]
    public function modifier(string $cle, Request $requete, #[CurrentUser] User $administrateur): JsonResponse
    {
        // Aucune écriture sous identité empruntée (§ 10.4, règle 2) — les réglages sont
        // nommément cités par le contrat.
        $this->bascule->refuserSiActive();

        $corps = json_decode($requete->getContent(), true);

        // `array_key_exists` et non `isset` : `{"value": null}` est une valeur soumise,
        // refusée par le cast, et non une clé absente.
        if (!\is_array($corps) || !\array_key_exists('value', $corps)) {
            throw new ValidationException(['value' => 'La nouvelle valeur est requise.']);
        }

        return new JsonResponse($this->reglages->normaliser(
            $this->reglages->modifier($cle, $corps['value'], $administrateur),
        ));
    }
}
