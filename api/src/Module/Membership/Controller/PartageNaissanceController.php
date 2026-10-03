<?php

declare(strict_types=1);

namespace App\Module\Membership\Controller;

use App\Module\Core\Entity\User;
use App\Module\Core\Exception\ConflictException;
use App\Module\Core\Exception\NotFoundException;
use App\Module\Core\Exception\ValidationException;
use App\Module\Core\Service\BasculeVoirComme;
use App\Module\Membership\Entity\AutorisationDeNaissance;
use App\Module\Membership\Repository\AutorisationDeNaissanceRepository;
use App\Module\Membership\Repository\MemberRepository;
use App\Module\Membership\Service\ConsentementDeNaissance;
use App\Module\Membership\Service\StockageDesAutorisations;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Les routes de la réciprocité des vivants (ADR 0016).
 *
 * - `PUT /api/session/partage-naissance` — le titulaire d'un compte change ce qu'il partage
 *   de sa naissance. Tout compte rattaché, même en attente : on consent avant d'être validé.
 * - `/api/administration/personnes/{id}/autorisation-naissance` — l'autorisation signée
 *   d'un vivant sans compte : la lire, la déposer, la retirer.
 * - `/api/administration/autorisations-naissance/{id}/document` — le document lui-même,
 *   aux administrateurs seulement (`security.yaml`).
 */
final class PartageNaissanceController extends AbstractController
{
    public function __construct(
        private readonly MemberRepository $membres,
        private readonly AutorisationDeNaissanceRepository $autorisations,
        private readonly ConsentementDeNaissance $consentements,
        private readonly StockageDesAutorisations $stockage,
        private readonly BasculeVoirComme $bascule,
    ) {
    }

    #[Route('/api/session/partage-naissance', name: 'membership_partage_naissance', methods: ['PUT'])]
    public function modifier(Request $requete, #[CurrentUser] User $utilisateur): JsonResponse
    {
        // Sous « voir comme », l'écran est celui d'un autre : on n'y écrit pas son choix.
        $this->bascule->refuserSiActive();

        $membre = null !== ($userId = $utilisateur->getId()) ? $this->membres->pourUtilisateur($userId) : null;
        if (null === $membre) {
            throw new ConflictException('compte_non_rattache', 'Ce compte n’est rattaché à aucune personne de l’arbre : il n’a pas de date de naissance à partager.');
        }

        [$partage, $erreurs] = ConsentementDeNaissance::lire($this->corpsJson($requete));
        if ([] !== $erreurs) {
            throw new ValidationException($erreurs);
        }

        $this->consentements->enregistrer($membre, $partage, 'profil');

        return new JsonResponse(ConsentementDeNaissance::etat($membre));
    }

    #[Route('/api/administration/personnes/{id}/autorisation-naissance', name: 'membership_admin_autorisation_lire', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function lire(int $id): JsonResponse
    {
        return new JsonResponse($this->etat($id));
    }

    #[Route('/api/administration/personnes/{id}/autorisation-naissance', name: 'membership_admin_autorisation_deposer', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function deposer(int $id, Request $requete, #[CurrentUser] User $administrateur): JsonResponse
    {
        $this->bascule->refuserSiActive();

        $document = $requete->files->get('document');
        if (!$document instanceof UploadedFile || !$document->isValid()) {
            throw new ValidationException(['document' => 'Joignez le document signé (PDF ou image).']);
        }

        [$partage, $erreurs] = ConsentementDeNaissance::lire($requete->request->all());
        if ([] !== $erreurs) {
            throw new ValidationException($erreurs);
        }

        $note = $requete->request->get('note');

        $this->consentements->deposerAutorisation(
            $id,
            $document->getPathname(),
            $partage,
            \is_string($note) ? $note : null,
            $administrateur,
        );

        return new JsonResponse($this->etat($id), Response::HTTP_CREATED);
    }

    #[Route('/api/administration/personnes/{id}/autorisation-naissance', name: 'membership_admin_autorisation_retirer', requirements: ['id' => '\d+'], methods: ['DELETE'])]
    public function retirer(int $id, #[CurrentUser] User $administrateur): JsonResponse
    {
        $this->bascule->refuserSiActive();

        $this->consentements->retirerAutorisation($id, $administrateur);

        return new JsonResponse($this->etat($id));
    }

    #[Route('/api/administration/autorisations-naissance/{id}/document', name: 'membership_admin_autorisation_document', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function document(int $id): Response
    {
        $autorisation = $this->autorisations->find($id);

        if (null === $autorisation || !$this->stockage->existe($autorisation->getDocumentName())) {
            throw new NotFoundException();
        }

        $reponse = new BinaryFileResponse($this->stockage->chemin($autorisation->getDocumentName()));
        $reponse->headers->set('Content-Type', $autorisation->getDocumentMime());
        $reponse->headers->set('Cache-Control', 'no-store, private');
        $reponse->headers->set('X-Content-Type-Options', 'nosniff');
        $reponse->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            \sprintf('autorisation-%d.%s', $id, StockageDesAutorisations::EXTENSIONS[$autorisation->getDocumentMime()] ?? 'bin'),
        );

        return $reponse;
    }

    /** @return array<string, mixed> */
    private function etat(int $personId): array
    {
        $historique = $this->autorisations->historiquePour($personId);
        $active = array_values(array_filter($historique, static fn (AutorisationDeNaissance $a): bool => $a->estActive()))[0] ?? null;

        return [
            'compteRattache' => null !== $this->membres->pourPersonne($personId),
            'autorisation' => null !== $active ? $this->normaliser($active) : null,
            'historique' => array_map($this->normaliser(...), $historique),
        ];
    }

    /** @return array<string, mixed> */
    private function normaliser(AutorisationDeNaissance $autorisation): array
    {
        return [
            'id' => $autorisation->getId(),
            ...$autorisation->getPartage()->enTableau(),
            'note' => $autorisation->getNote(),
            'mime' => $autorisation->getDocumentMime(),
            'taille' => $autorisation->getDocumentSize(),
            'sha256' => $autorisation->getDocumentSha256(),
            'accordeeLe' => $autorisation->getGrantedAt()->format(\DateTimeInterface::ATOM),
            'accordeeParUserId' => $autorisation->getGrantedByUserId(),
            'archiveeLe' => $autorisation->getArchivedAt()?->format(\DateTimeInterface::ATOM),
            'raisonArchivage' => $autorisation->getArchiveReason(),
            'documentUrl' => \sprintf('/api/administration/autorisations-naissance/%d/document', (int) $autorisation->getId()),
        ];
    }

    /** @return array<mixed> */
    private function corpsJson(Request $requete): array
    {
        $decode = json_decode($requete->getContent(), true);

        if (!\is_array($decode)) {
            throw new ValidationException(['corps' => 'Un objet JSON est attendu.']);
        }

        return $decode;
    }
}
