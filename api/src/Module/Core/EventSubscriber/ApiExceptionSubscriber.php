<?php

declare(strict_types=1);

namespace App\Module\Core\EventSubscriber;

use App\Module\Core\Exception\DomainException;
use App\Module\Core\Exception\TooManyRequestsException;
use App\Module\Core\Http\ErrorEnvelope;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Le **seul** endroit qui traduit une erreur en réponse `/api`, dans l'enveloppe unique
 * du § 15.1 du contrat.
 *
 * Il ne se limite pas aux exceptions de domaine : les erreurs **du routeur** passent par
 * ici aussi. « Une `404` de routeur qui rendrait du HTML casserait le front sur
 * exactement le cas où il a le plus besoin d'un JSON » — le front lit `erreur.code`, il
 * ne sait pas lire une page d'erreur Symfony.
 *
 * **Priorité 0**, donc après le `ExceptionListener` du pare-feu (priorité 1) : c'est lui
 * qui décide d'abord si un accès refusé doit devenir une `401` par le point d'entrée
 * (visiteur anonyme) ou une `AccessDeniedHttpException` (compte authentifié sans le
 * droit). On traduit sa décision, on ne la prend pas à sa place.
 */
final class ApiExceptionSubscriber implements EventSubscriberInterface
{
    public function __construct(
        #[Autowire('%kernel.debug%')]
        private readonly bool $debug,
        private readonly LoggerInterface $journal,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::EXCEPTION => ['surException', 0]];
    }

    public function surException(ExceptionEvent $event): void
    {
        $requete = $event->getRequest();

        if (!str_starts_with($requete->getPathInfo(), '/api')) {
            return;
        }

        $exception = $event->getThrowable();

        // ON JOURNALISE AVANT DE COMPOSER QUOI QUE CE SOIT. L'ordre est tout le sujet.
        //
        // `setResponse()` arrête la propagation de `kernel.exception` : l'`ErrorListener`
        // du framework, qui écoute en -128 et journalise d'ordinaire les exceptions non
        // rattrapées, n'est JAMAIS atteint derrière ce souscripteur. La démonstration
        // tient en deux requêtes sur la même instance, à la même seconde :
        //
        //     GET /inexistant       → 404, journalisée par Symfony
        //     GET /api/inexistant   → 404, RIEN
        //
        // La seule différence est le préfixe. Tant que la journalisation vivait plus bas,
        // dans la seule branche `500`, tout le reste passait par-dessus bord en silence —
        // et cinq `500` de production n'ont laissé aucune trace. Ne pas redescendre cet
        // appel sous la composition de l'enveloppe : il serait de nouveau contournable
        // par la première branche qui sort tôt.
        if (!$this->prisEnCharge($exception)) {
            // Non pris en charge : on ne journalise pas, l'`ErrorListener` de Symfony
            // s'en occupera puisqu'on ne coupera pas la propagation. Journaliser ici
            // ferait doublon — et le bruit est ce qu'on essaie de supprimer.
            return;
        }

        $incident = $this->identifiantDIncident();
        $this->journaliser($requete, $exception, $incident);

        $event->setResponse(
            $exception instanceof DomainException
                ? $this->depuisDomaine($exception)
                : ($this->depuisHttp($exception, $incident) ?? ErrorEnvelope::response(
                    Response::HTTP_INTERNAL_SERVER_ERROR,
                    'erreur_interne',
                    'Erreur interne.',
                    null,
                    ['incident' => $incident],
                )),
        );
    }

    /**
     * Ce souscripteur va-t-il poser une réponse ?
     *
     * La question se pose AVANT de journaliser, pour ne pas écrire deux fois la même
     * panne : ce qu'on ne prend pas en charge reste à l'`ErrorListener` de Symfony.
     */
    private function prisEnCharge(\Throwable $exception): bool
    {
        return $exception instanceof DomainException
            || $exception instanceof HttpExceptionInterface
            || !$this->debug;
    }

    /**
     * Le raccord entre les deux moitiés du § 16.1.
     *
     * La réponse est muette sur la cause, le journal est bavard — mais il faut pouvoir
     * relier les deux. Cet identifiant part au client dans `erreur.details.incident` et
     * se retrouve dans la ligne de journal. Il ne dit RIEN de la panne : c'est du hasard,
     * sans horodatage ni compteur, donc ni devinable ni dénombrable. Un utilisateur le
     * recopie dans un message, l'exploitant retrouve la ligne.
     */
    private function identifiantDIncident(): string
    {
        return bin2hex(random_bytes(8));
    }

    /**
     * « Muette pour le client, bavarde pour l'exploitant » (§ 16.1).
     *
     * Tout ce que la réponse tait part ici : classe, message d'origine, trace, méthode et
     * chemin. Le seul recouvrement avec la réponse est la liste des secrets — mots de
     * passe, secrets TOTP, jetons, chemins de disque — qui ne vont ni dans l'une ni dans
     * l'autre. D'où l'absence volontaire du corps de la requête et des en-têtes dans le
     * contexte : un corps de connexion porte un mot de passe en clair, un en-tête porte
     * un cookie de session.
     *
     * Le NIVEAU suit le statut, et ce n'est pas cosmétique : en production le gestionnaire
     * `fingers_crossed` ne vide son tampon qu'à partir de `error`. Une `500` écrit donc,
     * avec tout le contexte qui la précède ; une `404` reste en tampon et n'écrit rien
     * tant qu'aucune vraie panne ne suit. C'est ce qui garde le journal lisible.
     */
    private function journaliser(Request $requete, \Throwable $exception, string $incident): void
    {
        $statut = $exception instanceof DomainException
            ? $exception->statutHttp()
            : ($exception instanceof HttpExceptionInterface ? $exception->getStatusCode() : Response::HTTP_INTERNAL_SERVER_ERROR);

        $contexte = [
            'incident' => $incident,
            'statut' => $statut,
            'methode' => $requete->getMethod(),
            'chemin' => $requete->getPathInfo(),
            'exception' => $exception,
        ];

        $message = 'Erreur sur une route /api.';

        match (true) {
            $statut >= 500 => $this->journal->critical($message, $contexte),
            Response::HTTP_TOO_MANY_REQUESTS === $statut => $this->journal->warning($message, $contexte),
            \in_array($statut, [Response::HTTP_UNAUTHORIZED, Response::HTTP_FORBIDDEN], true) => $this->journal->notice($message, $contexte),
            default => $this->journal->info($message, $contexte),
        };
    }

    private function depuisDomaine(DomainException $exception): Response
    {
        $reponse = ErrorEnvelope::response(
            $exception->statutHttp(),
            $exception->codeErreur(),
            $exception->getMessage(),
            $exception->champs(),
            $exception->details(),
        );

        if ($exception instanceof TooManyRequestsException) {
            $reponse->headers->set('Retry-After', (string) $exception->retryAfter());
        }

        return $reponse;
    }

    private function depuisHttp(\Throwable $exception, string $incident): ?Response
    {
        return match (true) {
            // `403` sur une ressource que le lecteur peut voir. Un refus qui ne doit même
            // pas révéler l'existence de la ressource lève une `NotFoundException`, pas
            // celle-ci (§ 15.3).
            $exception instanceof AccessDeniedHttpException => ErrorEnvelope::response(
                Response::HTTP_FORBIDDEN,
                'acces_refuse',
                'Accès refusé.',
            ),
            $exception instanceof UnauthorizedHttpException => ErrorEnvelope::response(
                Response::HTTP_UNAUTHORIZED,
                'authentification_requise',
                'Authentification requise.',
            ),
            $exception instanceof NotFoundHttpException => ErrorEnvelope::response(
                Response::HTTP_NOT_FOUND,
                'introuvable',
                'Ressource introuvable.',
            ),
            $exception instanceof MethodNotAllowedHttpException => ErrorEnvelope::response(
                Response::HTTP_METHOD_NOT_ALLOWED,
                'methode_non_autorisee',
                'Méthode non autorisée sur cette ressource.',
                null,
                ['autorisees' => $this->methodesAutorisees($exception)],
            ),
            // Corps JSON illisible ou clé absente : `json_login` lève une
            // `BadRequestHttpException`. Le § 15.2 ne connaît pas de `400` — la donnée
            // soumise est invalide, c'est une `422 donnees_invalides`.
            $exception instanceof BadRequestHttpException => ErrorEnvelope::response(
                Response::HTTP_UNPROCESSABLE_ENTITY,
                'donnees_invalides',
                'Le corps de la requête est illisible ou incomplet.',
                ['corps' => 'JSON attendu, avec toutes les clés requises.'],
            ),
            $exception instanceof HttpExceptionInterface => $this->depuisStatut($exception, $incident),
            // En développement et en test, on laisse passer : la page d'erreur de Symfony
            // et sa trace valent mieux qu'une `500` JSON opaque quand on débogue. En
            // production, jamais — le message d'une exception peut nommer une personne.
            $this->debug => null,
            default => $this->erreurInterne($incident),
        };
    }

    /**
     * La `500` rendue au client : trois champs, aucune information sur la panne.
     *
     * La trace, la classe et le message d'origine sont partis au journal en amont, dans
     * {@see self::journaliser()} — ils ne peuvent PAS être ici : le message d'une
     * exception nomme volontiers une personne, un chemin de fichier ou une requête SQL
     * (§ 16.1). `details.incident` est le seul pont entre cette réponse muette et la
     * ligne de journal bavarde.
     */
    private function erreurInterne(string $incident): Response
    {
        return ErrorEnvelope::response(
            Response::HTTP_INTERNAL_SERVER_ERROR,
            'erreur_interne',
            'Erreur interne.',
            null,
            ['incident' => $incident],
        );
    }

    /**
     * Le filet pour les `HttpException` qu'aucune branche nommée n'a reconnues — celles
     * que lèvent les composants, pas notre code.
     *
     * On les rattache au code du § 15.2 qui correspond à leur statut plutôt que de
     * rendre un code inventé : le front branche sur `erreur.code`, un code qu'il ne
     * connaît pas est traité comme une panne. Et **le message d'origine n'est jamais
     * repris au-delà de 500** : il peut nommer une personne, un chemin de fichier ou une
     * requête SQL (§ 16.1).
     */
    private function depuisStatut(HttpExceptionInterface $exception, string $incident): Response
    {
        $statut = $exception->getStatusCode();

        [$code, $message] = match (true) {
            $statut >= 500 => ['erreur_interne', 'Erreur interne.'],
            Response::HTTP_REQUEST_ENTITY_TOO_LARGE === $statut => ['fichier_trop_volumineux', 'Le fichier est trop volumineux.'],
            Response::HTTP_UNSUPPORTED_MEDIA_TYPE === $statut => ['type_de_fichier_refuse', 'Ce type de fichier n’est pas accepté.'],
            Response::HTTP_UNPROCESSABLE_ENTITY === $statut => ['donnees_invalides', 'Les données envoyées sont invalides.'],
            Response::HTTP_TOO_MANY_REQUESTS === $statut => ['trop_de_tentatives', 'Trop de tentatives. Réessayez dans un moment.'],
            default => ['conflit', $exception->getMessage()],
        };

        // Une 5xx venue d'un composant est une panne comme une autre : elle porte son
        // identifiant de corrélation, sinon elle serait la seule à ne pas être
        // retrouvable au journal.
        return ErrorEnvelope::response($statut, $code, $message, null, $statut >= 500 ? ['incident' => $incident] : null);
    }

    /** @return list<string> */
    private function methodesAutorisees(MethodNotAllowedHttpException $exception): array
    {
        $autorisees = $exception->getHeaders()['Allow'] ?? '';

        if (!\is_string($autorisees) || '' === $autorisees) {
            return [];
        }

        return array_values(array_filter(array_map(trim(...), explode(',', $autorisees))));
    }
}
