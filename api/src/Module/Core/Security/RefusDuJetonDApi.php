<?php

declare(strict_types=1);

namespace App\Module\Core\Security;

use App\Module\Core\Service\LimiteursDesJetonsDApi;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authentication\AuthenticationFailureHandlerInterface;

/**
 * Le `failure_handler` de l'`access_token` du pare-feu `api_jetons` : **un seul refus**,
 * le `401 authentification_requise` du point d'entrée (§ 1.6 et § 15.2 du contrat,
 * ADR 0021 § 6).
 *
 * ## Pourquoi déléguer au point d'entrée
 *
 * Une requête dont l'en-tête n'a même pas la syntaxe d'un Bearer (`Bearer !!`) n'atteint
 * pas l'authentificateur : l'extracteur la laisse passer sans jeton, et c'est le point
 * d'entrée qui répond. Toutes les autres passent ici. Rendre la réponse du point d'entrée
 * fait des deux chemins **un seul corps par construction** — pas deux textes à tenir
 * identiques à la main.
 *
 * Sans ce gestionnaire, l'`AccessTokenAuthenticator` de Symfony rend un `401` vide avec
 * `WWW-Authenticate: Bearer error="invalid_token", error_description="…"`, où la
 * description est le message de l'exception : « Invalid credentials. » pour un jeton
 * inconnu, le message du compte suspendu pour un compte suspendu. L'en-tête dirait ce que
 * le corps tait.
 *
 * ## Le compte suspendu
 *
 * Le `user_checker` (`UserChecker::checkPostAuth`) refuse le compte suspendu **après**
 * avoir reconnu le jeton, et son exception arrive ici comme les autres : même `401
 * authentification_requise`, et non le `401 compte_suspendu` de `POST /api/connexion`.
 * Le mot de passe et la clé d'accès se tapent : leur titulaire est là pour lire le motif.
 * Un jeton se vole et se rejoue sans lui ; son porteur n'apprend donc pas que le compte
 * existe et qu'il est suspendu. Le commanditaire, lui, le sait par
 * `heritage:jeton-api:lister`.
 *
 * ## Chaque refus compte un échec
 *
 * Ce gestionnaire est le lieu unique où un jeton présenté est refusé : il compte l'échec de
 * l'adresse ({@see LimiteursDesJetonsDApi}, 10 sur 15 minutes). Le contrôle, lui, se fait
 * avant le pare-feu, par `LimitesDesJetonsDApiSubscriber`.
 */
final readonly class RefusDuJetonDApi implements AuthenticationFailureHandlerInterface
{
    public function __construct(
        private JsonAuthenticationEntryPoint $pointDEntree,
        private LimiteursDesJetonsDApi $limiteurs,
    ) {
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        $this->limiteurs->compterUnEchec($request);

        return $this->pointDEntree->start($request, $exception);
    }
}
