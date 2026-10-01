<?php

declare(strict_types=1);

namespace App\Module\Core\Service;

use App\Module\Core\Contract\DeclarationDeQuotasInterface;
use App\Module\Core\Contract\Quota;
use App\Module\Core\Contract\QuotasDUsageInterface;
use App\Module\Core\Entity\LimiteDeQuota;
use App\Module\Core\Entity\User;
use App\Module\Core\Exception\ValidationException;
use App\Module\Core\Repository\LimiteDeQuotaRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\RateLimiter\LimiterInterface;
use Symfony\Component\RateLimiter\RateLimit;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\CacheStorage;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Les quotas d'usage, **avec la limite de chaque compte** : décompter, lire la
 * consommation sans la décompter, poser ou retirer une limite personnelle.
 *
 * ## Un limiteur par appel, construit avec la limite effective
 *
 * Une `RateLimiterFactory` porte **une** limite pour toutes ses clés ; elle ne sait pas
 * dire « 20 pour Marbre, 50 pour Opale ». On en fabrique donc une à la volée, avec la
 * limite effective du compte — exactement le geste de {@see LimiteurDesOptionsDeConnexion}.
 * Rien n'est perdu à le faire : la fabrique ne garde aucun état, tout est dans le stockage.
 *
 * ## La consommation déjà comptée reste valable
 *
 * Les anciens limiteurs (`assistant.limiteur_analyses`, `media.limiteur_televersement`…)
 * écrivaient dans `cache.app`, sous `<id>-<clé>`, en fenêtre glissante. On écrit au même
 * endroit, sous le même nom, avec la même fenêtre : le passage à cette classe n'a remis
 * personne à zéro. Et la fenêtre glissante ne stocke **pas** la limite — seulement les
 * coups comptés de la fenêtre courante et de la précédente (`SlidingWindow`) —, de sorte
 * qu'une limite changée s'applique aussitôt aux coups déjà comptés : baisser 60 à 10 un
 * compte qui en est à 12 le bloque tout de suite, monter 20 à 30 un compte bloqué à 20 le
 * débloque tout de suite.
 *
 * ## Lire sans décompter
 *
 * `SlidingWindowLimiter::reserve(0)` — donc `consume(0)` — calcule les jetons restants et
 * **rend avant d'écrire** (`if (0 === $tokens) return …`, puis `if (0 !== $tokens) save`) :
 * ni coup ajouté, ni fenêtre réécrite. C'est la lecture qu'utilisait déjà
 * `tests/Media/PlafondDeDepotTest`. La consommation est `limite − restants`, qui reste juste
 * quand la limite est passée sous le nombre de coups (les restants sont alors négatifs).
 *
 * ## Zéro, et la borne haute
 *
 * **0 bloque** l'usage, sans toucher au compteur : `reserve(1)` sur un limiteur de taille 0
 * lèverait une `InvalidArgumentException` (« plus de jetons que la taille du limiteur »),
 * on rend donc nous-mêmes un refus. Pas d'« illimité » : un plafond infini sur un appel
 * payant chez un tiers (Gemini) est précisément ce que ces quotas existent pour empêcher,
 * et il demanderait une valeur sentinelle de plus dans la table, l'API et l'écran. La borne
 * haute — {@see self::LIMITE_MAXIMALE} — suffit à tout usage réel et garde le compteur,
 * donc l'affichage de la consommation.
 */
final class QuotasDUsage implements QuotasDUsageInterface, ResetInterface
{
    /**
     * 10 000 par fenêtre : cinq cents fois le défaut des analyses. Plus haut n'est plus une
     * limite, c'est une absence de limite qui ne dit pas son nom.
     */
    public const int LIMITE_MAXIMALE = 10_000;

    private readonly CacheStorage $stockage;

    /** @var list<Quota>|null */
    private ?array $quotas = null;

    /**
     * Toute la table, rangée par `<compte ou « * »>|<clé>`, pour la durée de la requête —
     * même parti que `SettingService` : quelques lignes, lues sur presque chaque appel au
     * modèle, et aucune invalidation à oublier.
     *
     * @var array<string, LimiteDeQuota>|null
     */
    private ?array $limites = null;

