<?php

declare(strict_types=1);

namespace App\Module\Core\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Durcissement des réponses `/api`.
 *
 * **Ce que ce souscripteur fait, et ce qu'il ne fait pas.** La CSP de l'application — la
 * vraie, celle qui protège les pages servies au navigateur — est posée par nginx sur le
 * bloc `server`, avec les polices en même origine et aucun CDN (§ 2 du dossier de
 * mission). Ici, on durcit les **réponses de l'API** : elles ne sont pas des pages, elles
 * ne doivent charger ni exécuter quoi que ce soit, et une `default-src 'none'` le dit
 * sans ambiguïté — utile le jour où une réponse est ouverte directement dans un onglet.
 *
 * > **Piège nginx à ne pas réintroduire**, constaté dans le dépôt de référence : un
 * > `add_header` placé dans un bloc `location` **remplace** tout le jeu hérité du bloc
 * > `server` au lieu de s'y ajouter. Le `location /assets/` de la référence perd ainsi
 * > silencieusement la CSP sur tous les actifs. Toute modification d'un `location` doit
 * > **réémettre l'intégralité** des en-têtes. La configuration nginx n'est pas dans le
 * > périmètre de ce module ; la règle est rappelée ici parce que c'est le fichier qu'on
 * > ouvre quand on cherche « où sont les en-têtes de sécurité ».
 *
 * `Cache-Control: no-store` par défaut et non `no-cache` : ces réponses contiennent des
 * données de personnes vivantes, elles n'ont rien à faire dans le cache disque d'un
 * navigateur familial partagé (§ 1.8 du contrat). L'unique exception est le graphe, qui
 * se revalide par `ETag` — on le reconnaît à cet `ETag`, seule route du contrat à en
 * porter un, plutôt qu'à une liste de chemins que personne ne penserait à tenir à jour.
 */
final class SecurityHeadersSubscriber implements EventSubscriberInterface
{
    /** @var array<string, string> */
    private const array EN_TETES = [
        // Aucune ressource : une réponse d'API ne charge rien, n'exécute rien.
        'Content-Security-Policy' => "default-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'",
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options' => 'DENY',
        // Une URL d'API peut porter un identifiant de personne : elle ne sort pas du site.
        'Referrer-Policy' => 'no-referrer',
        'Cross-Origin-Opener-Policy' => 'same-origin',
        'Cross-Origin-Resource-Policy' => 'same-origin',
        'X-Permitted-Cross-Domain-Policies' => 'none',
        'Permissions-Policy' => 'geolocation=(), microphone=(), camera=(), payment=(), usb=()',
    ];

    /**
     * Priorité **-1100**, et ce nombre n'est pas arbitraire : il vaut -1000 moins un.
     *
     * `AbstractSessionListener` s'exécute à -1000 et, dès qu'une session est démarrée,
     * pose `Cache-Control: max-age=0, must-revalidate, private`. En passant avant lui on
     * obtenait un en-tête composite où `no-store` se noyait au milieu de directives de
     * revalidation — c'est-à-dire l'inverse de ce que le § 1.8 demande. En passant après,
     * c'est nous qui écrivons en dernier.
     *
     * C'est la même famille de défaut que le piège nginx rappelé plus haut : deux
     * composants qui écrivent le même en-tête sans se connaître, et un résultat qui
     * dépend de l'ordre. Un ordre de priorité sans commentaire est un contrat implicite ;
     * celui-ci est donc écrit. **Qui ajoute un en-tête ici doit vérifier qui d'autre
     * l'écrit, et à quelle priorité.**
     *
     * La valeur finale observée est `no-store, private`, et c'est correct : le `private`
     * ne vient pas du listener de session mais de
     * `ResponseHeaderBag::computeCacheControlValue()`, qui l'ajoute à toute valeur ne
     * portant ni `public` ni `s-maxage`. Sur une route dont la charge dépend du lecteur,
     * il va dans le même sens que `no-store` — on ne le combat pas.
     */
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::RESPONSE => ['surReponse', -1100]];
    }

    public function surReponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        if (!str_starts_with($event->getRequest()->getPathInfo(), '/api')) {
            return;
        }

        $reponse = $event->getResponse();

        foreach (self::EN_TETES as $nom => $valeur) {
            $reponse->headers->set($nom, $valeur);
        }

        // La charge dépend du lecteur, identifié par son cookie de session **ou** par un
        // `Authorization: Bearer hrt_…` hors navigateur (ADR 0021 Jetons d'API) : un cache
        // partagé doit distinguer les deux. On ajoute aux `Vary` déjà posés par les routes,
        // en une seule ligne (un tableau donnerait une ligne
        // `Vary` par nom), graphe à `ETag` compris.
        $reponse->headers->set('Vary', implode(', ', array_unique([...$reponse->getVary(), 'Cookie', 'Authorization'])));

        $this->poserLaPolitiqueDeCache($reponse);
    }

    private function poserLaPolitiqueDeCache(Response $reponse): void
    {
        // Le graphe (§ 5.2) est la seule route à `ETag` : elle a posé elle-même
        // `private, no-cache` + `Vary: Cookie`, on ne l'écrase pas.
        if ($reponse->headers->has('ETag')) {
            return;
        }

        $reponse->headers->set('Cache-Control', 'no-store');
    }
}
