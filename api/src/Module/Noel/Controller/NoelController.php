<?php

declare(strict_types=1);

namespace App\Module\Noel\Controller;

use App\Module\Core\Entity\User;
use App\Module\Core\Exception\NotFoundException;
use App\Module\Core\Security\ReserveAuxMembres;
use App\Module\Core\Service\Incarnation;
use App\Module\Noel\Entity\TirageNoel;
use App\Module\Noel\Service\LectureNoel;
use App\Module\Noel\Service\SerialiseurNoel;
use App\Module\Noel\Service\ServiceDesTiragesNoel;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Les tirages de Noël du site noel2026.
 *
 * ```
 * GET  /api/noel/tirages/{code}                  public ; complet avec une session
 * POST /api/noel/tirages                         créer                  201
 * GET  /api/noel/mes-tirages                     les miens
 * GET  /api/noel/tirages                         tous (membres d'heritage seulement)
 * PUT  /api/noel/tirages/{code}                  régler (organisateur, tant qu'ouvert)
 * PUT  /api/noel/tirages/{code}/participation    répondre (tant qu'ouvert)
 * POST /api/noel/tirages/{code}/lancer           tirer en avance (organisateur) — irréversible
 * GET  /api/noel/tirages/{code}/mon-attribution  à qui j'offre
 * GET  /api/noel/tirages/{code}/qui-ma-offert    qui m'a offert (si le vote l'a voulu)
 * ```
 *
 * Tout compte connecté, **en attente compris** : c'est le public du site. Il n'existe
 * aucune route d'annulation d'un tirage.
 *
 * Une exception, `GET /api/noel/tirages` : la liste de **tous** les tirages, qu'heritage
 * montre dans « Mes événements » depuis le retrait de son module `Tirage` (ADR 0009,
 * remplacé). Elle porte {@see ReserveAuxMembres} — le même contrôle que l'ancien
 * contrôleur des tirages et que celui des événements — parce qu'un compte en attente,
 * qui s'est peut-être inscrit sur noel2026 sous un faux nom, n'a pas à découvrir les
 * tirages auxquels on ne l'a pas convié : il reçoit `403 regime_en_attente`. Sans
 * session, `access_control` rend `401` — la règle publique ne s'ouvre qu'à
 * `/tirages/{code}`.
 */
#[Route('/api/noel')]
final class NoelController extends AbstractController
{
    public function __construct(
        private readonly ServiceDesTiragesNoel $service,
        private readonly SerialiseurNoel $serialiseur,
        private readonly LectureNoel $lecture,
        private readonly Incarnation $incarnation,
    ) {
    }

    #[Route('/tirages/{code}', name: 'noel_tirage', methods: ['GET'])]
    public function tirage(string $code): JsonResponse
    {
        $lecteur = $this->getUser();

        return new JsonResponse($this->serialiseur->tirage($this->service->parCode($code), $lecteur instanceof User ? $lecteur : null));
    }

    #[Route('/tirages', name: 'noel_creer', methods: ['POST'])]
    public function creer(Request $requete, #[CurrentUser] User $compte): JsonResponse
    {
        $this->incarnation->refuserSiActive();
        $tirage = $this->service->creer($compte, $this->lecture->reglages($this->lecture->corps($requete)));

        return new JsonResponse($this->serialiseur->tirage($tirage, $compte), Response::HTTP_CREATED);
    }

    #[Route('/mes-tirages', name: 'noel_mes_tirages', methods: ['GET'])]
    public function mesTirages(#[CurrentUser] User $compte): JsonResponse
    {
        return new JsonResponse(array_map(
            fn (TirageNoel $t): array => $this->serialiseur->resume($t, $compte),
            $this->service->duCompte($compte),
        ));
    }

    #[Route('/tirages', name: 'noel_tous_les_tirages', methods: ['GET'])]
    #[ReserveAuxMembres]
    public function tousLesTirages(#[CurrentUser] User $compte): JsonResponse
    {
        return new JsonResponse(array_map(
            fn (TirageNoel $t): array => $this->serialiseur->resume($t, $compte),
            $this->service->tous(),
        ));
    }

    #[Route('/tirages/{code}', name: 'noel_modifier', methods: ['PUT'])]
    public function modifier(string $code, Request $requete, #[CurrentUser] User $compte): JsonResponse
    {
        $this->incarnation->refuserSiActive();
        $tirage = $this->service->modifier($this->service->parCode($code), $compte, $this->lecture->reglages($this->lecture->corps($requete)));

        return new JsonResponse($this->serialiseur->tirage($tirage, $compte));
    }

    #[Route('/tirages/{code}/participation', name: 'noel_participation', methods: ['PUT'])]
    public function participation(string $code, Request $requete, #[CurrentUser] User $compte): JsonResponse
    {
        $this->incarnation->refuserSiActive();
        $tirage = $this->service->repondre($this->service->parCode($code), $compte, $this->lecture->reponse($this->lecture->corps($requete)));

        return new JsonResponse($this->serialiseur->tirage($tirage, $compte));
    }

    #[Route('/tirages/{code}/lancer', name: 'noel_lancer', methods: ['POST'])]
    public function lancer(string $code, #[CurrentUser] User $compte): JsonResponse
    {
        $this->incarnation->refuserSiActive();
        $tirage = $this->service->lancer($this->service->parCode($code), $compte);

        return new JsonResponse($this->serialiseur->tirage($tirage, $compte));
    }

    #[Route('/tirages/{code}/mon-attribution', name: 'noel_mon_attribution', methods: ['GET'])]
    public function monAttribution(string $code, #[CurrentUser] User $compte): JsonResponse
    {
        $tirage = $this->service->parCode($code);
        if ($tirage->estOuvert()) {
            throw new NotFoundException('Le tirage n’a pas encore eu lieu.');
        }

        return new JsonResponse($this->serialiseur->personne(
            $tirage,
            $this->service->consulterMonAttribution($tirage, $compte),
            $compte,
            $tirage->getDateEvenement(),
        ));
    }

    #[Route('/tirages/{code}/qui-ma-offert', name: 'noel_qui_ma_offert', methods: ['GET'])]
    public function quiMAOffert(string $code, #[CurrentUser] User $compte): JsonResponse
    {
        $tirage = $this->service->parCode($code);

        return new JsonResponse($this->serialiseur->personne($tirage, $this->service->donneurDe($tirage, $compte), $compte));
    }
}
