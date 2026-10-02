<?php

declare(strict_types=1);

namespace App\Module\Evenement\Controller;

use App\Module\Core\Security\ReserveAuxMembres;
use App\Module\Core\Service\BasculeVoirComme;
use App\Module\Evenement\Entity\Evenement;
use App\Module\Evenement\Service\IdentiteDuLecteur;
use App\Module\Evenement\Service\LectureDesRequetes;
use App\Module\Evenement\Service\SerialiseurDEvenement;
use App\Module\Evenement\Service\ServiceDesEvenements;
use App\Module\Evenement\Service\ServiceDesLiens;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Les événements de famille — « Cousinade été 2027 ».
 *
 * ```
 * GET    /api/evenements                                   liste
 * POST   /api/evenements                                   créer             201
 * GET    /api/evenements/{id}                              détail (et la vue de l'invité)
 * PUT    /api/evenements/{id}                              modifier l'annonce
 * POST   /api/evenements/{id}/annulation                   annuler
 * POST   /api/evenements/{id}/vu                           l'annonce a été vue 204
 * PUT    /api/evenements/{id}/reponse                      répondre (présent / absent)
 * POST   /api/evenements/{id}/invites                      inviter           201
 * DELETE /api/evenements/{id}/invites/{personId}           retirer
 * PUT    /api/evenements/{id}/invites/{personId}/reponse   noter sa réponse à sa place
 * POST   /api/evenements/{id}/invites/{personId}/lien      lien personnel    201
 * DELETE /api/evenements/{id}/invites/{personId}/lien      révoquer          204
 * POST   /api/evenements/{id}/relance                      relancer
 * ```
 *
 * Toutes exigent un compte **actif** (`ReserveAuxMembres`), comme les tirages : un compte en
 * attente ne lit rien de l'arbre, et une liste d'invités en est un morceau.
 *
 * ## Une lecture qui écrit : `GET /api/evenements/{id}`
 *
 * Ouvrir la page de l'événement **est** voir l'annonce : la route pose la première vue de
 * l'invité qui la lit. C'est une écriture sous un `GET`, dite franchement — comme la page
 * « Questions » —, et elle est choisie : la vue doit se poser **sans geste**, quel que soit
 * le chemin qui mène à la page (la cloche, une notification push, un lien recopié), et un
 * second appel que l'écran oublierait ferait de chaque oubli un fantôme de plus. Elle est
 * idempotente, ne s'écrit qu'une fois, et ne s'écrit pas sous la bascule « voir comme ».
 *
 * `POST …/vu` existe pour l'**accueil**, qui montre l'annonce sur sa carte sans ouvrir la
 * page : la carte vue, l'annonce l'est aussi.
 *
 * Chaque écriture est refusée sous la bascule « voir comme un membre » ; chaque réponse est
 * l'événement relu, sérialisé pour le lecteur — sauf le lien personnel, dont la réponse est
 * la clé, rendue une seule fois. Ce que ce lien ouvre, sans session, vit dans
 * {@see LienDEvenementController}.
 */
#[Route('/api/evenements')]
#[ReserveAuxMembres]
final class EvenementController extends AbstractController
{
    public function __construct(
        private readonly IdentiteDuLecteur $identite,
        private readonly ServiceDesEvenements $service,
        private readonly SerialiseurDEvenement $serialiseur,
        private readonly LectureDesRequetes $lecture,
        private readonly BasculeVoirComme $bascule,
        private readonly ServiceDesLiens $liens,
    ) {
    }

    #[Route('', name: 'evenement_liste', methods: ['GET'])]
    public function liste(): JsonResponse
    {
        $lecteur = $this->identite->lecteur();
        $aujourdhui = new \DateTimeImmutable();

        return $this->charge([
            // `null` : le compte n'est rattaché à aucune personne — il peut organiser, pas
            // être invité. L'écran de création coche d'office cette personne.
            'moi' => $this->service->personneDuLecteur($lecteur),
            'evenements' => array_map(
                fn (Evenement $evenement): array => $this->serialiseur->resume($evenement, $lecteur, $aujourdhui),
                $this->service->visibles($lecteur),
            ),
        ]);
    }

