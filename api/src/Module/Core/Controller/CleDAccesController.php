<?php

declare(strict_types=1);

namespace App\Module\Core\Controller;

use App\Module\Core\Entity\Passkey;
use App\Module\Core\Entity\User;
use App\Module\Core\Exception\NotFoundException;
use App\Module\Core\Exception\ValidationException;
use App\Module\Core\Http\ErrorEnvelope;
use App\Module\Core\Repository\PasskeyRepository;
use App\Module\Core\Security\CleDAccesAuthenticator;
use App\Module\Core\Service\AvisDeCleDAcces;
use App\Module\Core\Service\ClesDAcces;
use App\Module\Core\Service\Incarnation;
use App\Module\Core\Service\LimiteurDesOptionsDeConnexion;
use App\Module\Core\Service\PreuveDIdentiteRecente;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Les **clés d'accès** (passkeys) — § 2.4 du contrat d'API.
 *
 * Deux familles de routes :
 *
 *  - **`/api/cles-d-acces`** — gérer les siennes : demander un défi d'enregistrement,
 *    enregistrer, lister, renommer, supprimer. Session exigée (filet `^/api` de
 *    `security.yaml`), y compris pour un compte **en attente** : il se connecte, il peut
 *    donc se connecter plus vite. **Refusées pendant une incarnation** (`409
 *    incarnation_active`), sauf la lecture : une clé d'accès ouvre le compte, c'est un
 *    secret du compte au même titre que le mot de passe (ADR 0010). Le défi
 *    d'enregistrement exige en plus une **preuve d'identité récente** (connexion de
 *    moins de dix minutes, ou mot de passe actuel) et chaque ajout est **annoncé par
 *    courriel** au titulaire : une clé survit à la session qui l'a créée.
 *  - **`/api/connexion/cle-d-acces/…`** — publiques (`^/api/connexion`). Les options ici ;
 *    la connexion elle-même est portée par {@see CleDAccesAuthenticator}, sur le modèle de
 *    `json_login`.
 *
 * Toute la cérémonie est dans {@see ClesDAcces} ; ce contrôleur ne fait que lire le corps
 * et choisir le statut.
 */
final class CleDAccesController extends AbstractController
{
    public function __construct(
        private readonly ClesDAcces $cles,
        private readonly PasskeyRepository $depot,
        private readonly Incarnation $incarnation,
        private readonly PreuveDIdentiteRecente $preuve,
        private readonly AvisDeCleDAcces $avis,
        private readonly LimiteurDesOptionsDeConnexion $limiteur,
    ) {
    }

    /**
     * Le seul défi d'enregistrement possible passe par ici : c'est donc ici que se joue
     * la **preuve d'identité récente** ({@see PreuveDIdentiteRecente}) — connexion de
     * moins de dix minutes, ou `motDePasseActuel` dans le corps. Sans elle, une session
     * volée suffirait à planter un moyen de connexion permanent. Le corps est facultatif
     * (vide = pas de mot de passe donné).
     */
    #[Route('/api/cles-d-acces/options-d-enregistrement', name: 'core_cles_d_acces_options', methods: ['POST'])]
    public function optionsDEnregistrement(Request $requete, #[CurrentUser] User $compte): JsonResponse
    {
        $this->incarnation->refuserSiActive();

        $corps = '' === trim($requete->getContent()) ? [] : $this->corpsJson($requete);
        $this->preuve->exiger($compte, $corps['motDePasseActuel'] ?? null);

        return new JsonResponse(['options' => $this->cles->optionsDEnregistrement($compte)]);
    }

    /** Un courriel prévient le titulaire de chaque ajout ({@see AvisDeCleDAcces}). */
    #[Route('/api/cles-d-acces', name: 'core_cles_d_acces_enregistrer', methods: ['POST'])]
    public function enregistrer(Request $requete, #[CurrentUser] User $compte): JsonResponse
    {
        $this->incarnation->refuserSiActive();

        $corps = $this->corpsJson($requete);
        $cle = $this->cles->enregistrer($compte, $corps['reponse'] ?? null, $corps['nom'] ?? null, $requete->headers->get('User-Agent'));
        $this->avis->prevenirDeLAjout($compte, $cle);

        return new JsonResponse($this->cles->enTableau($cle), Response::HTTP_CREATED);
    }

    #[Route('/api/cles-d-acces', name: 'core_cles_d_acces_lister', methods: ['GET'])]
    public function lister(#[CurrentUser] User $compte): JsonResponse
    {
        return new JsonResponse(array_map(
            $this->cles->enTableau(...),
            $this->depot->duCompte((int) $compte->getId()),
        ));
    }

    #[Route('/api/cles-d-acces/{id}', name: 'core_cles_d_acces_renommer', requirements: ['id' => '\d+'], methods: ['PATCH'])]
    public function renommer(int $id, Request $requete, #[CurrentUser] User $compte): JsonResponse
    {
        $this->incarnation->refuserSiActive();

        $cle = $this->laSienne($compte, $id);
        $this->cles->renommer($cle, $this->corpsJson($requete)['nom'] ?? null);

        return new JsonResponse($this->cles->enTableau($cle));
    }

    #[Route('/api/cles-d-acces/{id}', name: 'core_cles_d_acces_supprimer', requirements: ['id' => '\d+'], methods: ['DELETE'])]
    public function supprimer(int $id, #[CurrentUser] User $compte): Response
    {
        $this->incarnation->refuserSiActive();

        $this->cles->supprimer($this->laSienne($compte, $id));

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * Publique : une page de connexion la demande avant que quiconque soit connu. Elle
     * écrit une session (le défi) : d'où le plafond par IP, {@see LimiteurDesOptionsDeConnexion}.
     */
    #[Route('/api/connexion/cle-d-acces/options', name: 'core_connexion_cle_d_acces_options', methods: ['POST'])]
    public function optionsDeConnexion(Request $requete): JsonResponse
    {
        $this->limiteur->entrer($requete);

        return new JsonResponse(['options' => $this->cles->optionsDeConnexion()]);
    }

    /**
     * Portée par {@see CleDAccesAuthenticator} : en succès comme en échec, il répond, et
     * cette méthode n'est jamais atteinte. Elle existe pour que le routeur connaisse le
     * chemin — même raison que `SecurityController::connexion()`.
     */
    #[Route(CleDAccesAuthenticator::CHEMIN, name: 'core_connexion_cle_d_acces', methods: ['POST'])]
    public function connexion(): JsonResponse
    {
        return ErrorEnvelope::response(
            Response::HTTP_UNAUTHORIZED,
            'identifiants_invalides',
            'Courriel ou mot de passe incorrect.',
        );
    }

    /** La clé `$id` de ce compte, ou `404` — une clé d'autrui n'existe pas. */
    private function laSienne(User $compte, int $id): Passkey
    {
        return $this->depot->duCompteParId((int) $compte->getId(), $id)
            ?? throw new NotFoundException('Cette clé d’accès n’existe pas.');
    }

    /** @return array<string, mixed> */
    private function corpsJson(Request $requete): array
    {
        $decode = json_decode($requete->getContent(), true);

        if (!\is_array($decode)) {
            throw new ValidationException(['corps' => 'Un objet JSON est attendu.']);
        }

        /* @var array<string, mixed> $decode */
        return $decode;
    }
}
