<?php

declare(strict_types=1);

namespace App\Module\Core\Security;

use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;

/**
 * Levée par {@see UserChecker} quand un compte `suspendu` se présente.
 *
 * Classe dédiée plutôt que comparaison de message : c'est le `instanceof` de
 * {@see AuthenticationFailureHandler} qui distingue `compte_suspendu` de
 * `identifiants_invalides`, et un test sur le texte se casserait à la première
 * reformulation.
 */
final class AccountSuspendedException extends CustomUserMessageAccountStatusException
{
}
