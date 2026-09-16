<?php

declare(strict_types=1);

namespace App\Module\Membership\Controller;

use App\Module\Core\Entity\User;
use App\Module\Core\Exception\ValidationException;
use App\Module\Core\Service\BasculeVoirComme;
use App\Module\Membership\Service\ServiceDesMembres;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * La file d'activation des membres — `/api/administration/membres` (§ 10.1, § 12.3).
 *
 * `POST .../{id}/activer` est **le seul endroit du produit où le régime d'un compte
 * change** : `core_user.status` et `mbr_member.status` passent `actif` dans la même
 * transaction, et le compte obtient un cercle. Le détail est dans
 * {@see ServiceDesMembres} ; ce contrôleur n'en sait rien.
 */
#[Route('/api/administration/membres')]
final class MembresAdminController extends AbstractController
{
    public function __construct(
        private readonly ServiceDesMembres $membres,
        // « Toute route d'écriture rend `409 vue_comme_membre_active` » (§ 10.4, règle 2).
        // La garde vit dans `Core`, qui possède la bascule.
        private readonly BasculeVoirComme $bascule,
    ) {
    }

    /**
     * La file d'attente. Elle n'est pas au contrat d'API — le § 10.1 n'énumère que
     * `POST .../{id}/activer` — mais un écran d'activation a besoin de savoir qui attend,
     * et `GET /api/administration/utilisateurs?status=en_attente` (celui-là au contrat)
     * appartient à `Core` et ne porte pas le `matchKind`. Signalé au livrable : à
     * confirmer, c'est un ajout et non une lecture du contrat.
     */
    #[Route('', name: 'membership_admin_membres_lister', methods: ['GET'])]
    public function lister(): JsonResponse
    {
        $elements = [];

        foreach ($this->membres->enAttente() as $membre) {
            $elements[] = $this->membres->normaliser($membre);
        }

        return new JsonResponse(['elements' => $elements, 'total' => \count($elements), 'page' => 1, 'parPage' => \count($elements)]);
    }

    #[Route('/{id}/activer', name: 'membership_admin_membre_activer', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function activer(int $id, Request $requete, #[CurrentUser] User $administrateur): JsonResponse
    {
        $this->bascule->refuserSiActive();

        $membre = $this->membres->activer($id, $this->corpsJson($requete), $administrateur);

        return new JsonResponse($this->membres->normaliser($membre));
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
