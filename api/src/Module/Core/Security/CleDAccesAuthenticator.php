<?php

declare(strict_types=1);

namespace App\Module\Core\Security;

use App\Module\Core\Service\ClesDAcces;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\CustomCredentials;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;

/**
 * `POST /api/connexion/cle-d-acces` — se connecter avec une clé d'accès (§ 2.4 du
 * contrat d'API).
 *
 * **Un authentificateur du pare-feu, pas un contrôleur qui ouvrirait la session à la
 * main.** C'est ce qui donne à la clé d'accès, sans une ligne de plus, tout ce que la
 * connexion par mot de passe a déjà :
 *
 * - le **limiteur** (`login_throttling`), qui écoute tous les passeports du pare-feu ;
 * - le **`UserChecker`**, qui refuse un compte suspendu après vérification — même
 *   réponse `401 compte_suspendu` que pour le mot de passe ;
 * - la **migration de la session** (anti-fixation) et le **journal d'audit**
 *   (`LoginAuditSubscriber`, qui lit {@see CleDAccesBadge} pour écrire la méthode) ;
 * - les **mêmes réponses**, au caractère près : le succès et l'échec sont délégués aux
 *   gestionnaires de `json_login`. Le front n'a qu'un format à lire, et une clé inconnue
 *   ne se distingue pas d'une signature fausse ni d'un défi expiré.
 *
 * ## L'identifiant présenté au limiteur
 *
 * Le limiteur compte par couple identifiant + IP. Quand la clé est connue, l'identifiant
 * est **le courriel du compte** : les essais par clé et par mot de passe se cumulent, et
 * l'on ne double pas son quota en alternant les deux portes. Quand la clé est inconnue,
 * c'est un identifiant fixe — {@see self::IDENTIFIANT_INCONNU} — : un balayage de clés
 * inventées depuis une même adresse bute sur le plafond au cinquième essai.
 */
final class CleDAccesAuthenticator extends AbstractAuthenticator
{
    public const string CHEMIN = '/api/connexion/cle-d-acces';

    /** L'identifiant compté (et journalisé) pour une clé que la base ne connaît pas. */
    public const string IDENTIFIANT_INCONNU = 'cle_d_acces_inconnue';

    public function __construct(
        private readonly ClesDAcces $cles,
        private readonly AuthenticationSuccessHandler $succes,
        private readonly AuthenticationFailureHandler $echec,
    ) {
    }

    public function supports(Request $request): bool
    {
        // Le chemin exact : `…/cle-d-acces/options` est une route ordinaire, publique.
        return $request->isMethod('POST') && self::CHEMIN === $request->getPathInfo();
    }

    public function authenticate(Request $request): Passport
    {
        $corps = json_decode($request->getContent(), true);
        $reponse = \is_array($corps) && \is_array($corps['reponse'] ?? null) ? $corps['reponse'] : [];

        // Le défi est brûlé ICI, avant toute vérification : une réponse rejouée trouve sa
        // place vide, qu'elle ait réussi la première fois ou non.
        $options = [] !== $reponse ? $this->cles->consommerLeDefiDeConnexion($reponse) : null;
        $cle = [] !== $reponse ? $this->cles->clePresentee($reponse) : null;

        $identifiant = null !== $cle ? $cle->getUser()->getUserIdentifier() : self::IDENTIFIANT_INCONNU;

        return new Passport(
            new UserBadge($identifiant, static function () use ($cle): UserInterface {
                // Clé inconnue : le pare-feu en fait un `BadCredentialsException`
                // (`hide_user_not_found`), donc `identifiants_invalides`.
                return $cle?->getUser() ?? throw new UserNotFoundException();
            }),
            new CustomCredentials(
                fn (array $reponse): bool => null !== $cle && $this->cles->verifierConnexion($cle, $reponse, $options),
                $reponse,
            ),
            [new CleDAccesBadge()],
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): Response
    {
        // Le gestionnaire de `json_login` : date `lastLoginAt`, vide l'unité de travail —
        // ce qui écrit au passage le compteur et la date d'usage de la clé — et rend la
        // charge de session du § 2.2.
        return $this->succes->onAuthenticationSuccess($request, $token);
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        return $this->echec->onAuthenticationFailure($request, $exception);
    }
}
