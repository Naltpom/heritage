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
 * ## Deux sources, dans cet ordre (correction du 01/10/2026)
 *
 * 1. **`core_user.status`** — un compte en attente ou suspendu n'est pas membre. C'est
 *    aussi la seule chose que lit {@see regimeDe()}, dont se sert le cercle lui-même.
 * 2. **Le régime du cercle** ({@see CercleDeVisibiliteInterface::regime()}) — un compte
 *    actif **sans membre actif** n'a pas de cercle, donc pas de lecture.
 *
 * On croyait la seconde inutile : le seul endroit où le régime change,
 * `POST /api/administration/membres/{id}/activer` (§ 12.3), fait passer les deux statuts
 * à `actif` dans la même transaction. Mais un compte peut être actif **sans** `mbr_member`
 * : créé par `app:creer-administrateur`, puis privé de `ROLE_ADMIN`. Ce compte passait
 * `MEMBRE`, franchissait `#[ReserveAuxMembres]` et lisait le graphe en régime `Aucun` —
 * les défunts, et les vivants en nœuds restreints, nom complet compris : la fuite même que
 * l'ADR 0003 révisée ferme. Pendant ce temps, la session lui disait `cercle.regime: aucun`
 * et le front le rangeait « en attente ».
 *
 * Le cercle est **la même valeur** que la session publie : le voter, la session et le
 * front disent désormais la même chose. Ce n'est pas une entorse à l'ADR 0002 — le
 * contrat du cercle appartient à `Core`, `Membership` n'en est que l'adaptateur.
 * Il n'y a pas de boucle : le cercle demande `ROLE_ADMIN`, jamais `MEMBRE`.
 *
 * Un administrateur hors bascule a le cercle complet : il reste membre. Sous la bascule
 * « voir comme » un membre non actif, il lit ce que ce membre lirait — rien.
 *
 * Usage :
 *
 *     #[ReserveAuxMembres]                  // toute lecture de l'arbre — 403 regime_en_attente
 *     $this->isGranted(RegimeVoter::MEMBRE) // écriture : proposer, téléverser
 *
 * **Révision du 01/10/2026 (ADR 0003).** `ATTENTE` ouvrait la lecture de « l'arbre
 * ancien » à un compte non validé : les défunts, et chaque vivant en nœud restreint, nom
 * complet compris. Le lien d'invitation circulant dans un groupe de messagerie, n'importe
 * qui pouvait s'inscrire sous un faux nom et lire les noms des vivants. Plus aucune
 * route ne s'en sert : un compte en attente ne lit plus rien de l'arbre. L'attribut reste
 * pour ce qu'il dit — « une session, pas suspendue » —, utile aux routes du compte.
 *
 * @extends Voter<string, mixed>
 */
final class RegimeVoter extends Voter
{
    public function __construct(private readonly CercleDeVisibiliteInterface $cercle)
    {
    }

    /** Compte actif **et** cercle de visibilité : il lit l'arbre, il peut contribuer. */
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
            // Le statut d'abord — un compte en attente n'a pas à résoudre de cercle —,
            // puis le cercle : actif sans rattachement actif, c'est `Aucun`.
            self::MEMBRE => UserStatus::Actif === $utilisateur->getStatus()
                && Regime::Aucun !== $this->cercle->regime(),
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
