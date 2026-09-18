<?php

declare(strict_types=1);

namespace App\Module\Core\Controller;

use App\Module\Core\Entity\User;
use App\Module\Core\Exception\NotFoundException;
use App\Module\Core\Exception\ValidationException;
use App\Module\Core\Http\ErrorEnvelope;
use App\Module\Core\Service\ChangementDeCourriel;
use App\Module\Core\Service\SessionPayloadBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Authentification et session — § 2 du contrat d'API.
 *
 * Les deux premières routes ne sont presque pas du code : `POST /api/connexion` est le
 * `check_path` de `json_login` et `POST /api/deconnexion` est interceptée par le listener
 * `logout` du pare-feu. Elles existent pour que le routeur connaisse leurs chemins ; le
 * travail est dans `config/packages/security.yaml` et dans `Core\Security\*`.
 */
final class SecurityController extends AbstractController
{
    /** § 6.1 du dossier de mission : 12 caractères minimum. */
    private const int LONGUEUR_MINIMALE_MOT_DE_PASSE = 12;

    public function __construct(private readonly SessionPayloadBuilder $charge)
    {
    }

    /**
     * Portée par `json_login` : en succès comme en échec, un gestionnaire dédié répond et
     * cette méthode n'est **jamais** atteinte.
     *
     * Elle n'est donc pas morte pour autant. `JsonLoginAuthenticator::supports()` exige un
     * `Content-Type` JSON ; une requête bien intentionnée mais mal formée arrive ici, et
     * il vaut mieux qu'elle reçoive le `401` du contrat qu'une `500` sur route sans
     * contrôleur.
     */
    #[Route('/api/connexion', name: 'core_connexion', methods: ['POST'])]
    public function connexion(#[CurrentUser] ?User $utilisateur): JsonResponse
    {
        if (null === $utilisateur) {
            return ErrorEnvelope::response(
                Response::HTTP_UNAUTHORIZED,
                'identifiants_invalides',
                'Courriel ou mot de passe incorrect.',
            );
        }

        return new JsonResponse($this->charge->construire($utilisateur));
    }

    /** Interceptée par le listener `logout` du pare-feu — jamais exécutée. */
    #[Route('/api/deconnexion', name: 'core_deconnexion', methods: ['POST'])]
    public function deconnexion(): never
    {
        throw new \LogicException('Interceptée par le listener de déconnexion du pare-feu.');
    }

    /** La charge de session du § 2.2, lue par le front au démarrage. */
    #[Route('/api/session', name: 'core_session', methods: ['GET'])]
    public function session(#[CurrentUser] User $utilisateur): JsonResponse
    {
        return new JsonResponse($this->charge->construire($utilisateur));
    }

    /**
     * § 2.1 — changement de mot de passe.
     *
     * `motDePasseActuel` faux rend **`422` et non `401`** : le compte est authentifié,
     * c'est la donnée soumise qui est mauvaise. Une `401` ferait déconnecter le front par
     * sa logique de rattrapage globale, ce qui est exactement l'inverse du service rendu.
     *
     * La session en cours survit ; les autres sessions du même compte ne sont pas
     * invalidées — Symfony ne le fait pas par défaut et il n'existe pas de table de
     * session. C'est en feuille de route, pas improvisé ici.
     */
    #[Route('/api/session/mot-de-passe', name: 'core_session_mot_de_passe', methods: ['POST'])]
    public function changerMotDePasse(
        Request $requete,
        #[CurrentUser] User $utilisateur,
        UserPasswordHasherInterface $hacheur,
        EntityManagerInterface $em,
    ): JsonResponse {
        $corps = $this->corpsJson($requete);

        $actuel = $corps['motDePasseActuel'] ?? null;
        $nouveau = $corps['nouveauMotDePasse'] ?? null;

        $champs = [];
        if (!\is_string($actuel) || '' === $actuel) {
            $champs['motDePasseActuel'] = 'Le mot de passe actuel est requis.';
        }
        if (!\is_string($nouveau) || mb_strlen($nouveau) < self::LONGUEUR_MINIMALE_MOT_DE_PASSE) {
            $champs['nouveauMotDePasse'] = \sprintf('%d caractères minimum.', self::LONGUEUR_MINIMALE_MOT_DE_PASSE);
        }
        if ([] !== $champs) {
            throw new ValidationException($champs);
        }

        \assert(\is_string($actuel) && \is_string($nouveau));

        if (!$hacheur->isPasswordValid($utilisateur, $actuel)) {
            throw new ValidationException(['motDePasseActuel' => 'Le mot de passe actuel est incorrect.']);
        }

        // Le souscripteur d'audit journalise la modification avec `password` à `"***"` :
        // la valeur n'est écrite nulle part, ni l'ancienne ni la nouvelle (§ 2.1).
        $utilisateur->setPassword($hacheur->hashPassword($utilisateur, $nouveau))->toucher();
        $em->flush();

        return new JsonResponse(['motDePasseChange' => true]);
    }

    /**
     * § 2.5 — demande de changement de courriel. Mot de passe actuel exigé, lien de
     * confirmation envoyé à la **nouvelle** adresse ; rien ne change avant le clic.
     * Tout est dans {@see ChangementDeCourriel}, y compris pourquoi.
     */
    #[Route('/api/session/courriel', name: 'core_session_courriel', methods: ['POST'])]
    public function demanderChangementDeCourriel(
        Request $requete,
        #[CurrentUser] User $utilisateur,
        ChangementDeCourriel $changement,
    ): JsonResponse {
        return new JsonResponse($changement->demander($utilisateur, $this->corpsJson($requete), $requete));
    }

    /**
     * § 2.5 — le clic sur le lien. **Publique** (`security.yaml`) : on clique souvent
     * depuis un autre appareil, sans session. Tout refus rend `404`, jamais `403`.
     *
     * Un jeton absent du corps vaut un jeton mal formé : même `404`, même corps. Le
     * seul `422` que cette route connaisse est celui d'un corps qui n'est pas du JSON.
     */
    #[Route('/api/session/courriel/confirmer', name: 'core_session_courriel_confirmer', methods: ['POST'])]
    public function confirmerChangementDeCourriel(Request $requete, ChangementDeCourriel $changement): JsonResponse
    {
        $jeton = $this->corpsJson($requete)['jeton'] ?? null;

        if (!\is_string($jeton)) {
            throw new NotFoundException();
        }

        $changement->confirmer($jeton);

        return new JsonResponse(['courrielChange' => true]);
    }

    /** @return array<string, mixed> */
    private function corpsJson(Request $requete): array
    {
        $decode = json_decode($requete->getContent(), true);

        if (!\is_array($decode)) {
            throw new ValidationException(['corps' => 'Un objet JSON est attendu.']);
        }

        return $decode;
    }
}
