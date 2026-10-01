<?php

declare(strict_types=1);

namespace App\Module\Membership\Service;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\CacheStorage;

/**
 * Les quatre limiteurs du parcours d'inscription (§ 1.9 du contrat d'API).
 *
 * | Route | Clé | Plafond | Réponse au dépassement |
 * |---|---|---|---|
 * | `POST /api/inscription/verifier` | IP | 20 / h | `429 trop_de_tentatives` |
 * | `POST /api/inscription/rattachement` | **jeton** | `invitation.tentatives_par_heure`, défaut 5 | **`200` indistinguable d'une recherche infructueuse** |
 * | `POST /api/inscription/rattachement` | IP | 30 / h | `429 trop_de_tentatives` |
 * | `POST /api/inscription/compte` avec `declaration` | IP | 5 / h | `429 trop_de_tentatives` |
 *
 * **Les deux limiteurs de `rattachement` coexistent et ne se répondent pas de la même
 * façon.** Ce n'est pas une incohérence, c'est le point le plus délicat du contrat : le
 * plafond **par jeton** est le garde-fou anti-oracle, et une `429` y annoncerait au porteur
 * que la limite existe, donc que ses requêtes portaient, donc qu'il y a quelque chose à
 * trouver. Le plafond **par IP** ne dit rien de la validité d'un jeton ni de l'existence
 * d'une personne, seulement qu'une adresse s'acharne : il attrape le balayage distribué
 * sur plusieurs jetons, que le premier ne voit pas.
 *
 * ## Pourquoi les fabriques sont construites ici, et non déclarées en YAML
 *
 * `invitation.tentatives_par_heure` est un **réglage d'administration** (§ 2.2 du modèle),
 * modifiable sans redéploiement. Une `RateLimiterFactory` déclarée sous
 * `framework.rate_limiter` ou en service YAML fige son plafond à la compilation du
 * conteneur : le réglage serait alors un réglage pour de faux, et le garde-fou le plus
 * sensible du produit serait le seul à ne pas obéir à l'écran d'administration. On
 * construit donc la fabrique à l'appel, avec la valeur lue.
 *
 * Les deux limiteurs par IP, eux, ont un plafond fixe — le contrat les donne en dur — mais
 * ils sont construits de la même façon pour que les trois vivent au même endroit.
 *
 * ## Fenêtre glissante et non fixe
 *
 * Une fenêtre fixe autorise deux fois le plafond à cheval sur l'heure ronde, ce qui vide
 * la limite de son sens le jour où quelqu'un s'en aperçoit. Sur le limiteur par jeton, ce
 * « quelqu'un » est exactement la personne contre qui il est posé.
 */
final readonly class LimiteursDInscription
{
    public const int VERIFIER_PAR_IP = 20;
    public const int RATTACHEMENT_PAR_IP = 30;

    /**
     * Comptes **sans fiche** créés par heure et par IP (ADR 0013).
     *
     * L'inscription sans fiche ne demande aucune preuve : n'importe quel porteur du lien
     * peut créer un compte en attente et voir les défunts. C'est assumé, mais un lien posté
     * dans un groupe peut fuiter, et ce plafond borne ce qu'un seul poste peut en faire.
     * Cinq, parce qu'une famille derrière la même box peut légitimement inscrire deux ou
     * trois cousins le même soir.
     */
    public const int SANS_FICHE_PAR_IP = 5;

    private CacheStorage $stockage;

    public function __construct(
        private ReglagesDeMembership $reglages,
        // Le pool des compteurs de `login_throttling` : pas de Redis, pas de table
        // (§ 0.6 du modèle, § 3 du dossier). Les tests le vident entre deux cas, ce qui
        // est la raison pour laquelle on ne prend pas `cache.app`.
        #[Autowire(service: 'cache.rate_limiter')]
        CacheItemPoolInterface $pool,
    ) {
        $this->stockage = new CacheStorage($pool);
    }

    /** 20 / heure / IP sur `POST /api/inscription/verifier`. */
    public function verificationParIp(): RateLimiterFactory
    {
        return $this->fabrique('inscription_verifier_ip', self::VERIFIER_PAR_IP);
    }

    /**
     * **Le garde-fou du § 6.1 du dossier** : `invitation.tentatives_par_heure` par jeton,
     * défaut 5.
     *
     * Son dépassement ne rend **jamais** `429` : voir {@see ServiceDInscription}, qui
     * cherche d'abord et jette ensuite.
     */
    public function rattachementParJeton(): RateLimiterFactory
    {
        return $this->fabrique('inscription_rattachement_jeton', $this->reglages->tentativesParHeure());
    }

    /** 30 / heure / IP — le balayage distribué sur plusieurs jetons. */
    public function rattachementParIp(): RateLimiterFactory
    {
        return $this->fabrique('inscription_rattachement_ip', self::RATTACHEMENT_PAR_IP);
    }

    /**
     * 5 / heure / IP sur `POST /api/inscription/compte` **avec `declaration`** — et rend,
     * lui, un vrai `429` avec `Retry-After` : il ne dit rien d'une personne de l'arbre,
     * seulement qu'une adresse crée des comptes en série.
     */
    public function inscriptionSansFicheParIp(): RateLimiterFactory
    {
        return $this->fabrique('inscription_sans_fiche', self::SANS_FICHE_PAR_IP);
    }

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
