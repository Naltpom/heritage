<?php

declare(strict_types=1);

namespace App\Module\Tirage\Controller;

use App\Module\Core\Security\RegimeVoter;
use App\Module\Core\Service\BasculeVoirComme;
use App\Module\Tirage\Entity\Tirage;
use App\Module\Tirage\Service\IdentiteDuLecteur;
use App\Module\Tirage\Service\LectureDesRequetes;
use App\Module\Tirage\Service\SerialiseurDeTirage;
use App\Module\Tirage\Service\ServiceDesTirages;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Les tirages au sort récurrents — ADR 0009.
 *
 * ```
 * GET    /api/tirages                                           liste
 * POST   /api/tirages                                           créer           201
 * GET    /api/tirages/{id}                                      détail
 * PUT    /api/tirages/{id}                                      régler
 * DELETE /api/tirages/{id}                                      supprimer       204
 * POST   /api/tirages/{id}/participants                         ajouter         201
 * DELETE /api/tirages/{id}/participants/{personId}              retirer
 * POST   /api/tirages/{id}/rejoindre                            se joindre      201
 * POST   /api/tirages/{id}/editions                             ouvrir l'année  201
 * PUT    /api/tirages/{id}/editions/{annee}/presences/{personId}
 * POST   /api/tirages/{id}/editions/{annee}/tirage              tirer
 * DELETE /api/tirages/{id}/editions/{annee}/tirage              annuler
 * ```
 *
 * Toutes exigent un compte **actif** (`MEMBRE`) : un tirage se fait entre membres de la
 * famille, et un compte en attente n'a pas encore de personne à qui tirer un cadeau.
 *
 * Chaque écriture est refusée sous la bascule « voir comme un membre » : l'administrateur
 * écrirait en son nom pendant que l'écran montre quelqu'un d'autre. Les lectures, elles,
 * se font **toujours** sous le compte réel — voir {@see IdentiteDuLecteur}.
 *
 * Chaque réponse est le tirage relu, sérialisé pour le lecteur : le front n'a jamais à
 * recoller une réponse partielle.
 */
#[Route('/api/tirages')]
#[IsGranted(RegimeVoter::MEMBRE)]
final class TirageController extends AbstractController
{
    public function __construct(
        private readonly IdentiteDuLecteur $identite,
        private readonly ServiceDesTirages $service,
        private readonly SerialiseurDeTirage $serialiseur,
        private readonly LectureDesRequetes $lecture,
        private readonly BasculeVoirComme $bascule,
    ) {
    }

    #[Route('', name: 'tirage_liste', methods: ['GET'])]
    public function liste(): JsonResponse
    {
        $lecteur = $this->identite->lecteur();
        $aujourdhui = new \DateTimeImmutable();

        return $this->charge([
            // `null` : le compte n'est rattaché à aucune personne, il ne peut rien rejoindre.
            'moi' => $this->service->personneDuLecteur($lecteur),
            'tirages' => array_map(
                fn (Tirage $tirage): array => $this->serialiseur->resume($tirage, $lecteur, $aujourdhui),
                $this->service->visibles($lecteur),
            ),
        ]);
    }

    #[Route('', name: 'tirage_creer', methods: ['POST'])]
    public function creer(Request $requete): JsonResponse
    {
        $this->bascule->refuserSiActive();
        $lecteur = $this->identite->lecteur();

        $tirage = $this->service->creer(
            $lecteur,
            $this->lecture->regles($requete),
            $this->lecture->participantsInitiaux($requete),
        );

        return $this->detail($tirage, Response::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'tirage_detail', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function lire(int $id): JsonResponse
    {
        return $this->detail($this->service->trouver($id, $this->identite->lecteur()));
    }

    #[Route('/{id}', name: 'tirage_regler', requirements: ['id' => '\d+'], methods: ['PUT'])]
    public function regler(int $id, Request $requete): JsonResponse
    {
        $this->bascule->refuserSiActive();
        $lecteur = $this->identite->lecteur();

        return $this->detail($this->service->modifier(
            $this->service->trouver($id, $lecteur),
            $lecteur,
            $this->lecture->regles($requete),
        ));
    }

    #[Route('/{id}', name: 'tirage_supprimer', requirements: ['id' => '\d+'], methods: ['DELETE'])]
    public function supprimer(int $id): Response
    {
        $this->bascule->refuserSiActive();
        $lecteur = $this->identite->lecteur();

        $this->service->supprimer($this->service->trouver($id, $lecteur), $lecteur);

        return new Response(status: Response::HTTP_NO_CONTENT);
    }

    #[Route('/{id}/participants', name: 'tirage_participant_ajouter', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function ajouterParticipant(int $id, Request $requete): JsonResponse
    {
        $this->bascule->refuserSiActive();
        $lecteur = $this->identite->lecteur();
        $corps = $this->lecture->participant($requete);

        return $this->detail($this->service->ajouterParticipant(
            $this->service->trouver($id, $lecteur),
            $lecteur,
            $corps['personId'],
            $corps['referentPersonId'],
        ), Response::HTTP_CREATED);
    }

    #[Route(
        '/{id}/participants/{personId}',
        name: 'tirage_participant_retirer',
        requirements: ['id' => '\d+', 'personId' => '\d+'],
        methods: ['DELETE'],
    )]
    public function retirerParticipant(int $id, int $personId): JsonResponse
    {
        $this->bascule->refuserSiActive();
        $lecteur = $this->identite->lecteur();

        return $this->detail($this->service->retirerParticipant(
            $this->service->trouver($id, $lecteur),
            $lecteur,
            $personId,
            new \DateTimeImmutable(),
        ));
    }

