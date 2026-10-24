<?php

declare(strict_types=1);

namespace App\Module\Tree\Service;

use App\Module\Core\Entity\User;
use App\Module\Core\Security\RegimeVoter;
use App\Module\Tree\Serializer\FabriqueDuLecteur;
use App\Module\Tree\Serializer\PersonneDuLecteur;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Le placement du lecteur, **calculé dès l'ouverture de l'application** — une fois la
 * réponse partie, avant qu'il clique sur l'arbre.
 *
 * ## Pourquoi
 *
 * Le placement est en cache par état de l'arbre et par point de vue
 * ({@see PlacementPartage}), mais ce cache se vide à chaque déploiement (il n'est pas sur un
 * volume, et c'est voulu : il porte l'empreinte de la mise en page) et ses entrées expirent
 * au bout de 24 h. Le premier lecteur qui ouvrait l'arbre ensuite payait le calcul : 2,1 s
 * sur 5 406 personnes le 24/10/2026, mesurées sur un arbre inchangé depuis deux jours, dans
 * une requête qui en prenait 6.
 *
 * ## Quand
 *
 * Après les requêtes qui ouvrent l'application : la session relue au démarrage, et chaque
 * connexion réussie. Le lecteur passe ensuite par l'accueil, ou attend la page : quand le
 * graphe est demandé, le placement est en cache, ou en cours de calcul — et la requête du
 * graphe attend alors ce calcul au lieu d'en lancer un second (verrou du cache Symfony).
 *
 * `kernel.terminate` vient après `fastcgi_finish_request()`, que FrankenPHP fournit : le
 * lecteur ne l'attend pas (même mécanisme que `Core\Service\DiffusionPush`).
 *
 * ## Ce que cela ne change pas
 *
 * Rien n'est servi ici, et rien n'est filtré : on remplit le même cache, avec la même clé,
 * que le graphe aurait remplie. Un lecteur qui n'a pas accès au graphe (régime d'attente)
 * ne déclenche rien. **Ne lève jamais** : un calcul raté ici sera refait par le graphe.
 */
final readonly class PrechauffageDuPlacement
{
    /** Les routes qui ouvrent l'application. */
    public const array ROUTES = [
        'core_session',
        'core_connexion',
        'core_connexion_cle_d_acces',
        'core_connexion_google',
        'core_connexion_google_retour',
    ];

    public function __construct(
        private Security $securite,
        private FabriqueDuLecteur $lecteurs,
        private PersonneDuLecteur $pointDeVue,
        private PlacementPartage $partage,
        private LoggerInterface $journal,
    ) {
    }

    #[AsEventListener(event: KernelEvents::TERMINATE)]
    public function apresLaReponse(TerminateEvent $evenement): void
    {
        if (!$evenement->isMainRequest()
            || $evenement->getResponse()->getStatusCode() >= 400
            || !\in_array($evenement->getRequest()->attributes->get('_route'), self::ROUTES, true)) {
            return;
        }

        try {
            $this->prechauffer();
        } catch (\Throwable $echec) {
            $this->journal->warning('Placement non préchauffé.', ['exception' => $echec]);
        }
    }

    /** Publique pour les tests. Rend `false` quand le lecteur n'a pas accès au graphe. */
    public function prechauffer(): bool
    {
        // La porte du graphe ({@see \App\Module\Core\Security\ReserveAuxMembres}).
        if (!$this->securite->getUser() instanceof User || !$this->securite->isGranted(RegimeVoter::MEMBRE)) {
            return false;
        }

        $this->partage->placement($this->pointDeVue->pour($this->lecteurs->courant()));

        return true;
    }
}