    /** @param iterable<DeclarationDeQuotasInterface> $declarations */
    public function __construct(
        #[AutowireIterator('core.declaration_de_quotas')]
        private readonly iterable $declarations,
        private readonly LimiteDeQuotaRepository $depot,
        private readonly EntityManagerInterface $em,
        /* Le pool des anciens limiteurs de `assistant.yaml` et `media.yaml` — voir l'en-tête. */
        #[Autowire(service: 'cache.app')]
        CacheItemPoolInterface $pool,
    ) {
        $this->stockage = new CacheStorage($pool);
    }

    public function consommer(Quota $quota, ?int $userId): RateLimit
    {
        $limite = $this->limiteEffective($quota, $userId);

        if (0 === $limite) {
            // Rien à décompter : le compte n'a pas droit à cet usage. Le délai rendu est la
            // fenêtre entière — il ne s'ouvrira pas de lui-même, et le message le dit.
            return new RateLimit(0, new \DateTimeImmutable('+'.$quota->fenetre), false, 0);
        }

        return $this->limiteur($quota, $userId, $limite)->consume();
    }

    /** Ce que le compte a consommé dans la fenêtre glissante — **sans rien décompter**. */
    public function consommation(Quota $quota, ?int $userId): int
    {
        // Lu à une taille d'au moins 1 : `reserve(0)` divise par la limite pour calculer
        // son délai, et la consommation (`limite − restants` = coups comptés) ne dépend
        // pas de la taille choisie. Une limite à 0 garde donc sa consommation lisible.
        $etat = $this->limiteur($quota, $userId, max(1, $this->limiteEffective($quota, $userId)))->consume(0);

        return max(0, $etat->getLimit() - $etat->getRemainingTokens());
    }

    /**
     * Le limiteur du compte, à sa limite effective (ou à `$limite`). Public pour les tests,
     * qui y lisent ou y épuisent un compteur — c'est le même que celui des routes.
     */
    public function limiteur(Quota $quota, ?int $userId, ?int $limite = null): LimiterInterface
    {
        $cle = $quota->compteurGlobal ?? (null !== $userId ? (string) $userId : null);

        if (null === $cle) {
            throw new \LogicException(\sprintf('Le quota « %s » se compte par compte : un identifiant est requis.', $quota->cle));
        }

        return (new RateLimiterFactory([
            'id' => $quota->identifiant,
            'policy' => 'sliding_window',
            'limit' => $limite ?? $this->limiteEffective($quota, $userId),
            'interval' => $quota->fenetre,
        ], $this->stockage))->create($cle);
    }

    public function limiteEffective(Quota $quota, ?int $userId): int
    {
        return $this->limitePersonnelle($quota, $userId) ?? $quota->limiteParDefaut;
    }

    /** `null` : aucune limite posée, le défaut s'applique. */
    public function limitePersonnelle(Quota $quota, ?int $userId): ?int
    {
        return ($this->lignes()[$this->rangement($quota, $userId)] ?? null)?->getLimite();
    }

    /** @return list<Quota> dans l'ordre des déclarations */
    public function quotas(): array
    {
        if (null === $this->quotas) {
            $this->quotas = [];
            foreach ($this->declarations as $declaration) {
                foreach ($declaration->quotas() as $quota) {
                    $this->quotas[] = $quota;
                }
            }
        }

        return $this->quotas;
    }

    /**
     * Les quotas d'un compte, tels que la liste des utilisateurs les rend (`quotas`, § 10.1).
     *
     * @return list<array{cle: string, libelle: string, fenetre: string, consomme: int, limite: int, limiteParDefaut: int, personnalisee: bool, exempte: bool}>
     */
    public function etatDuCompte(User $compte): array
    {
        $userId = $compte->getId();
        $administrateur = \in_array('ROLE_ADMIN', $compte->getRoles(), true);

        if (null === $userId) {
            return [];
        }

        $etat = [];
        foreach ($this->quotas() as $quota) {
            if (!$quota->estGlobal()) {
                $etat[] = $this->etat($quota, $userId) + ['exempte' => $administrateur && $quota->administrateurExempte];
            }
        }

        return $etat;
    }

