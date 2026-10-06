<?php

declare(strict_types=1);

namespace App\Module\Proposal\Service;

use App\Module\Core\Contract\Lecteur;
use App\Module\Core\Exception\TooManyRequestsException;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\CacheStorage;

/**
 * Le débit de `POST /api/demandes/doublons-probables`, **par compte** (§ 1.9 du contrat).
 *
 * ## Pourquoi cette route a un plafond quand la recherche n'en a pas
 *
 * Elle ne rend rien que le lecteur ne puisse lire ailleurs — c'est la règle de sa
 * conception. Mais elle compare **des dates** : une boucle qui ferait varier l'année de
 * naissance saisie apprendrait, au moment où la réponse change, ce qu'une fiche porte.
 * Le serveur ne compare que la date réduite par la réciprocité (ADR 0016), donc rien de
 * plus que ce que la fiche affiche ; le plafond est la seconde ceinture, celle qui ne
 * dépend pas d'avoir bien raisonné.
 *
 * ## Le plafond
 *
 * 60 par heure et par compte. Le formulaire l'appelle une fois par clic sur « Envoyer » :
 * une soirée de saisie en consomme une dizaine ; une boucle l'atteint en une minute.
 * Même stockage et même forme que les autres limiteurs du dépôt (`cache.rate_limiter`,
 * fenêtre glissante) — voir `Core\Service\LimiteurDesOptionsDeConnexion`.
 */
final readonly class LimiteurDesDoublons
{
    public const int REQUETES_PAR_COMPTE = 60;

    private CacheStorage $stockage;

    public function __construct(
        #[Autowire(service: 'cache.rate_limiter')]
        CacheItemPoolInterface $pool,
    ) {
        $this->stockage = new CacheStorage($pool);
    }

    /** Compte la demande ; lève `429` quand le compte a épuisé son heure. */
    public function entrer(Lecteur $lecteur): void
    {
        $limite = (new RateLimiterFactory([
            'id' => 'demandes_doublons_compte',
            'policy' => 'sliding_window',
            'limit' => self::REQUETES_PAR_COMPTE,
            'interval' => '1 hour',
        ], $this->stockage))->create((string) $lecteur->userId)->consume();

        if (!$limite->isAccepted()) {
            throw new TooManyRequestsException(max(1, $limite->getRetryAfter()->getTimestamp() - time()));
        }
    }
}
