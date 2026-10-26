<?php

declare(strict_types=1);

namespace App\Module\Core\EventSubscriber;

use App\Module\Core\Security\JetonDApiAuthentifie;
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
 *
 * ## Le jeton d'API ne marque rien
 *
 * L'`access_token` du pare-feu `api_jetons` émet lui aussi `LoginSuccessEvent` — à
 * **chaque** requête, puisqu'il est sans état. Or un jeton ne prouve jamais une identité
 * récente (ADR 0021 § 4) : il se vole et se rejoue sans son titulaire. Le marquer ferait
 * d'un jeton volé la clé de ce que le mot de passe protège, et poserait en prime une
 * session, donc un cookie, sur une réponse sans état.
 *
 * Le jeton se reconnaît à ses attributs sur le jeton de sécurité de l'événement
 * ({@see JetonDApiAuthentifie}), posés avant ce succès : un critère qui tient à
 * l'authentification elle-même, et non au nom du pare-feu, qui n'est qu'une chaîne de
 * `security.yaml`. {@see PreuveDIdentiteRecente::marquer()} refuse de son côté toute
 * requête par jeton : deux gardes, pour qu'un oubli de l'une ne rouvre rien.
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
        // Un jeton d'API ne prouve jamais une identité récente (ADR 0021 § 4), et
        // `access_token` émet ce succès à chaque requête : la marque poserait une session,
        // donc un cookie, sur une requête sans état.
        if (null !== JetonDApiAuthentifie::depuisLeJetonDeSecurite($event->getAuthenticatedToken())) {
            return;
        }

        $requete = $event->getRequest();

        // Une requête sans session n'a pas où écrire : une marque perdue vaut mieux qu'une
        // connexion qui échoue.
        if ($requete->hasSession()) {
            $this->preuve->marquer($requete->getSession());
        }
    }
}
