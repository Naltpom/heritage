<?php

declare(strict_types=1);

namespace App\Module\Core\Controller;

use App\Module\Core\Entity\User;
use App\Module\Core\Exception\ValidationException;
use App\Module\Core\Service\BasculeVoirComme;
use App\Module\Core\Service\UserAdminService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * `/api/administration/utilisateurs` — § 10.1 du contrat d'API.
 *
 * `access_control` met déjà tout `^/api/administration` derrière `ROLE_ADMIN` ;
 * l'attribut le redit au niveau de la classe, pour que la protection suive le
 * contrôleur si quelqu'un le déplace un jour sous un autre préfixe.
 */
#[IsGranted('ROLE_ADMIN')]
final class UserAdminController extends AbstractController
{
    public function __construct(
        private readonly UserAdminService $comptes,
        private readonly BasculeVoirComme $bascule,
    ) {
    }

    /**
     * `status`, `role=admin` et `q` filtrent **ici** : la liste est paginée, et un filtre
     * appliqué à une page par l'écran ne filtrerait que cette page.
     */
    #[Route('/api/administration/utilisateurs', name: 'core_admin_utilisateurs', methods: ['GET'])]
    public function lister(Request $requete): JsonResponse
    {
        return new JsonResponse($this->comptes->lister(
            $requete->query->getString('status') ?: null,
            $requete->query->getInt('page', 1),
            $requete->query->getInt('parPage', UserAdminService::PAR_PAGE_DEFAUT),
            $requete->query->getString('q'),
            'admin' === $requete->query->getString('role'),
        ));
    }

    /** Les compteurs des puces : `{tous, en_attente, actif, suspendu, admins}`. */
    #[Route('/api/administration/utilisateurs/compteurs', name: 'core_admin_utilisateurs_compteurs', methods: ['GET'])]
    public function compteurs(): JsonResponse
    {
        return new JsonResponse($this->comptes->compteurs());
    }

    #[Route('/api/administration/utilisateurs/{id}', name: 'core_admin_utilisateur', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function detail(int $id): JsonResponse
    {
        return new JsonResponse($this->comptes->normaliser($this->comptes->trouver($id)));
    }

    #[Route('/api/administration/utilisateurs/{id}', name: 'core_admin_utilisateur_modifier', methods: ['PATCH'], requirements: ['id' => '\d+'])]
    public function modifier(int $id, Request $requete, #[CurrentUser] User $administrateur): JsonResponse
    {
        // Aucune écriture sous identité empruntée (§ 10.4, règle 2) : le journal
        // porterait l'administrateur alors que l'écran montrait autre chose.
        $this->bascule->refuserSiActive();

        $corps = json_decode($requete->getContent(), true);

        if (!\is_array($corps)) {
            throw new ValidationException(['corps' => 'Un objet JSON est attendu.']);
        }

        return new JsonResponse($this->comptes->normaliser(
            $this->comptes->modifier($id, $corps, $administrateur),
        ));
    }

    /**
     * Les limites d'usage **personnelles** d'un compte (analyses et questions de
     * l'assistant, téléversements) : `{"limites": {"assistant.analyses": 30, "media.televersements": null}}`.
     * Une route à elle plutôt qu'un champ de plus dans le `PATCH` ci-dessus : ce `PATCH`
     * vérifie le dernier administrateur et le rattachement avant d'écrire, et un réglage
     * de quota n'a rien à faire dans ces gardes. Elle rend la ligne entière du compte,
     * comme lui, pour que le tableau la remplace telle quelle.
     */
    #[Route(
        '/api/administration/utilisateurs/{id}/quotas',
        name: 'core_admin_utilisateur_quotas',
        methods: ['PATCH'],
        requirements: ['id' => '\d+'],
    )]
    public function personnaliserLesQuotas(int $id, Request $requete): JsonResponse
    {
        $this->bascule->refuserSiActive();

        $corps = json_decode($requete->getContent(), true);

        if (!\is_array($corps) || !\array_key_exists('limites', $corps)) {
            throw new ValidationException(['limites' => 'Un objet { "limites": { clé : limite ou null } } est attendu.']);
        }

        return new JsonResponse($this->comptes->normaliser(
            $this->comptes->personnaliserLesQuotas($id, $corps['limites']),
        ));
    }

    /**
     * Réinitialise le mot de passe d'un compte, et **rend le nouveau une seule fois**.
     *
     * C'est la seule réponse de l'API qui contienne un mot de passe en clair, et c'est
     * assumé pour la même raison que le jeton d'invitation du § 3.2 : il n'existe qu'à
     * cet instant, il n'est écrit nulle part, et l'administrateur le transmet par le
     * canal de son choix. Une réinitialisation qui dépendrait du courriel échouerait
     * précisément quand on en a besoin — « aucun SMTP n'est configuré » est un état
     * normal du produit.
     *
     * `Cache-Control: no-store` est déjà posé sur toutes les réponses `/api` par
     * `SecurityHeadersSubscriber` : celle-ci ne doit atterrir dans aucun cache disque.
     */
    #[Route(
        '/api/administration/utilisateurs/{id}/mot-de-passe',
        name: 'core_admin_utilisateur_mot_de_passe',
        methods: ['POST'],
        requirements: ['id' => '\d+'],
    )]
    public function reinitialiserLeMotDePasse(int $id, #[CurrentUser] User $administrateur): JsonResponse
    {
        $this->bascule->refuserSiActive();

        return new JsonResponse([
            'motDePasseReinitialise' => true,
            // Rendu **une fois**. Il n'est ni stocké en clair, ni journalisé, ni relisible.
            'motDePasse' => $this->comptes->reinitialiserLeMotDePasse($id, $administrateur),
        ]);
    }
}
