<?php

declare(strict_types=1);

namespace App\Module\Core\Security;

use App\Module\Core\Entity\User;
use App\Module\Core\Enum\UserStatus;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * « Un compte `suspendu` **ne s'authentifie pas** : il n'obtient pas de session qu'il
 * faudrait ensuite filtrer » (§ 1.6 du contrat d'API).
 *
 * **Le contrôle est en `checkPostAuth`, et c'est délibéré.** Symfony appelle
 * `checkPreAuth` *avant* la vérification du mot de passe (priorité 256 sur
 * `CheckPassportEvent`, contre 128 pour `CheckCredentialsListener`) : y placer le refus
 * ferait de `POST /api/connexion` un oracle — n'importe qui saurait, sans connaître le
 * mot de passe, quelles adresses correspondent à un compte suspendu. `checkPostAuth`
 * s'exécute sur `AuthenticationSuccessEvent`, donc **après** la vérification des
 * identifiants ; la réponse du contrat (`401 compte_suspendu`) est rendue au seul
 * titulaire légitime, et tous les autres reçoivent `identifiants_invalides`.
 *
 * Ce contrôle ne couvre que l'authentification interactive. Une session **déjà ouverte**
 * au moment de la suspension est fermée par
 * {@see \App\Module\Core\EventSubscriber\AccountStatusSubscriber}.
 */
final class UserChecker implements UserCheckerInterface
{
    public function checkPreAuth(UserInterface $user): void
    {
        // Volontairement vide : voir l'en-tête de classe.
    }

    public function checkPostAuth(UserInterface $user, ?TokenInterface $token = null): void
    {
        if ($user instanceof User && UserStatus::Suspendu === $user->getStatus()) {
            throw new AccountSuspendedException('Ce compte est suspendu.');
        }
    }
}
