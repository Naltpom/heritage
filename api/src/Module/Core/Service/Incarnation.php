<?php

declare(strict_types=1);

namespace App\Module\Core\Service;

use App\Module\Core\Contract\SessionPayloadContributorInterface;
use App\Module\Core\Entity\User;
use App\Module\Core\Enum\AuditAction;
use App\Module\Core\Enum\UserStatus;
use App\Module\Core\Exception\ConflictException;
use App\Module\Core\Exception\ForbiddenException;
use App\Module\Core\Exception\NotFoundException;
use App\Module\Core\Repository\UserRepository;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\SwitchUserToken;

/**
 * **Se connecter en tant qu'un autre compte** — l'incarnation (ADR 0010).
 *
 * Là où la bascule « voir comme un membre » ({@see BasculeVoirComme}) prête un cercle en
 * lecture seule, l'incarnation remplace **le compte lui-même** : l'administrateur voit et
 * fait exactement ce que ferait ce membre, jusqu'à ce qu'il revienne au sien.
 *
 * ## Le mécanisme est celui de Symfony, pas une clé de session maison
 *
 * Le jeton de la session devient un {@see SwitchUserToken} : son utilisateur est le compte
 * incarné, et il **garde le jeton d'origine**. Tout ce qui lit « qui est connecté » —
 * `#[IsGranted]`, le voter de régime, `access_control`, le cercle — voit donc le membre sans
 * qu'une ligne de ces modules change ; et le retour n'a rien à reconstruire, il remet le
 * jeton d'origine. Le pare-feu persiste le jeton en session comme n'importe quel autre.
 *
 * ## La trace
 *
 * - le début et la fin sont journalisés (`incarnation_debut`, `incarnation_fin`) ;
 * - **chaque ligne du journal écrite pendant l'incarnation** porte l'administrateur dans
 *   `impersonator_user_id`, à côté du compte incarné en acteur — c'est
 *   {@see AuditService} et le souscripteur Doctrine qui le lisent sur le jeton.
 *
 * ## Ce qu'elle refuse
 *
 * - incarner un administrateur, un compte suspendu, ou soi-même ;
 * - incarner depuis une incarnation : on revient d'abord à son compte ;
 * - toucher aux **secrets du compte incarné** — mot de passe et courriel, via
 *   {@see refuserSiActive()} : l'administrateur agit au nom du membre, il ne s'approprie
 *   pas son compte.
 */
final readonly class Incarnation implements SessionPayloadContributorInterface
{
    public function __construct(
        private TokenStorageInterface $jetons,
        private UserRepository $utilisateurs,
        private AuditService $audit,
        private BasculeVoirComme $bascule,
    ) {
    }

    public function commencer(int $userId): User
    {
        $jeton = $this->jetons->getToken();
        $administrateur = $jeton?->getUser();

        if (null === $jeton || !$administrateur instanceof User) {
            throw new ForbiddenException();
        }

        if ($jeton instanceof SwitchUserToken) {
            throw new ConflictException('incarnation_active', 'Revenez d’abord à votre compte avant d’en incarner un autre.');
        }

        $cible = $this->utilisateurs->find($userId) ?? throw new NotFoundException('Ce compte n’existe pas.');

        if ($cible->getId() === $administrateur->getId()) {
            throw new ConflictException('incarnation_impossible', 'On ne s’incarne pas soi-même.');
        }

        if (\in_array('ROLE_ADMIN', $cible->getRoles(), true)) {
            throw new ConflictException('incarnation_impossible', 'On n’incarne pas un autre administrateur.');
        }

        if (UserStatus::Suspendu === $cible->getStatus()) {
            throw new ConflictException('incarnation_impossible', 'Ce compte est suspendu : il ne peut pas se connecter, on ne l’incarne pas.');
        }

        // Les deux dispositifs ne se superposent pas : sous l'incarnation, c'est le cercle
        // du membre qui s'applique, et il n'y a plus rien à emprunter.
        $this->bascule->desactiver();

        $this->audit->enregistrer(
            AuditAction::IncarnationDebut,
            'Core\\User',
            (string) $cible->getId(),
            $cible->getDisplayName(),
            ['incarne' => $cible->getEmail()],
        );

        $this->jetons->setToken(new SwitchUserToken($cible, 'main', $cible->getRoles(), $jeton));

        return $cible;
    }

    /** Revient au compte d'origine, et rend celui-ci. */
    public function terminer(): User
    {
        $jeton = $this->jetons->getToken();

        if (!$jeton instanceof SwitchUserToken) {
            throw new ConflictException('incarnation_inactive', 'Aucune incarnation n’est en cours.');
        }

        $incarne = $jeton->getUser();

        // Écrite sous le jeton incarné, **avant** le retour : elle porte donc le membre en
        // acteur et l'administrateur en incarnant, comme toute ligne de l'incarnation.
        $this->audit->enregistrer(
            AuditAction::IncarnationFin,
            'Core\\User',
            $incarne instanceof User ? (string) $incarne->getId() : null,
            $incarne instanceof User ? $incarne->getDisplayName() : null,
        );

        $original = $jeton->getOriginalToken();
        $this->jetons->setToken($original);

        $administrateur = $original->getUser();
        \assert($administrateur instanceof User);

        return $administrateur;
    }

    /** L'administrateur derrière l'incarnation en cours, ou `null`. */
    public function incarnant(): ?User
    {
        $jeton = $this->jetons->getToken();

        if (!$jeton instanceof SwitchUserToken) {
            return null;
        }

        $original = $jeton->getOriginalToken()->getUser();

        return $original instanceof User ? $original : null;
    }

    /**
     * À appeler en tête des routes qui touchent aux **secrets du compte** : mot de passe,
     * courriel. `409 incarnation_active`.
     */
    public function refuserSiActive(): void
    {
        if (null !== $this->incarnant()) {
            throw new ConflictException('incarnation_active', 'Vous êtes connecté en tant que ce membre : son mot de passe et son courriel restent les siens. Revenez à votre compte.');
        }
    }

    /**
     * `incarnation` dans la charge de session : `null`, ou qui incarne. Le front en tire le
     * bandeau permanent et le bouton « Revenir à mon compte ».
     */
    public function contribuer(User $utilisateur): array
    {
        $incarnant = $this->incarnant();

        return ['incarnation' => null === $incarnant ? null : [
            'par' => [
                'id' => $incarnant->getId(),
                'displayName' => '' !== $incarnant->getDisplayName() ? $incarnant->getDisplayName() : $incarnant->getEmail(),
            ],
        ]];
    }
}
