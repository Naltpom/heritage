<?php

declare(strict_types=1);

namespace App\Module\Core\Security;

use App\Module\Core\Entity\User;
use App\Module\Core\Http\ErrorEnvelope;
use App\Module\Core\Service\SessionPayloadBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationSuccessHandlerInterface;

/**
 * `POST /api/connexion` réussi : la charge de session du § 2.2, et rien d'autre.
 *
 * Sans gestionnaire, `json_login` laisse la requête continuer jusqu'au contrôleur ; on le
 * pose explicitement pour que la réponse de connexion soit **exactement** celle de
 * `GET /api/session` — le front n'a alors qu'un seul format à lire, et aucune raison de
 * refaire un appel après s'être connecté (§ 1.1 : « une action qui réussit rend toujours
 * l'état résultant »).
 */
final class AuthenticationSuccessHandler implements AuthenticationSuccessHandlerInterface
{
    public function __construct(
        private readonly SessionPayloadBuilder $charge,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token): Response
    {
        $utilisateur = $token->getUser();

        if (!$utilisateur instanceof User) {
            // Ne peut pas arriver avec le provider `entity` ; on refuse plutôt que de
            // composer une charge de session vide qui ressemblerait à un succès.
            return ErrorEnvelope::response(
                Response::HTTP_UNAUTHORIZED,
                'identifiants_invalides',
                'Courriel ou mot de passe incorrect.',
            );
        }

        // `lastLoginAt` seulement : surtout pas `toucher()`. `updatedAt` date la dernière
        // modification *du compte*, pas sa dernière visite — et le souscripteur d'audit
        // ignore ces deux champs précisément pour que se connecter ne laisse pas une
        // ligne `modification` vide à côté de la ligne `connexion`.
        $utilisateur->setLastLoginAt(new \DateTimeImmutable());
        $this->em->flush();

        return new JsonResponse($this->charge->construire($utilisateur));
    }
}
