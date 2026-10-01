<?php

declare(strict_types=1);

namespace App\Module\Core\Service;

use App\Module\Core\Exception\TooManyRequestsException;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\CacheStorage;

/**
 * Le débit de `POST /api/connexion/cle-d-acces/options`, par adresse IP.
 *
 * ## Pourquoi cette route a besoin d'un limiteur à elle
 *
 * Elle est **publique** et elle **écrit une session** : le défi de connexion y est gardé
 * (`ClesDAcces::optionsDeConnexion()`). Avec `session.handler.native_file`, chaque appel
 * anonyme sans cookie crée un fichier dans `var/sessions`. Sans plafond, une boucle de
 * requêtes remplit le disque ou épuise les inodes du conteneur — et aucune autre route
 * publique n'ouvrait jusque-là de session à un anonyme (`json_login` n'en ouvre qu'en cas
 * de succès). Le limiteur de connexion de Symfony ne couvre pas ce cas : il compte les
 * **échecs de connexion**, pas les demandes de défi.
 *
 * ## Le plafond
 *
 * 120 par heure et par IP. La page de connexion demande des options à l'affichage, puis
 * les renouvelle toutes les 4 minutes (le défi expire à 5) : une page restée ouverte une
 * heure en consomme une quinzaine. Une famille derrière la même box, plusieurs onglets,
 * quelques rechargements restent loin du plafond ; une boucle l'atteint en quelques
 * secondes.
 *
 * Même stockage et même forme que les autres limiteurs du dépôt (`cache.rate_limiter`,
 * fenêtre glissante) — voir `Tirage\Service\LimiteurDesLiens`.
 */
final readonly class LimiteurDesOptionsDeConnexion
{
    public const int REQUETES_PAR_IP = 120;

    private CacheStorage $stockage;

    public function __construct(
        #[Autowire(service: 'cache.rate_limiter')]
        CacheItemPoolInterface $pool,
    ) {
        $this->stockage = new CacheStorage($pool);
    }

    /** Compte la demande ; lève `429` quand l'adresse a épuisé son heure. */
    public function entrer(Request $requete): void
    {
        $limite = (new RateLimiterFactory([
            'id' => 'cle_d_acces_options_ip',
            'policy' => 'sliding_window',
            'limit' => self::REQUETES_PAR_IP,
            'interval' => '1 hour',
        ], $this->stockage))->create($requete->getClientIp() ?? 'inconnue')->consume();

        if (!$limite->isAccepted()) {
            throw new TooManyRequestsException(max(1, $limite->getRetryAfter()->getTimestamp() - time()));
        }
    }
}
