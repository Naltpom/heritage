<?php

declare(strict_types=1);

namespace App\Module\Evenement\Controller;

use App\Module\Evenement\Entity\Invitation;
use App\Module\Evenement\Exception\ErreurDEvenement;
use App\Module\Evenement\Service\IdentiteDuLecteur;
use App\Module\Evenement\Service\LectureDesRequetes;
use App\Module\Evenement\Service\LimiteurDesLiens;
use App\Module\Evenement\Service\SerialiseurDEvenement;
use App\Module\Evenement\Service\ServiceDesLiens;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Le lien personnel d'un invité, **sans session** — la page `/e/<clé>` du front.
 *
 * ```
 * GET /api/evenements/lien/{clé}            l'annonce, et sa réponse   200 · 404 · 429
 * PUT /api/evenements/lien/{clé}/reponse    répondre                   200 · 404 · 409 · 422 · 429
 * ```
 *
 * Public dans `security.yaml` (`^/api/evenements/lien/`), comme le lien de tirage : on s'y
 * présente sans compte, et **la clé est la seule protection**. D'où les trois mêmes règles :
 *
 * 1. **Un seul refus**, `404 lien_invalide`, au même corps, pour une clé mal formée,
 *    inconnue, révoquée, remplacée, ou dont l'invité a été retiré.
 * 2. **Un limiteur par IP** ({@see LimiteurDesLiens}) : les échecs comptent à part.
 * 3. **Rien d'autre que cette invitation** : voir {@see SerialiseurDEvenement::lien()}.
 *
 * Une session ouverte dans le même navigateur ne change **rien** à la réponse — la clé seule
 * décide. Elle change une seule chose, qui ne se voit pas : la visite de l'organisateur ne
 * pose pas la vue de l'invité ({@see ServiceDesLiens::ouvrir()}).
 */
#[Route('/api/evenements/lien')]
final class LienDEvenementController extends AbstractController
{
    public function __construct(
        private readonly ServiceDesLiens $liens,
        private readonly SerialiseurDEvenement $serialiseur,
        private readonly LectureDesRequetes $lecture,
        private readonly LimiteurDesLiens $limiteur,
        private readonly IdentiteDuLecteur $identite,
    ) {
    }

    #[Route('/{cle}', name: 'evenement_lien_lire', requirements: ['cle' => '[^/]+'], methods: ['GET'])]
    public function lire(string $cle, Request $requete): JsonResponse
    {
        $invitation = $this->invitation($cle, $requete);
        $maintenant = new \DateTimeImmutable();

        $this->liens->ouvrir($invitation, $this->identite->compteConnecte(), $maintenant);

        return $this->charge($this->serialiseur->lien($invitation, $maintenant));
    }

    #[Route('/{cle}/reponse', name: 'evenement_lien_reponse', requirements: ['cle' => '[^/]+'], methods: ['PUT'])]
    public function repondre(string $cle, Request $requete): JsonResponse
    {
        $invitation = $this->invitation($cle, $requete);
        $maintenant = new \DateTimeImmutable();

        $this->liens->repondre($invitation, $this->lecture->reponse($requete), $maintenant);

        return $this->charge($this->serialiseur->lien($invitation, $maintenant));
    }

    private function invitation(string $cle, Request $requete): Invitation
    {
        $this->limiteur->entrer($requete);

        try {
            return $this->liens->invitationDuLien($cle);
        } catch (ErreurDEvenement $erreur) {
            $this->limiteur->echec($requete);

            throw $erreur;
        }
    }

    /** @param array<string, mixed> $charge */
    private function charge(array $charge): JsonResponse
    {
        $reponse = new JsonResponse($charge);
        // La réponse d'une personne ne se met en cache nulle part.
        $reponse->headers->set('Cache-Control', 'private, no-store');

        return $reponse;
    }
}
