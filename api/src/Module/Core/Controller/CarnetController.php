<?php

declare(strict_types=1);

namespace App\Module\Core\Controller;

use App\Module\Core\Entity\User;
use App\Module\Core\Exception\ValidationException;
use App\Module\Core\Service\BasculeVoirComme;
use App\Module\Core\Service\CarnetDeNotes;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Le carnet de notes de l'administrateur — personnel, voir {@see CarnetDeNotes}.
 *
 * ```
 * GET    /api/administration/notes        mes notes, épinglées d'abord        200
 * POST   /api/administration/notes        créer                               201 · 422
 * PUT    /api/administration/notes/{id}   remplacer titre, texte, épingle     200 · 404 · 422
 * DELETE /api/administration/notes/{id}   supprimer                           204 · 404
 * ```
 *
 * Chaque écriture est refusée sous la bascule « voir comme un membre », comme ailleurs
 * dans l'administration.
 */
#[Route('/api/administration/notes')]
#[IsGranted('ROLE_ADMIN')]
final class CarnetController extends AbstractController
{
    public function __construct(
        private readonly CarnetDeNotes $carnet,
        private readonly BasculeVoirComme $bascule,
    ) {
    }

    /** Tableau nu : un carnet personnel reste de taille humaine. */
    #[Route('', name: 'core_admin_notes', methods: ['GET'])]
    public function lister(#[CurrentUser] User $administrateur): JsonResponse
    {
        return new JsonResponse(array_map(
            $this->carnet->normaliser(...),
            $this->carnet->lister($administrateur),
        ));
    }

    #[Route('', name: 'core_admin_note_creer', methods: ['POST'])]
    public function creer(Request $requete, #[CurrentUser] User $administrateur): JsonResponse
    {
        $this->bascule->refuserSiActive();

        return new JsonResponse(
            $this->carnet->normaliser($this->carnet->creer($administrateur, $this->corps($requete))),
            Response::HTTP_CREATED,
        );
    }

    #[Route('/{id}', name: 'core_admin_note_modifier', requirements: ['id' => '\d+'], methods: ['PUT'])]
    public function modifier(int $id, Request $requete, #[CurrentUser] User $administrateur): JsonResponse
    {
        $this->bascule->refuserSiActive();

        return new JsonResponse(
            $this->carnet->normaliser($this->carnet->modifier($administrateur, $id, $this->corps($requete))),
        );
    }

    #[Route('/{id}', name: 'core_admin_note_supprimer', requirements: ['id' => '\d+'], methods: ['DELETE'])]
    public function supprimer(int $id, #[CurrentUser] User $administrateur): Response
    {
        $this->bascule->refuserSiActive();
        $this->carnet->supprimer($administrateur, $id);

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    /** @return array<mixed> */
    private function corps(Request $requete): array
    {
        $corps = json_decode($requete->getContent(), true);

        if (!\is_array($corps)) {
            throw new ValidationException(['corps' => 'Un objet JSON est attendu.']);
        }

        return $corps;
    }
}
