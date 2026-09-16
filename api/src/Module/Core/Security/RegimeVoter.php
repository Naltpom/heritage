<?php

declare(strict_types=1);

namespace App\Module\Core\Security;

use App\Module\Core\Entity\User;
use App\Module\Core\Enum\Regime;
use App\Module\Core\Enum\UserStatus;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Le voter qui porte la distinction **`ATTENTE` / `MEMBRE`** du § 1.6 du contrat d'API.
 *
 * Elle n'est **pas** portée par un rôle, et c'est écrit dans le contrat : « un
 * `ROLE_MEMBRE` qu'on retire à l'activation serait une seconde source de vérité à côté de
 * `core_user.status` ». Les rôles disent ce qu'on a le droit d'administrer ; le statut du
 * compte dit sous quel régime on lit. Deux questions différentes, deux mécanismes.
 *
 * `core_user.status` est la seule source lue ici, sans détour par `mbr_member` : le seul
 * endroit où le régime change est `POST /api/administration/membres/{id}/activer`
 * (§ 12.3), qui fait passer **les deux** statuts à `actif` dans la même transaction.
 * Interroger `Membership` depuis `Core` violerait l'ADR 0002 pour n'apprendre rien de
 * plus.
 *
 * Usage :
 *
 *     #[IsGranted(RegimeVoter::MEMBRE)]     // écriture : proposer, téléverser
 *     #[IsGranted(RegimeVoter::ATTENTE)]    // lecture : l'arbre ancien suffit
 *
 * @extends Voter<string, mixed>
 */
final class RegimeVoter extends Voter
{
    /** Compte actif : il a un cercle de visibilité, il peut contribuer. */
    public const string MEMBRE = 'REGIME_MEMBRE';

    /** Compte authentifié non suspendu — `MEMBRE` l'implique (§ 1.6). */
    public const string ATTENTE = 'REGIME_ATTENTE';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return \in_array($attribute, [self::MEMBRE, self::ATTENTE], true);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $utilisateur = $token->getUser();

        if (!$utilisateur instanceof User) {
            return false;
        }

        return match ($attribute) {
            self::ATTENTE => UserStatus::Suspendu !== $utilisateur->getStatus(),
            self::MEMBRE => UserStatus::Actif === $utilisateur->getStatus(),
            default => false,
        };
    }

    /**
     * Le régime de lecture d'un compte, pour les services qui ont besoin de la valeur
     * plutôt que d'une décision — la charge de session, la sérialisation.
     *
     * Un seul endroit décide de la correspondance statut → régime : un second, même
     * trivial, finirait par ne plus dire la même chose.
     */
    public static function regimeDe(User $utilisateur): Regime
    {
        return UserStatus::Actif === $utilisateur->getStatus() ? Regime::Cercle : Regime::Aucun;
    }
}
