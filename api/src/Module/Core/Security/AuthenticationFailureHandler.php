<?php

declare(strict_types=1);

namespace App\Module\Core\Security;

use App\Module\Core\Http\ErrorEnvelope;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\TooManyLoginAttemptsAuthenticationException;
use Symfony\Component\Security\Http\Authentication\AuthenticationFailureHandlerInterface;

/**
 * Normalise **toutes** les issues d'échec de `POST /api/connexion` dans l'enveloppe du
 * § 15.1.
 *
 * Sans lui, `login_throttling` émet sa propre réponse et « la seule route que tout le
 * monde appelle en premier est la seule à ne pas respecter le contrat » (§ 1.9). Les
 * trois issues possibles, et elles seules :
 *
 * | Exception                                      | Réponse                                |
 * |------------------------------------------------|----------------------------------------|
 * | `TooManyLoginAttemptsAuthenticationException`   | `429 trop_de_tentatives` + `Retry-After` |
 * | {@see AccountSuspendedException}                | `401 compte_suspendu`                  |
 * | tout le reste                                   | `401 identifiants_invalides`           |
 *
 * Le repli sur `identifiants_invalides` est volontairement aveugle : compte inconnu et
 * mot de passe faux doivent être **indiscernables**, sans quoi la route énumère les
 * adresses de la famille.
 */
final class AuthenticationFailureHandler implements AuthenticationFailureHandlerInterface
{
    /**
     * Repli en secondes quand l'exception ne porte pas le délai restant. Aligné sur
     * `login_throttling.interval` de `config/packages/security.yaml` (15 minutes) ; en
     * pratique jamais utilisé, Symfony renseignant le nombre de minutes restantes.
     */
    private const int REPLI_RETRY_AFTER = 900;

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        if ($exception instanceof TooManyLoginAttemptsAuthenticationException) {
            $secondes = $this->secondesAvantNouvelEssai($exception);

            $reponse = ErrorEnvelope::response(
                Response::HTTP_TOO_MANY_REQUESTS,
                'trop_de_tentatives',
                'Trop de tentatives de connexion. Réessayez dans quelques minutes.',
                null,
                ['retryAfter' => $secondes],
            );
            $reponse->headers->set('Retry-After', (string) $secondes);

            return $reponse;
        }

        if ($exception instanceof AccountSuspendedException) {
            return ErrorEnvelope::response(
                Response::HTTP_UNAUTHORIZED,
                'compte_suspendu',
                'Ce compte est suspendu. Contactez l’administrateur de l’arbre.',
            );
        }

        return ErrorEnvelope::response(
            Response::HTTP_UNAUTHORIZED,
            'identifiants_invalides',
            'Courriel ou mot de passe incorrect.',
        );
    }

    /**
     * L'exception ne publie pas son seuil autrement que par ses données de message :
     * `%count%` y vaut le nombre de **minutes** restantes, calculé par
     * `LoginThrottlingListener` depuis la fenêtre réelle du limiteur. On le relit là
     * plutôt que de redire l'intervalle configuré, qui dériverait au premier réglage.
     */
    private function secondesAvantNouvelEssai(TooManyLoginAttemptsAuthenticationException $exception): int
    {
        $minutes = $exception->getMessageData()['%count%'] ?? null;

        return \is_int($minutes) && $minutes > 0 ? $minutes * 60 : self::REPLI_RETRY_AFTER;
    }
}
