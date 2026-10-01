<?php

declare(strict_types=1);

namespace App\Module\Core\EventSubscriber;

use App\Module\Core\Service\PreuveDIdentiteRecente;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/**
 * Chaque connexion réussie — mot de passe ou clé d'accès — vaut **preuve d'identité
 * récente** : on en note l'heure en session ({@see PreuveDIdentiteRecente}).
 *
 * `LoginSuccessEvent` et non `InteractiveLoginEvent` : c'est celui que `json_login` et
 * `CleDAccesAuthenticator` émettent tous deux. L'incarnation (`switch_user`) n'émet ni
 * l'un ni l'autre : elle ne prouve rien de l'identité de l'incarné, et n'en marque rien.
 *
 * La migration de session qui suit la connexion (protection contre la fixation) garde
 * les attributs : la marque survit au changement d'identifiant de session.
 */
final readonly class PreuveDIdentiteSubscriber implements EventSubscriberInterface
{
    public function __construct(private PreuveDIdentiteRecente $preuve)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [LoginSuccessEvent::class => 'surConnexion'];
    }

    public function surConnexion(LoginSuccessEvent $event): void
    {
        $requete = $event->getRequest();

        // Un pare-feu sans état n'a pas de session où écrire — il n'y en a pas ici, mais
        // une marque perdue vaut mieux qu'une connexion qui échoue.
        if ($requete->hasSession()) {
            $this->preuve->marquer($requete->getSession());
        }
    }
}
