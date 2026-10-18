<?php

declare(strict_types=1);

namespace App\Module\Noel\Controller;

use App\Module\Core\Entity\User;
use App\Module\Core\Exception\NotFoundException;
use App\Module\Core\Service\SessionPayloadBuilder;
use App\Module\Noel\Contract\PortPhotosPourNoel;
use App\Module\Noel\Repository\TirageNoelRepository;
use App\Module\Noel\Service\AnnuaireDuTirage;
use App\Module\Noel\Service\InscriptionAuSiteDeNoel;
use App\Module\Noel\Service\LectureNoel;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Le compte, vu du site de Noël.
 *
 * ```
 * POST /api/noel/inscription       public : créer son compte (en attente) et se connecter   201
 * GET  /api/noel/moi               mon prénom, mon nom, ma photo
 * GET  /api/noel/photos/{userId}   la photo d'un compte avec qui je partage un tirage
 * ```
 */
#[Route('/api/noel')]
final class CompteNoelController extends AbstractController
{
    public function __construct(
        private readonly AnnuaireDuTirage $annuaire,
    ) {
    }

    #[Route('/inscription', name: 'noel_inscription', methods: ['POST'])]
    public function inscription(
        Request $requete,
        InscriptionAuSiteDeNoel $inscription,
        LectureNoel $lecture,
        Security $securite,
        SessionPayloadBuilder $charge,
    ): JsonResponse {
        $compte = $inscription->inscrire($lecture->corps($requete), $requete);

        $securite->login($compte, 'json_login');

        return new JsonResponse($charge->construire($compte), Response::HTTP_CREATED);
    }

    #[Route('/moi', name: 'noel_moi', methods: ['GET'])]
    public function moi(#[CurrentUser] User $compte): JsonResponse
    {
        return new JsonResponse([
            'id' => $compte->getId(),
            'email' => $compte->getEmail(),
            ...$this->annuaire->nom($compte),
            'photoUrl' => $this->annuaire->photoUrl($compte),
        ]);
    }

    /**
     * Une photo ne se montre qu'à soi-même et aux comptes d'un même tirage — organisateur
     * compris. Pour tout autre, `404` : la route ne dit pas qui a une photo.
     */
    #[Route('/photos/{userId}', name: 'noel_photo', requirements: ['userId' => '\d+'], methods: ['GET'])]
    public function photo(
        int $userId,
        Request $requete,
        #[CurrentUser] User $lecteur,
        TirageNoelRepository $tirages,
        PortPhotosPourNoel $photos,
    ): Response {
        $cible = $lecteur->getId() === $userId ? $lecteur : null;

        foreach (null === $cible ? $tirages->duCompte($lecteur) : [] as $tirage) {
            $comptes = [$tirage->getCreateur(), ...array_map(static fn ($p): User => $p->getUser(), $tirage->getParticipations()->toArray())];
            foreach ($comptes as $compte) {
                if ($compte->getId() === $userId) {
                    $cible = $compte;
                    break 2;
                }
            }
        }

        $photo = null !== $cible ? $photos->vignette($userId, $this->annuaire->identite($cible)?->personId) : null;
        if (null === $photo) {
            throw new NotFoundException();
        }

        $reponse = new BinaryFileResponse($photo->chemin);
        $reponse->headers->set('Content-Type', $photo->mime);
        $reponse->setEtag($photo->empreinte);
        $reponse->setPrivate();
        $reponse->setMaxAge(3600);
        $reponse->isNotModified($requete);

        return $reponse;
    }
}
