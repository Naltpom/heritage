<?php

declare(strict_types=1);

namespace App\Module\Tirage\Service;

use App\Module\Core\Exception\TooManyRequestsException;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\CacheStorage;

/**
 * Les deux limiteurs des routes publiques `/api/tirages/lien/{clé}`, par adresse IP.
 *
 * | Limiteur | Compte | Plafond | Au dépassement |
 * |---|---|---|---|
 * | **échecs** | les clés inconnues, révoquées ou mal formées | 20 / h | `429` sur **toute** requête de cette IP |
 * | **débit** | toutes les requêtes | 300 / h | `429` |
 *
 * ## Pourquoi deux, et pourquoi ces plafonds
 *
 * Le lien sert un soir de famille : quinze personnes, **derrière la même box**, qui ouvrent
 * leur lien, le rouvrent, changent leur présence. Un plafond unique assez bas pour gêner
 * une devinette gênerait d'abord cette tablée. On sépare donc :
 *
 * - les **échecs**, qui sont la seule trace d'un balayage — une personne honnête n'en
 *   produit qu'en recopiant mal son lien, et vingt suffisent largement ;
 * - le **débit** général, qui ne borne qu'un script qui s'acharne sur une clé valide
 *   (écritures de présence en rafale) et reste hors de portée d'une famille.
 *
 * Dit sans le flatter : avec 256 bits de clé ({@see GenerateurDeCle}), la devinette est
 * impossible avec ou sans limiteur. Celui-ci est de la défense en profondeur, et la trace
 * qu'un balayage laisse côté serveur.
 *
 * ## `429` et non une réponse muette
 *
 * Contrairement au plafond par jeton de l'inscription, une `429` ici n'est pas un oracle :
 * elle dit qu'une adresse s'acharne, jamais qu'une clé existe — elle tombe de la même façon
 * sur une clé valide que sur une clé inconnue.
 *
 * ## Le stockage
 *
 * Le pool `cache.rate_limiter`, comme les limiteurs de l'inscription : pas de Redis, pas de
 * table, et les tests le vident entre deux cas.
 */
final readonly class LimiteurDesLiens
{
    public const int ECHECS_PAR_IP = 20;
    public const int REQUETES_PAR_IP = 300;

    private CacheStorage $stockage;

    public function __construct(
        #[Autowire(service: 'cache.rate_limiter')]
        CacheItemPoolInterface $pool,
    ) {
        $this->stockage = new CacheStorage($pool);
    }

    /**
     * À l'entrée de chaque requête : refuse une IP qui a épuisé ses échecs, puis compte la
     * requête au débit général.
     */
    public function entrer(Request $requete): void
    {
        $ip = $this->ip($requete);

        // `consume(0)` lit l'état sans rien retirer : on ne paie un échec qu'en échouant.
        $echecs = $this->fabrique('tirage_lien_echecs_ip', self::ECHECS_PAR_IP)->create($ip)->consume(0);

        if (0 >= $echecs->getRemainingTokens()) {
            throw new TooManyRequestsException($this->delai($echecs->getRetryAfter()));
        }

        $debit = $this->fabrique('tirage_lien_debit_ip', self::REQUETES_PAR_IP)->create($ip)->consume();

        if (!$debit->isAccepted()) {
            throw new TooManyRequestsException($this->delai($debit->getRetryAfter()));
        }
    }

    /** Une clé qui n'ouvre rien. */
    public function echec(Request $requete): void
    {
        $this->fabrique('tirage_lien_echecs_ip', self::ECHECS_PAR_IP)->create($this->ip($requete))->consume();
    }

    private function ip(Request $requete): string
    {
        return $requete->getClientIp() ?? 'inconnue';
    }

    private function delai(\DateTimeImmutable $reprise): int
    {
        return max(1, $reprise->getTimestamp() - time());
    }

    /**
     * Fenêtre glissante et non fixe : une fenêtre fixe autorise deux fois le plafond à
     * cheval sur l'heure ronde. Construite à l'appel, comme `LimiteursDInscription`, pour
     * que le module garde ses limiteurs dans son propre code (ADR 0002, règle 1).
     */
    private function fabrique(string $identifiant, int $plafond): RateLimiterFactory
    {
        return new RateLimiterFactory([
            'id' => $identifiant,
            'policy' => 'sliding_window',
            'limit' => $plafond,
            'interval' => '1 hour',
        ], $this->stockage);
    }
}
