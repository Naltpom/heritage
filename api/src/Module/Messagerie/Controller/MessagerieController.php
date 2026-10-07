<?php

declare(strict_types=1);

namespace App\Module\Messagerie\Controller;

use App\Module\Core\Service\BasculeVoirComme;
use App\Module\Messagerie\Entity\Fil;
use App\Module\Messagerie\Service\IdentiteDuLecteur;
use App\Module\Messagerie\Service\LectureDesRequetes;
use App\Module\Messagerie\Service\SerialiseurDeFil;
use App\Module\Messagerie\Service\ServiceDeLaMessagerie;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * « Écrire à l'administration » — le côté du membre : une question, une suggestion, un bug.
 *
 * ```
 * GET  /api/messagerie/fils                   mes fils, le plus récent d'abord (résumés)
 * POST /api/messagerie/fils                   ouvrir un fil, premier message compris    201
 * GET  /api/messagerie/fils/{id}              le fil en entier — pose l'accusé de lecture
 * POST /api/messagerie/fils/{id}/messages     écrire dans le fil (le rouvre s'il est réglé) 201
 * ```
 *
 * **Toute session**, compte en attente compris (`ROLE_USER`, par `access_control`) : c'est
 * justement quand l'inscription traîne qu'on a une question à poser, et rien ici ne lit
 * l'arbre. Chaque écriture est refusée sous la bascule « voir comme un membre ».
 */
#[Route('/api/messagerie/fils')]
final class MessagerieController extends AbstractController
{
    public function __construct(
        private readonly IdentiteDuLecteur $identite,
        private readonly ServiceDeLaMessagerie $service,
        private readonly SerialiseurDeFil $serialiseur,
        private readonly LectureDesRequetes $lecture,
        private readonly BasculeVoirComme $bascule,
    ) {
    }

    #[Route('', name: 'messagerie_liste', methods: ['GET'])]
    public function liste(): JsonResponse
    {
        $fils = $this->service->filsDuMembre($this->identite->lecteur());

        return $this->charge([
            'elements' => array_map(fn (Fil $f): array => $this->serialiseur->resume($f, false), $fils),
            'total' => \count($fils),
        ]);
    }

    #[Route('', name: 'messagerie_ouvrir', methods: ['POST'])]
    public function ouvrir(Request $requete): JsonResponse
    {
        $this->bascule->refuserSiActive();
        [$nature, $sujet, $texte] = $this->lecture->ouverture($requete);

        return $this->detail(
            $this->service->ouvrir($this->identite->lecteur(), $nature, $sujet, $texte),
            Response::HTTP_CREATED,
        );
    }

    #[Route('/{id}', name: 'messagerie_lire', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function lire(int $id): JsonResponse
    {
        $lecteur = $this->identite->lecteur();
        $fil = $this->service->filDuMembre($id, $lecteur);

        if (!$lecteur->sousBascule) {
            $this->service->marquerVuParLeMembre($fil);
        }

        return $this->detail($fil);
    }

    #[Route('/{id}/messages', name: 'messagerie_ecrire', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function ecrire(int $id, Request $requete): JsonResponse
    {
        $this->bascule->refuserSiActive();
        $lecteur = $this->identite->lecteur();
        $fil = $this->service->filDuMembre($id, $lecteur);

        return $this->detail(
            $this->service->ecrire($fil, $lecteur, $this->lecture->message($requete)),
            Response::HTTP_CREATED,
        );
    }

    private function detail(Fil $fil, int $statut = Response::HTTP_OK): JsonResponse
    {
        return $this->charge($this->serialiseur->detail($fil, $this->identite->lecteur(), false), $statut);
    }

    /** @param array<string, mixed> $charge */
    private function charge(array $charge, int $statut = Response::HTTP_OK): JsonResponse
    {
        $reponse = new JsonResponse($charge, $statut);
        // La conversation d'un compte : rien ne se met en cache partagé.
        $reponse->headers->set('Cache-Control', 'private, no-store');
        $reponse->headers->set('Vary', 'Cookie');

        return $reponse;
    }
}
