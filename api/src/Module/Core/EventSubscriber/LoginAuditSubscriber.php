<?php

declare(strict_types=1);

namespace App\Module\Core\EventSubscriber;

use App\Module\Core\Entity\AuditLog;
use App\Module\Core\Entity\User;
use App\Module\Core\Enum\AuditAction;
use App\Module\Core\Security\CleDAccesBadge;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;
use Symfony\Component\Security\Http\Event\LogoutEvent;

/**
 * Connexions, **tentatives échouées** et déconnexions au journal d'audit.
 *
 * Les tentatives échouées ne sont pas un luxe : sur une application familiale privée dont
 * l'unique porte est un formulaire de connexion, c'est la seule trace qui distingue « un
 * cousin a oublié son mot de passe » de « quelqu'un balaie la liste des adresses ». Elles
 * ont leur index partiel dédié (`idx_core_audit_echecs`, § 2.4 du modèle) précisément
 * parce qu'on les interroge isolément.
 *
 * Une tentative bloquée par `login_throttling` passe aussi par `LoginFailureEvent` : elle
 * est donc consignée comme les autres, ce qui est voulu — c'est le pic d'échecs qui rend
 * un balayage visible, pas son interruption.
 *
 * **Le mot de passe tenté n'est jamais écrit**, sous aucune forme. Seul l'identifiant
 * l'est ; c'est déjà ce que l'IP et l'horodatage disent.
 *
 * **La méthode est écrite** dans `changes.methode` des connexions et des échecs :
 * `mot_de_passe` ou `cle_d_acces` (§ 2.4 du contrat). Elle se lit sur le passeport
 * ({@see CleDAccesBadge}). Une tentative par clé d'accès dont la clé est inconnue porte
 * l'identifiant fixe `cle_d_acces_inconnue` : ni l'identifiant de la clé ni la réponse de
 * l'appareil ne sont écrits.
 */
final class LoginAuditSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            LoginSuccessEvent::class => 'surConnexion',
            LoginFailureEvent::class => 'surEchec',
            LogoutEvent::class => 'surDeconnexion',
        ];
    }

    public function surConnexion(LoginSuccessEvent $event): void
    {
        $utilisateur = $event->getUser();

        $this->consigner(
            AuditAction::Connexion,
            $event->getRequest(),
            $utilisateur instanceof User ? $utilisateur->getId() : null,
            $utilisateur->getUserIdentifier(),
            $this->methode($event->getPassport()),
        );
    }

    public function surEchec(LoginFailureEvent $event): void
    {
        $badge = $event->getPassport()?->getBadge(UserBadge::class);
        $identifiant = $badge instanceof UserBadge ? $badge->getUserIdentifier() : null;

        // `actor_user_id` reste nul : on ne veut pas résoudre l'identifiant en compte
        // pour une tentative échouée, ce serait interroger la base sur une saisie
        // arbitraire à chaque coup d'un balayage.
        $this->consigner(AuditAction::ConnexionEchouee, $event->getRequest(), null, $identifiant, $this->methode($event->getPassport()));
    }

    public function surDeconnexion(LogoutEvent $event): void
    {
        $utilisateur = $event->getToken()?->getUser();

        if (null === $utilisateur) {
            return;
        }

        $this->consigner(
            AuditAction::Deconnexion,
            $event->getRequest(),
            $utilisateur instanceof User ? $utilisateur->getId() : null,
            $utilisateur->getUserIdentifier(),
        );
    }

    /**
     * `null` quand il n'y a pas de passeport — une requête si mal formée que
     * l'authentificateur n'en a pas construit : on ne sait alors rien de la méthode.
     *
     * @return array{methode: string}|null
     */
    private function methode(?Passport $passeport): ?array
    {
        if (null === $passeport) {
            return null;
        }

        return ['methode' => $passeport->hasBadge(CleDAccesBadge::class) ? 'cle_d_acces' : 'mot_de_passe'];
    }

    /** @param array<string, mixed>|null $changes */
    private function consigner(AuditAction $action, Request $requete, ?int $acteurId, ?string $acteurEmail, ?array $changes = null): void
    {
        $this->em->persist(new AuditLog(
            $action,
            'Core\\User',
            null !== $acteurId ? (string) $acteurId : null,
            null,
            $changes,
            $acteurId,
            $acteurEmail,
            $requete->getClientIp(),
            $this->tronquer($requete->headers->get('User-Agent')),
        ));

        $this->em->flush();
    }

    private function tronquer(?string $valeur): ?string
    {
        if (null === $valeur || '' === $valeur) {
            return null;
        }

        return mb_substr($valeur, 0, 255);
    }
}