    /**
     * Les quotas partagés par toute l'installation (`GET /api/administration/quotas`).
     *
     * @return list<array{cle: string, libelle: string, fenetre: string, consomme: int, limite: int, limiteParDefaut: int, personnalisee: bool}>
     */
    public function etatGlobal(): array
    {
        $etat = [];
        foreach ($this->quotas() as $quota) {
            if ($quota->estGlobal()) {
                $etat[] = $this->etat($quota, null);
            }
        }

        return $etat;
    }

    /**
     * Pose ou retire des limites — celles d'un compte, ou les limites globales si `$compte`
     * est `null`. Tout est validé **avant** la première écriture : un lot à moitié appliqué
     * laisserait l'écran dire autre chose que la base.
     *
     * Le corps attendu : `{"assistant.analyses": 30, "assistant.questions": null}`. Une clé
     * absente ne change rien ; `null` rend le défaut ; un entier de 0 à
     * {@see self::LIMITE_MAXIMALE} devient la limite.
     *
     * @throws ValidationException
     */
    public function personnaliser(?User $compte, mixed $limites): void
    {
        if (!\is_array($limites) || ([] !== $limites && array_is_list($limites))) {
            throw new ValidationException(['limites' => 'Un objet { clé du quota : limite ou null } est attendu.']);
        }

        $aAppliquer = [];
        $erreurs = [];
        foreach ($limites as $cle => $valeur) {
            $quota = $this->trouver((string) $cle);

            if (null === $quota || $quota->estGlobal() !== (null === $compte)) {
                $erreurs['limites.'.$cle] = null === $compte
                    ? 'Ce quota n’est pas global : il se règle compte par compte.'
                    : 'Quota inconnu pour un compte.';
                continue;
            }

            if (null !== $valeur && (!\is_int($valeur) || $valeur < 0 || $valeur > self::LIMITE_MAXIMALE)) {
                $erreurs['limites.'.$cle] = \sprintf('Un entier de 0 à %d, ou null pour revenir au défaut (%d).', self::LIMITE_MAXIMALE, $quota->limiteParDefaut);
                continue;
            }

            $aAppliquer[] = [$quota, $valeur];
        }

        if ([] !== $erreurs) {
            throw new ValidationException($erreurs);
        }

        $userId = $compte?->getId();
        foreach ($aAppliquer as [$quota, $valeur]) {
            $ligne = $this->lignes()[$this->rangement($quota, $userId)] ?? null;

            if (null === $valeur) {
                if (null !== $ligne) {
                    $this->em->remove($ligne);
                }
            } elseif (null === $ligne) {
                $this->em->persist(new LimiteDeQuota($compte, $quota->cle, $valeur));
            } elseif ($ligne->getLimite() !== $valeur) {
                $ligne->setLimite($valeur);
            }
        }

        $this->em->flush();
        $this->limites = null;
    }

    public function trouver(string $cle): ?Quota
    {
        foreach ($this->quotas() as $quota) {
            if ($quota->cle === $cle) {
                return $quota;
            }
        }

        return null;
    }

    public function reset(): void
    {
        $this->limites = null;
    }

    /** @return array{cle: string, libelle: string, fenetre: string, consomme: int, limite: int, limiteParDefaut: int, personnalisee: bool} */
    private function etat(Quota $quota, ?int $userId): array
    {
        $personnelle = $this->limitePersonnelle($quota, $userId);

        return [
            'cle' => $quota->cle,
            'libelle' => $quota->libelle,
            'fenetre' => $quota->fenetreLisible,
            'consomme' => $this->consommation($quota, $userId),
            'limite' => $personnelle ?? $quota->limiteParDefaut,
            'limiteParDefaut' => $quota->limiteParDefaut,
            'personnalisee' => null !== $personnelle,
        ];
    }

    /** @return array<string, LimiteDeQuota> */
    private function lignes(): array
    {
        if (null === $this->limites) {
            $this->limites = [];
            foreach ($this->depot->findAll() as $ligne) {
                $this->limites[($ligne->getUserId() ?? '*').'|'.$ligne->getCle()] = $ligne;
            }
        }

        return $this->limites;
    }

    /** Un quota global n'a qu'une ligne, sans compte, quel que soit l'appelant. */
    private function rangement(Quota $quota, ?int $userId): string
    {
        return ($quota->estGlobal() ? '*' : (string) $userId).'|'.$quota->cle;
    }
}
