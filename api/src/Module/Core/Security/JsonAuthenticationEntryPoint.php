<?php

declare(strict_types=1);

namespace App\Module\Core\Security;

use App\Module\Core\Http\ErrorEnvelope;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

/**
 * API pure : **jamais** une redirection vers un formulaire, une `401` JSON explicite.
 *
 * Sans point d'entrée déclaré, Symfony choisit le seul qu'il connaisse — la redirection
 * `302` vers `/login` — et le front reçoit du HTML sur exactement la requête où il a le
 * plus besoin d'un JSON : celle qui lui apprend que sa session a expiré.
 *
 * L'enveloppe est celle du § 15.1 du contrat. Le dépôt de référence émet ici
 * `{"error": "…"}` ; Heritage n'a qu'une forme, c'est écrit noir sur blanc dans le
 * contrat parce qu'un agent recopierait la référence sans y penser.
 */
final class JsonAuthenticationEntryPoint implements AuthenticationEntryPointInterface
{
    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        // Le motif exact de l'échec n'est jamais renvoyé : il renseignerait sur
        // l'existence du compte. « Authentification requise », rien de plus.
        return ErrorEnvelope::response(
            Response::HTTP_UNAUTHORIZED,
            'authentification_requise',
            'Authentification requise.',
        );
    }
}
