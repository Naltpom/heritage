<?php

declare(strict_types=1);

namespace App\Module\Tirage\Controller;

use App\Module\Tirage\Entity\Participant;
use App\Module\Tirage\Exception\ErreurDeTirage;
use App\Module\Tirage\Service\LectureDesRequetes;
use App\Module\Tirage\Service\LimiteurDesLiens;
use App\Module\Tirage\Service\SerialiseurDeTirage;
use App\Module\Tirage\Service\ServiceDesLiens;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Le lien personnel d'un participant, **sans session** — la page `/t/<clé>` du front.
 *
 * ```
 * GET /api/tirages/lien/{clé}              ce que voit la personne du lien   200 · 404 · 429
 * PUT /api/tirages/lien/{clé}/presence     sa présence à l'édition courante  200 · 404 · 409 · 422 · 429
 * ```
 *
 * Public dans `security.yaml` (`^/api/tirages/lien/`), comme `^/api/inscription` : on s'y
 * présente sans compte, et **la clé est la seule protection**. D'où trois règles :
 *
 * 1. **Un seul refus**, `404 lien_invalide`, au même corps, pour une clé mal formée,
 *    inconnue, révoquée, remplacée, ou dont le participant a quitté le tirage.
 * 2. **Un limiteur par IP** ({@see LimiteurDesLiens}) : les échecs comptent à part.
 * 3. **Rien d'autre que ce participant** : voir {@see SerialiseurDeTirage::lien()}.
 *
 * Aucun `IsGranted`, aucune bascule « voir comme » : il n'y a pas de compte ici. Une
 * session ouverte dans le même navigateur ne change **rien** à la réponse — la clé seule
 * décide, pour qu'un lien ouvert par l'organisateur montre exactement ce que verra son
 * destinataire.
 *
 * La clé voyage dans le chemin, et non dans un corps comme le jeton d'invitation : c'est
 * un lien qu'on rouvre, pas un formulaire qu'on soumet une fois (et c'est le contrat convenu
 * avec le front). Le prix, dit franchement : elle peut figurer dans un journal d'accès du
 * serveur. `no-store` la tient hors des caches ; la page `/t/<clé>` du front, elle, porte la
 * clé dans son adresse de toute façon.
 */
#[Route('/api/tirages/lien')]
final class LienDeTirageController extends AbstractController
{
    public function __construct(
        private readonly ServiceDesLiens $liens,
        private readonly SerialiseurDeTirage $serialiseur,
        private readonly LectureDesRequetes $lecture,
        private readonly LimiteurDesLiens $limiteur,
    ) {
    }

    #[Route('/{cle}', name: 'tirage_lien_lire', requirements: ['cle' => '[^/]+'], methods: ['GET'])]
    public function lire(string $cle, Request $requete): JsonResponse
    {
        $participant = $this->participant($cle, $requete);

        return $this->charge($this->serialiseur->lien($participant, new \DateTimeImmutable()));
    }

    #[Route('/{cle}/presence', name: 'tirage_lien_presence', requirements: ['cle' => '[^/]+'], methods: ['PUT'])]
    public function presence(string $cle, Request $requete): JsonResponse
    {
        $participant = $this->participant($cle, $requete);
        $aujourdhui = new \DateTimeImmutable();

        $this->liens->definirPresence($participant, $this->lecture->presence($requete), $aujourdhui);

        return $this->charge($this->serialiseur->lien($participant, $aujourdhui));
    }

    private function participant(string $cle, Request $requete): Participant
    {
        $this->limiteur->entrer($requete);

        try {
            return $this->liens->participantDuLien($cle);
        } catch (ErreurDeTirage $erreur) {
            $this->limiteur->echec($requete);

            throw $erreur;
        }
    }

    /** @param array<string, mixed> $charge */
    private function charge(array $charge): JsonResponse
    {
        $reponse = new JsonResponse($charge);
        // Une attribution est un secret : rien ne se met en cache, nulle part.
        $reponse->headers->set('Cache-Control', 'private, no-store');

        return $reponse;
    }
}
