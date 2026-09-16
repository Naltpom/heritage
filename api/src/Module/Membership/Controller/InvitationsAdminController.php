<?php

declare(strict_types=1);

namespace App\Module\Membership\Controller;

use App\Module\Core\Entity\User;
use App\Module\Core\Exception\ValidationException;
use App\Module\Core\Service\BasculeVoirComme;
use App\Module\Membership\Service\NormaliseurDInvitation;
use App\Module\Membership\Service\ServiceDInvitations;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Les invitations, côté administrateur — § 3 du contrat d'API.
 *
 * Tout le préfixe `/api/administration` est derrière `ROLE_ADMIN` par `access_control`, et
 * la règle est posée **avant** `^/api` — « seule la première règle qui correspond est
 * appliquée ». Le troisième segment désigne le module propriétaire : `invitations` et
 * `membres` appartiennent à `Membership` (§ 1.7).
 *
 * ## Il n'existe pas de route « renvoyer l'invitation »
 *
 * Et il ne faut pas en ajouter une : le serveur ne stocke que `hash(jeton)`, le jeton en
 * clair n'existe plus après la réponse de création. L'administrateur qui a perdu le lien
 * **révoque et recrée**. C'est le piège le plus probable de ce module — « la route paraît
 * manquante alors qu'elle est impossible » (§ 3.2).
 *
 * ## `409 vue_comme_membre_active`
 *
 * « Toute route d'écriture rend `409` tant que la bascule est active — création de
 * demande, validation, refus, **invitation**, téléversement, réglages » (§ 10.4, règle 2).
 * On ne crée pas une invitation sous une identité empruntée : `created_by_user_id`
 * porterait l'administrateur alors que l'écran montrait autre chose.
 */
#[Route('/api/administration/invitations')]
final class InvitationsAdminController extends AbstractController
{
    public function __construct(
        private readonly ServiceDInvitations $invitations,
        private readonly NormaliseurDInvitation $normaliseur,
        // Le refus d'écrire sous identité empruntée appartient à `Core`, qui **écrit** la
        // clé de session de la bascule. Le refaire ici recopierait un nom de clé, et un
        // nom de clé recopié finit par diverger — c'est déjà arrivé dans ce dépôt.
        private readonly BasculeVoirComme $bascule,
    ) {
    }

    #[Route('', name: 'membership_admin_invitation_creer', methods: ['POST'])]
    public function creer(Request $requete, #[CurrentUser] User $administrateur): JsonResponse
    {
        $this->bascule->refuserSiActive();

        $resultat = $this->invitations->creer($this->corpsJson($requete), $administrateur);

        return new JsonResponse($this->normaliseur->creation($resultat), Response::HTTP_CREATED);
    }

    #[Route('', name: 'membership_admin_invitation_lister', methods: ['GET'])]
    public function lister(Request $requete): JsonResponse
    {
        $liste = $this->invitations->lister(
            $requete->query->get('status'),
            $requete->query->getInt('page', 1),
            $requete->query->getInt('parPage', 25),
        );

        $elements = [];

        foreach ($liste['elements'] as $invitation) {
            $elements[] = $this->normaliseur->ligne($invitation, $this->invitations->tentativesDeLHeure($invitation));
        }

        return new JsonResponse([
            'elements' => $elements,
            'total' => $liste['total'],
            'page' => $liste['page'],
            'parPage' => $liste['parPage'],
        ]);
    }

    #[Route('/{id}/revoquer', name: 'membership_admin_invitation_revoquer', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function revoquer(int $id, Request $requete, #[CurrentUser] User $administrateur): JsonResponse
    {
        $this->bascule->refuserSiActive();

        $invitation = $this->invitations->revoquer($id, $administrateur);

        return new JsonResponse($this->normaliseur->ligne($invitation, $this->invitations->tentativesDeLHeure($invitation)));
    }

    /** @return array<string, mixed> */
    private function corpsJson(Request $requete): array
    {
        $contenu = $requete->getContent();

        if ('' === $contenu) {
            return [];
        }

        $decode = json_decode($contenu, true);

        if (!\is_array($decode)) {
            throw new ValidationException(['corps' => 'Un objet JSON est attendu.']);
        }

        /* @var array<string, mixed> $decode */
        return $decode;
    }
}
