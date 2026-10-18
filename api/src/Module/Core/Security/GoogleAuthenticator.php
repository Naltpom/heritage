<?php

declare(strict_types=1);

namespace App\Module\Core\Security;

use App\Module\Core\Entity\User;
use App\Module\Core\Http\ErrorEnvelope;
use App\Module\Core\Repository\UserRepository;
use App\Module\Core\Service\ConnexionGoogle;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

/**
 * `POST /api/connexion/google {credential}` — la connexion avec Google.
 *
 * L'ID token est vérifié par {@see ConnexionGoogle}. Le compte se retrouve par son
 * `googleSub`, sinon par son courriel — que Google certifie vérifié : c'est ce qui permet
 * de lier Google à un compte heritage existant sans autre preuve. Un compte suspendu est
 * refusé par le `UserChecker` du pare-feu, comme pour le mot de passe.
 *
 * Aucun compte : `404 inscription_requise`, avec le jeton d'inscription signé et le
 * profil (prénom, nom, photo) qui pré-remplit l'inscription du site de Noël. Heritage, qui
 * n'a pas d'inscription publique, l'affiche comme « aucun compte ».
 */
final class GoogleAuthenticator extends AbstractAuthenticator
{
    public const string CHEMIN = '/api/connexion/google';

    public function __construct(
        private readonly ConnexionGoogle $google,
        private readonly UserRepository $utilisateurs,
        private readonly AuthenticationSuccessHandler $succes,
        private readonly AuthenticationFailureHandler $echec,
    ) {
    }

    public function supports(Request $request): bool
    {
        return $request->isMethod('POST') && self::CHEMIN === $request->getPathInfo();
    }

    public function authenticate(Request $request): Passport
    {
        $corps = json_decode($request->getContent(), true);
        $credential = \is_array($corps) && \is_string($corps['credential'] ?? null) ? $corps['credential'] : '';

        $profil = $this->google->verifier($credential)
            ?? throw new CustomUserMessageAuthenticationException('Connexion Google refusée.');

        $compte = $this->utilisateurs->findOneBy(['googleSub' => $profil->sub])
            ?? $this->utilisateurs->findOneBy(['email' => $profil->email]);

        if (!$compte instanceof User) {
            throw new InscriptionGoogleRequise($profil, $this->google->jetonDInscription($profil));
        }

        if (null === $compte->getGoogleSub()) {
            $compte->setGoogleSub($profil->sub);
        }
        if (null === $compte->getAvatarUrl() && null !== $profil->photo) {
            $compte->setAvatarUrl($profil->photo);
        }

        return new SelfValidatingPassport(new UserBadge($compte->getUserIdentifier(), static fn (): User => $compte), [new GoogleBadge()]);
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): Response
    {
        // Le succès écrit `lastLoginAt` et flush : le `googleSub` lié plus haut part avec.
        return $this->succes->onAuthenticationSuccess($request, $token);
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        if ($exception instanceof InscriptionGoogleRequise) {
            return ErrorEnvelope::response(
                Response::HTTP_NOT_FOUND,
                'inscription_requise',
                'Aucun compte n’est associé à ce compte Google.',
                null,
                ['jeton' => $exception->jeton, ...array_diff_key($exception->profil->enTableau(), ['sub' => true])],
            );
        }

        if ($exception instanceof CustomUserMessageAuthenticationException) {
            return ErrorEnvelope::response(Response::HTTP_UNAUTHORIZED, 'google_refuse', 'La connexion avec Google a échoué. Réessayez.');
        }

        return $this->echec->onAuthenticationFailure($request, $exception);
    }
}
