<?php

declare(strict_types=1);

namespace App\Module\Core\EventSubscriber;

use App\Module\Core\Entity\User;
use App\Module\Core\Exception\ForbiddenException;
use App\Module\Core\Security\RegimeVoter;
use App\Module\Core\Security\ReserveAuxMembres;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ControllerArgumentsEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Applique {@see ReserveAuxMembres} : un compte **en attente** de validation reçoit
 * `403 regime_en_attente` sur toute lecture de l'arbre (ADR 0003, révision du
 * 01/10/2026).
 *
 * ## Ce que ce souscripteur décide, et ce qu'il ne décide pas
 *
 * Il ne décide **rien** : c'est {@see RegimeVoter::MEMBRE} qui dit si le compte lit
 * l'arbre, comme pour toute autre route. Ce fichier ne fait que choisir la **forme** du
 * refus. Un second endroit qui lirait `core_user.status` ou le cercle serait une seconde
 * source de vérité — l'erreur même que le voter existe pour éviter.
 *
 * Depuis la correction du 01/10/2026, le voter ne regarde plus seulement le statut du
 * compte mais aussi le **régime du cercle** — la valeur même que la session publie dans
 * `cercle.regime` et sur laquelle le front range un compte « en attente ». Un compte
 * actif sans membre actif (ancien administrateur rétrogradé, compte créé en ligne de
 * commande) est donc refusé ici comme un compte en attente. Le détail est sur le voter.
 *
 * ## Les deux refus
 *
 * - **Pas de compte du tout** — ne devrait pas arriver, `access_control` place tout
 *   `/api` derrière `ROLE_USER`. On ne suppose pas pour autant que la configuration est
 *   juste : `AccessDeniedException`, que le pare-feu change en `401` pour un anonyme.
 * - **Un compte, non validé** — `403 regime_en_attente`, avec un message qui dit quoi
 *   attendre. Le front y lit la raison du refus et mène à l'écran d'attente.
 *
 * ## Priorité 15
 *
 * Après `IsGrantedAttributeListener` (20) : une route qui porterait les deux attributs
 * rendrait d'abord le refus le plus général. Avant le contrôleur, bien sûr — c'est tout
 * l'intérêt : **aucune** ligne du contrôleur ne s'exécute pour un compte en attente,
 * pas même le chargement du graphe.
 */
final readonly class ReserveAuxMembresSubscriber implements EventSubscriberInterface
{
    public function __construct(private Security $securite)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::CONTROLLER_ARGUMENTS => ['surArguments', 15]];
    }

    public function surArguments(ControllerArgumentsEvent $event): void
    {
        // L'attribut se lit sur la classe **et** sur la méthode : `getAttributes()`
        // fusionne les deux, et un seul suffit à réserver la route.
        if ([] === $event->getAttributes(ReserveAuxMembres::class)) {
            return;
        }

        if (!$this->securite->getUser() instanceof User) {
            throw new AccessDeniedException();
        }

        if ($this->securite->isGranted(RegimeVoter::MEMBRE)) {
            return;
        }

        throw ForbiddenException::regimeEnAttenteEnLecture();
    }
}
