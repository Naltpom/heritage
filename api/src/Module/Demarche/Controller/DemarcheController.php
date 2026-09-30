<?php

declare(strict_types=1);

namespace App\Module\Demarche\Controller;

use App\Module\Core\Security\RegimeVoter;
use App\Module\Core\Service\BasculeVoirComme;
use App\Module\Demarche\Entity\Demarche;
use App\Module\Demarche\Exception\ErreurDeDemarche;
use App\Module\Demarche\Service\IdentiteDuLecteur;
use App\Module\Demarche\Service\LectureDesRequetes;
use App\Module\Demarche\Service\SerialiseurDeDemarche;
use App\Module\Demarche\Service\ServiceDesDemarches;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Les actes demandés — à une mairie, à des archives — et leur suivi.
 *
 * ```
 * GET    /api/demarches?personId=&enAttente=          liste (résumés)
 * POST   /api/demarches                               créer, premier message compris   201
 * GET    /api/demarches/{id}                          détail, messages en entier
 * PUT    /api/demarches/{id}                          corriger la description
 * PUT    /api/demarches/{id}/suivi                    statut et résultat
 * DELETE /api/demarches/{id}                          supprimer (auteur, administrateur) 204
 * POST   /api/demarches/{id}/messages                 ajouter un message               201
 * DELETE /api/demarches/{id}/messages/{messageId}     retirer un message (qui l'a saisi, administrateur)
 * ```
 *
 * Toutes exigent un compte **actif** (`MEMBRE`). Chaque écriture est refusée sous la
 * bascule « voir comme un membre ». Chaque réponse d'écriture est la démarche relue.
 */
#[Route('/api/demarches')]
#[IsGranted(RegimeVoter::MEMBRE)]
final class DemarcheController extends AbstractController
{
    public function __construct(
        private readonly IdentiteDuLecteur $identite,
        private readonly ServiceDesDemarches $service,
        private readonly SerialiseurDeDemarche $serialiseur,
        private readonly LectureDesRequetes $lecture,
        private readonly BasculeVoirComme $bascule,
    ) {
    }

    #[Route('', name: 'demarche_liste', methods: ['GET'])]
    public function liste(Request $requete): JsonResponse
    {
        $lecteur = $this->identite->lecteur();
        $aujourdhui = new \DateTimeImmutable();

        $personId = $requete->query->get('personId');
        if (null !== $personId && 1 !== preg_match('/^[1-9]\d{0,9}$/', (string) $personId)) {
            throw ErreurDeDemarche::donneesInvalides(['personId' => 'Un identifiant de personne est attendu.']);
        }

        $enAttente = $requete->query->get('enAttente');
        if (null !== $enAttente && !\in_array($enAttente, ['0', '1'], true)) {
            throw ErreurDeDemarche::donneesInvalides(['enAttente' => '0 ou 1 est attendu.']);
        }

        $demarches = $this->service->visibles(
            null === $personId ? null : (int) $personId,
            null === $enAttente ? null : '1' === $enAttente,
        );

        return $this->charge([
            'elements' => array_map(
                fn (Demarche $d): array => $this->serialiseur->resume($d, $lecteur, $aujourdhui),
                $demarches,
            ),
            'total' => \count($demarches),
        ]);
    }

    #[Route('', name: 'demarche_creer', methods: ['POST'])]
    public function creer(Request $requete): JsonResponse
    {
        $this->bascule->refuserSiActive();
        [$description, $premier] = $this->lecture->creation($requete);

        return $this->detail(
            $this->service->creer($this->identite->lecteur(), $description, $premier),
            Response::HTTP_CREATED,
        );
    }

    #[Route('/{id}', name: 'demarche_detail', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function lire(int $id): JsonResponse
    {
        return $this->detail($this->service->trouver($id));
    }

    #[Route('/{id}', name: 'demarche_decrire', requirements: ['id' => '\d+'], methods: ['PUT'])]
    public function decrire(int $id, Request $requete): JsonResponse
    {
        $this->bascule->refuserSiActive();
        $demarche = $this->service->trouver($id);

        return $this->detail($this->service->decrire($demarche, $this->lecture->description($requete)));
    }

    #[Route('/{id}/suivi', name: 'demarche_suivre', requirements: ['id' => '\d+'], methods: ['PUT'])]
    public function suivre(int $id, Request $requete): JsonResponse
    {
        $this->bascule->refuserSiActive();
        $demarche = $this->service->trouver($id);

        return $this->detail($this->service->suivre($demarche, $this->lecture->suivi($requete)));
    }

    #[Route('/{id}', name: 'demarche_supprimer', requirements: ['id' => '\d+'], methods: ['DELETE'])]
    public function supprimer(int $id): Response
    {
        $this->bascule->refuserSiActive();
        $this->service->supprimer($this->service->trouver($id), $this->identite->lecteur());

        return new Response(status: Response::HTTP_NO_CONTENT);
    }

    #[Route('/{id}/messages', name: 'demarche_message_ajouter', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function ajouterMessage(int $id, Request $requete): JsonResponse
    {
        $this->bascule->refuserSiActive();
        $demarche = $this->service->trouver($id);

        return $this->detail(
            $this->service->ajouterMessage($demarche, $this->lecture->message($requete), $this->identite->lecteur()),
            Response::HTTP_CREATED,
        );
    }

    #[Route(
        '/{id}/messages/{messageId}',
        name: 'demarche_message_retirer',
        requirements: ['id' => '\d+', 'messageId' => '\d+'],
        methods: ['DELETE'],
    )]
    public function retirerMessage(int $id, int $messageId): JsonResponse
    {
        $this->bascule->refuserSiActive();
        $demarche = $this->service->trouver($id);

        return $this->detail($this->service->retirerMessage($demarche, $messageId, $this->identite->lecteur()));
    }

    private function detail(Demarche $demarche, int $statut = Response::HTTP_OK): JsonResponse
    {
        return $this->charge(
            $this->serialiseur->detail($demarche, $this->identite->lecteur(), new \DateTimeImmutable()),
            $statut,
        );
    }

    /** @param array<string, mixed> $charge */
    private function charge(array $charge, int $statut = Response::HTTP_OK): JsonResponse
    {
        $reponse = new JsonResponse($charge, $statut);
        // Une démarche nomme des personnes que le cercle peut masquer : sa réponse dépend du
        // lecteur, rien ne se met en cache partagé.
        $reponse->headers->set('Cache-Control', 'private, no-store');
        $reponse->headers->set('Vary', 'Cookie');

        return $reponse;
    }
}
