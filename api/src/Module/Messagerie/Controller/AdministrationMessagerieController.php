<?php

declare(strict_types=1);

namespace App\Module\Messagerie\Controller;

use App\Module\Core\Service\BasculeVoirComme;
use App\Module\Messagerie\Entity\Fil;
use App\Module\Messagerie\Enum\StatutDuFil;
use App\Module\Messagerie\Exception\ErreurDeMessagerie;
use App\Module\Messagerie\Service\IdentiteDuLecteur;
use App\Module\Messagerie\Service\LectureDesRequetes;
use App\Module\Messagerie\Service\SerialiseurDeFil;
use App\Module\Messagerie\Service\ServiceDeLaMessagerie;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * La messagerie, côté administration : tous les fils, et la réponse.
 *
 * ```
 * GET  /api/administration/messagerie/fils?statut=      tous les fils (résumés)
 * GET  /api/administration/messagerie/fils/{id}         le fil — pose l'accusé de lecture
 * POST /api/administration/messagerie/fils/{id}/messages répondre                      201
 * PUT  /api/administration/messagerie/fils/{id}/statut   régler, ou rouvrir
 * ```
 *
 * `ROLE_ADMIN` (par `access_control` sur `/api/administration`, et redit ici). Sous la
 * bascule « voir comme », tout est refusé, la lecture comprise : un fil n'a pas de
 * version « vue par un membre », et la lire poserait un accusé au nom de l'administration.
 */
#[Route('/api/administration/messagerie/fils')]
#[IsGranted('ROLE_ADMIN')]
final class AdministrationMessagerieController extends AbstractController
{
    public function __construct(
        private readonly IdentiteDuLecteur $identite,
        private readonly ServiceDeLaMessagerie $service,
        private readonly SerialiseurDeFil $serialiseur,
        private readonly LectureDesRequetes $lecture,
        private readonly BasculeVoirComme $bascule,
    ) {
    }

    #[Route('', name: 'administration_messagerie_liste', methods: ['GET'])]
    public function liste(Request $requete): JsonResponse
    {
        $this->bascule->refuserSiActive();

        $statut = $requete->query->get('statut');
        if (null !== $statut && null === StatutDuFil::tryFrom((string) $statut)) {
            throw ErreurDeMessagerie::donneesInvalides(['statut' => 'ouvert ou clos est attendu.']);
        }

        $fils = $this->service->tousLesFils();
        if (null !== $statut) {
            $fils = array_values(array_filter($fils, static fn (Fil $f): bool => $f->getStatut()->value === $statut));
        }

        $elements = array_map(fn (Fil $f): array => $this->serialiseur->resume($f, true), $fils);

        return $this->charge([
            'elements' => $elements,
            'total' => \count($elements),
            'nonLus' => array_sum(array_column($elements, 'nonLus')),
        ]);
    }

    #[Route('/{id}', name: 'administration_messagerie_lire', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function lire(int $id): JsonResponse
    {
        $this->bascule->refuserSiActive();
        $fil = $this->service->filPourLAdministration($id);
        $this->service->marquerVuParLAdministration($fil, $this->identite->lecteur());

        return $this->detail($fil);
    }

    #[Route('/{id}/messages', name: 'administration_messagerie_repondre', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function repondre(int $id, Request $requete): JsonResponse
    {
        $this->bascule->refuserSiActive();
        $fil = $this->service->filPourLAdministration($id);

        return $this->detail(
            $this->service->repondre($fil, $this->identite->lecteur(), $this->lecture->message($requete)),
            Response::HTTP_CREATED,
        );
    }

    #[Route('/{id}/statut', name: 'administration_messagerie_statut', requirements: ['id' => '\d+'], methods: ['PUT'])]
    public function statut(int $id, Request $requete): JsonResponse
    {
        $this->bascule->refuserSiActive();
        $fil = $this->service->filPourLAdministration($id);

        return $this->detail($this->service->changerStatut($fil, $this->lecture->statut($requete)));
    }

    private function detail(Fil $fil, int $statut = Response::HTTP_OK): JsonResponse
    {
        return $this->charge($this->serialiseur->detail($fil, $this->identite->lecteur(), true), $statut);
    }

    /** @param array<string, mixed> $charge */
    private function charge(array $charge, int $statut = Response::HTTP_OK): JsonResponse
    {
        $reponse = new JsonResponse($charge, $statut);
        $reponse->headers->set('Cache-Control', 'private, no-store');
        $reponse->headers->set('Vary', 'Cookie');

        return $reponse;
    }
}
