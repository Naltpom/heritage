<?php

declare(strict_types=1);

namespace App\Module\Core\Service;

use App\Module\Core\Exception\TooManyRequestsException;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\LimiterInterface;
use Symfony\Component\RateLimiter\RateLimit;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\CacheStorage;

/**
 * Les deux plafonds du jeton d'API (ADR 0021, § 1.9 du contrat) : les **échecs** par
 * adresse IP, et les **requêtes** par jeton.
 *
 * ## Les échecs, par IP : 10 sur 15 minutes
 *
 * Un jeton se devine ou se vole ; ce plafond rend la devinette hors de prix. La mesure est
 * celle de `login_throttling` du pare-feu `main` (même fenêtre de 15 minutes), à ceci près
 * qu'elle compte par IP seule : un jeton n'a pas d'identifiant public à joindre à l'IP.
 *
 * **Le contrôle précède l'authentification** ({@see self::verifierLesEchecs()}, appelé avant
 * le pare-feu) : une IP qui a épuisé ses dix échecs reçoit `429` même avec un **bon** jeton.
 * Sinon le plafond ne protégerait de rien — l'attaquant n'aurait qu'à continuer, la réponse
 * lui dirait seulement quand il a trouvé. Le contrôle ne consomme rien (`consume(0)`) : un
 * appel bloqué n'allonge pas le blocage.
 *
 * **Le comptage se fait au refus** ({@see self::compterUnEchec()}, appelé par
 * `RefusDuJetonDApi`). Un succès ne remet pas le compteur à zéro : un attaquant qui
 * posséderait un jeton valide ne s'en servirait pas pour effacer ses essais.
 *
 * ## Les requêtes, par jeton : 2 000 par heure
 *
 * Un agent qui boucle, ou un jeton volé qui aspire l'arbre, ne doit pas peser sur les
 * membres. Compté après l'authentification réussie, par **id de jeton** : un autre jeton du
 * même compte a son propre plafond, et le cookie du même compte n'en a aucun. La valeur est
 * le paramètre `app.core.jeton_api.requetes_par_heure` (abaissé en test).
 *
 * Même stockage et même forme que les autres limiteurs du dépôt (`cache.rate_limiter`,
 * fenêtre glissante, adresse via `Request::getClientIp()` donc via les proxys de confiance) —
 * voir {@see LimiteurDesOptionsDeConnexion}. Les deux dépassements rendent
 * `429 trop_de_tentatives` avec `Retry-After`, comme `login_throttling`.
 */
final readonly class LimiteursDesJetonsDApi
{
    public const int ECHECS_PAR_IP = 10;

    private CacheStorage $stockage;

    public function __construct(
        #[Autowire(service: 'cache.rate_limiter')]
        CacheItemPoolInterface $pool,
        #[Autowire(param: 'app.core.jeton_api.requetes_par_heure')]
        private int $requetesParHeure,
    ) {
        $this->stockage = new CacheStorage($pool);
    }

    /** Lève `429` quand l'adresse a déjà essuyé ses dix échecs ; ne compte rien. */
    public function verifierLesEchecs(Request $requete): void
    {
        $etat = $this->echecs($requete)->consume(0);

        if (0 === $etat->getRemainingTokens()) {
            throw $this->trop($etat);
        }
    }

    /** Compte un refus de jeton pour l'adresse de la requête. */
    public function compterUnEchec(Request $requete): void
    {
        $this->echecs($requete)->consume();
    }

    /** Compte une requête du jeton ; lève `429` quand il a épuisé son heure. */
    public function compterUneRequete(int $idDuJeton): void
    {
        $etat = (new RateLimiterFactory([
            'id' => 'jeton_api_requetes',
            'policy' => 'sliding_window',
            'limit' => $this->requetesParHeure,
            'interval' => '1 hour',
        ], $this->stockage))->create((string) $idDuJeton)->consume();

        if (!$etat->isAccepted()) {
            throw $this->trop($etat);
        }
    }

    private function echecs(Request $requete): LimiterInterface
    {
        return (new RateLimiterFactory([
            'id' => 'jeton_api_echecs_ip',
            'policy' => 'sliding_window',
            'limit' => self::ECHECS_PAR_IP,
            'interval' => '15 minutes',
        ], $this->stockage))->create($requete->getClientIp() ?? 'inconnue');
    }

    private function trop(RateLimit $etat): TooManyRequestsException
    {
        return new TooManyRequestsException(max(1, $etat->getRetryAfter()->getTimestamp() - time()));
    }
}