    #[Route('/{id}/rejoindre', name: 'tirage_rejoindre', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function rejoindre(int $id): JsonResponse
    {
        $this->bascule->refuserSiActive();
        $lecteur = $this->identite->lecteur();

        return $this->detail(
            $this->service->rejoindre($this->service->trouver($id, $lecteur), $lecteur),
            Response::HTTP_CREATED,
        );
    }

    #[Route('/{id}/editions', name: 'tirage_edition_ouvrir', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function ouvrirEdition(int $id, Request $requete): JsonResponse
    {
        $this->bascule->refuserSiActive();
        $lecteur = $this->identite->lecteur();
        $corps = $this->lecture->edition($requete);

        return $this->detail($this->service->ouvrirEdition(
            $this->service->trouver($id, $lecteur),
            $lecteur,
            $corps['annee'],
            $corps['date'],
        ), Response::HTTP_CREATED);
    }

    #[Route(
        '/{id}/editions/{annee}/presences/{personId}',
        name: 'tirage_presence',
        requirements: ['id' => '\d+', 'annee' => '\d{4}', 'personId' => '\d+'],
        methods: ['PUT'],
    )]
    public function presence(int $id, int $annee, int $personId, Request $requete): JsonResponse
    {
        $this->bascule->refuserSiActive();
        $lecteur = $this->identite->lecteur();

        return $this->detail($this->service->definirPresence(
            $this->service->trouver($id, $lecteur),
            $lecteur,
            $annee,
            $personId,
            $this->lecture->presence($requete),
        ));
    }

    #[Route(
        '/{id}/editions/{annee}/tirage',
        name: 'tirage_tirer',
        requirements: ['id' => '\d+', 'annee' => '\d{4}'],
        methods: ['POST'],
    )]
    public function tirer(int $id, int $annee): JsonResponse
    {
        $this->bascule->refuserSiActive();
        $lecteur = $this->identite->lecteur();

        return $this->detail($this->service->tirer($this->service->trouver($id, $lecteur), $lecteur, $annee));
    }

    #[Route(
        '/{id}/editions/{annee}/tirage',
        name: 'tirage_annuler',
        requirements: ['id' => '\d+', 'annee' => '\d{4}'],
        methods: ['DELETE'],
    )]
    public function annuler(int $id, int $annee): JsonResponse
    {
        $this->bascule->refuserSiActive();
        $lecteur = $this->identite->lecteur();

        return $this->detail($this->service->annulerLeTirage($this->service->trouver($id, $lecteur), $lecteur, $annee));
    }

    private function detail(Tirage $tirage, int $statut = Response::HTTP_OK): JsonResponse
    {
        return $this->charge(
            $this->serialiseur->detail($tirage, $this->identite->lecteur(), new \DateTimeImmutable()),
            $statut,
        );
    }

    /** @param array<string, mixed> $charge */
    private function charge(array $charge, int $statut = Response::HTTP_OK): JsonResponse
    {
        $reponse = new JsonResponse($charge, $statut);
        // Une attribution est un secret par compte : rien ne se met en cache, nulle part.
        $reponse->headers->set('Cache-Control', 'private, no-store');
        $reponse->headers->set('Vary', 'Cookie');

        return $reponse;
    }
}