    #[Route('', name: 'evenement_creer', methods: ['POST'])]
    public function creer(Request $requete): JsonResponse
    {
        $this->bascule->refuserSiActive();
        $lecteur = $this->identite->lecteur();
        $maintenant = new \DateTimeImmutable();

        $evenement = $this->service->creer(
            $lecteur,
            $this->lecture->annonce($requete, $maintenant),
            $this->lecture->personnes($requete, 'invites'),
            $maintenant,
        );

        return $this->detail($evenement, Response::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'evenement_detail', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function lire(int $id): JsonResponse
    {
        $lecteur = $this->identite->lecteur();
        $evenement = $this->service->trouver($id, $lecteur);
        $this->service->marquerVu($evenement, $lecteur, new \DateTimeImmutable());

        return $this->detail($evenement);
    }

    #[Route('/{id}', name: 'evenement_modifier', requirements: ['id' => '\d+'], methods: ['PUT'])]
    public function modifier(int $id, Request $requete): JsonResponse
    {
        $this->bascule->refuserSiActive();
        $lecteur = $this->identite->lecteur();
        $maintenant = new \DateTimeImmutable();

        return $this->detail($this->service->modifier(
            $this->service->trouver($id, $lecteur),
            $lecteur,
            $this->lecture->annonce($requete, $maintenant),
            $maintenant,
        ));
    }

    #[Route('/{id}/annulation', name: 'evenement_annuler', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function annuler(int $id): JsonResponse
    {
        $this->bascule->refuserSiActive();
        $lecteur = $this->identite->lecteur();

        return $this->detail($this->service->annuler($this->service->trouver($id, $lecteur), $lecteur, new \DateTimeImmutable()));
    }

    /**
     * Sans refus sous la bascule : la lecture y est permise, et le service n'y pose aucune
     * vue. Refuser ici ferait tomber en erreur l'accueil d'un administrateur qui « voit comme ».
     */
    #[Route('/{id}/vu', name: 'evenement_vu', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function vu(int $id): Response
    {
        $lecteur = $this->identite->lecteur();
        $this->service->marquerVu($this->service->trouver($id, $lecteur), $lecteur, new \DateTimeImmutable());

        return new Response(status: Response::HTTP_NO_CONTENT);
    }

    #[Route('/{id}/reponse', name: 'evenement_repondre', requirements: ['id' => '\d+'], methods: ['PUT'])]
    public function repondre(int $id, Request $requete): JsonResponse
    {
        $this->bascule->refuserSiActive();
        $lecteur = $this->identite->lecteur();

        return $this->detail($this->service->repondre(
            $this->service->trouver($id, $lecteur),
            $lecteur,
            $this->lecture->reponse($requete),
            new \DateTimeImmutable(),
        ));
    }

    #[Route('/{id}/invites', name: 'evenement_inviter', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function inviter(int $id, Request $requete): JsonResponse
    {
        $this->bascule->refuserSiActive();
        $lecteur = $this->identite->lecteur();

        return $this->detail($this->service->inviter(
            $this->service->trouver($id, $lecteur),
            $lecteur,
            $this->lecture->personnes($requete, 'personIds'),
            new \DateTimeImmutable(),
        ), Response::HTTP_CREATED);
    }

    #[Route(
        '/{id}/invites/{personId}',
        name: 'evenement_retirer',
        requirements: ['id' => '\d+', 'personId' => '\d+'],
        methods: ['DELETE'],
    )]
    public function retirer(int $id, int $personId): JsonResponse
    {
        $this->bascule->refuserSiActive();
        $lecteur = $this->identite->lecteur();

        return $this->detail($this->service->retirer(
            $this->service->trouver($id, $lecteur),
            $lecteur,
            $personId,
            new \DateTimeImmutable(),
        ));
    }

    /**
     * L'organisateur (ou un administrateur) **note la réponse d'un invité à sa place** — une
     * réponse donnée de vive voix. Tracée comme telle, elle ne compte pas comme une vue.
     */
    #[Route(
        '/{id}/invites/{personId}/reponse',
        name: 'evenement_noter_reponse',
        requirements: ['id' => '\d+', 'personId' => '\d+'],
        methods: ['PUT'],
    )]
    public function noterLaReponse(int $id, int $personId, Request $requete): JsonResponse
    {
        $this->bascule->refuserSiActive();
        $lecteur = $this->identite->lecteur();

        return $this->detail($this->service->noterLaReponse(
            $this->service->trouver($id, $lecteur),
            $lecteur,
            $personId,
            $this->lecture->reponse($requete),
            new \DateTimeImmutable(),
        ));
    }

    /**
     * Crée le lien personnel de l'invité, ou le **régénère** : l'ancienne clé cesse de valoir.
     * La clé en clair n'est rendue qu'ici — il n'existe aucune route pour la relire.
     */
    #[Route(
        '/{id}/invites/{personId}/lien',
        name: 'evenement_lien_creer',
        requirements: ['id' => '\d+', 'personId' => '\d+'],
        methods: ['POST'],
    )]
    public function creerLien(int $id, int $personId): JsonResponse
    {
        $this->bascule->refuserSiActive();
        $lecteur = $this->identite->lecteur();
        $cle = $this->liens->creer($this->service->trouver($id, $lecteur), $lecteur, $personId, new \DateTimeImmutable());

        // Le chemin **du front**, relatif : l'API ne connaît pas l'origine sous laquelle
        // l'application est servie, et le front la préfixe au moment de copier.
        return $this->charge(['cle' => $cle, 'url' => '/e/'.$cle], Response::HTTP_CREATED);
    }

    #[Route(
        '/{id}/invites/{personId}/lien',
        name: 'evenement_lien_revoquer',
        requirements: ['id' => '\d+', 'personId' => '\d+'],
        methods: ['DELETE'],
    )]
    public function revoquerLien(int $id, int $personId): Response
    {
        $this->bascule->refuserSiActive();
        $lecteur = $this->identite->lecteur();
        $this->liens->revoquer($this->service->trouver($id, $lecteur), $lecteur, $personId);

        return new Response(status: Response::HTTP_NO_CONTENT);
    }

    #[Route('/{id}/relance', name: 'evenement_relancer', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function relancer(int $id): JsonResponse
    {
        $this->bascule->refuserSiActive();
        $lecteur = $this->identite->lecteur();
        $evenement = $this->service->trouver($id, $lecteur);
        $this->service->relancer($evenement, $lecteur, new \DateTimeImmutable());

        return $this->detail($evenement);
    }

    private function detail(Evenement $evenement, int $statut = Response::HTTP_OK): JsonResponse
    {
        return $this->charge(
            $this->serialiseur->detail($evenement, $this->identite->lecteur(), new \DateTimeImmutable()),
            $statut,
        );
    }

    /** @param array<string, mixed> $charge */
    private function charge(array $charge, int $statut = Response::HTTP_OK): JsonResponse
    {
        $reponse = new JsonResponse($charge, $statut);
        // Le suivi est propre à l'organisateur, la réponse à chaque invité : rien ne se met
        // en cache, nulle part.
        $reponse->headers->set('Cache-Control', 'private, no-store');
        $reponse->headers->set('Vary', 'Cookie');

        return $reponse;
    }
}
