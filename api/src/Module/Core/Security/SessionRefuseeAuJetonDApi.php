<?php

declare(strict_types=1);

namespace App\Module\Core\Security;

use App\Module\Core\Exception\ForbiddenException;
use Symfony\Component\HttpFoundation\Session\SessionBagInterface;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpFoundation\Session\Storage\MetadataBag;

/**
 * La session d'une requête par jeton d'API : **il n'y en a pas** (ADR 0021 § 4).
 *
 * {@see \App\Module\Core\EventSubscriber\JetonSansSessionSubscriber} la pose à la place de
 * la session que Symfony attache à toute requête principale. Elle dit qu'elle est absente
 * — nom, identifiant vide, jamais démarrée — et **refuse tout le reste** : qui la lit ou
 * l'écrit reçoit un `403 acces_refuse`.
 *
 * ## Pourquoi refuser plutôt que laisser faire
 *
 * Le pare-feu `api_jetons` est sans état, mais le reste de l'application ne sait pas par
 * où l'utilisateur est entré. Une session ordinaire, sous un jeton, s'ouvrirait à la
 * première écriture : un fichier de session de plus à chaque requête, et un cookie sur une
 * réponse qui ne doit en poser aucun. Et ce qui a besoin d'une session — un défi de clé
 * d'accès, l'état d'une connexion Google, les candidats d'une inscription — n'a de sens
 * que d'une requête à l'autre : sous un jeton, il ne marcherait jamais, il laisserait
 * seulement des traces. Le refus le dit tout de suite, sur toute route, y compris celles
 * que la liste blanche des portées ouvrirait par erreur.
 *
 * Ce qui doit **marcher** sous un jeton ne touche donc pas la session : la bascule « voir
 * comme » lit `hasPreviousSession()` (qui ne demande que le nom), la preuve d'identité
 * récente et le journal des connexions reconnaissent le jeton avant d'y venir.
 *
 * ## Pourquoi ces quatre réponses
 *
 * `getName()`, `getId()` et `isStarted()` décrivent la session sans l'ouvrir : Symfony les
 * lit en fin de requête (`AbstractSessionListener`), et une session jamais démarrée n'y
 * reçoit ni enregistrement ni cookie. `save()` n'a rien à enregistrer.
 */
final readonly class SessionRefuseeAuJetonDApi implements SessionInterface
{
    public function __construct(private string $nom)
    {
    }

    public function getName(): string
    {
        return $this->nom;
    }

    public function getId(): string
    {
        return '';
    }

    public function isStarted(): bool
    {
        return false;
    }

    public function save(): void
    {
    }

    public function start(): bool
    {
        throw self::refus();
    }

    public function setId(string $id): void
    {
        throw self::refus();
    }

    public function setName(string $name): void
    {
        throw self::refus();
    }

    public function invalidate(?int $lifetime = null): bool
    {
        throw self::refus();
    }

    public function migrate(bool $destroy = false, ?int $lifetime = null): bool
    {
        throw self::refus();
    }

    public function has(string $name): bool
    {
        throw self::refus();
    }

    public function get(string $name, mixed $default = null): mixed
    {
        throw self::refus();
    }

    public function set(string $name, mixed $value): void
    {
        throw self::refus();
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        throw self::refus();
    }

    /** @param array<string, mixed> $attributes */
    public function replace(array $attributes): void
    {
        throw self::refus();
    }

    public function remove(string $name): mixed
    {
        throw self::refus();
    }

    public function clear(): void
    {
        throw self::refus();
    }

    public function registerBag(SessionBagInterface $bag): void
    {
        throw self::refus();
    }

    public function getBag(string $name): SessionBagInterface
    {
        throw self::refus();
    }

    public function getMetadataBag(): MetadataBag
    {
        throw self::refus();
    }

    /** Le `403` du contrat sur une action refusée (§ 15.3) : cette route n'est pas pour un jeton. */
    private static function refus(): ForbiddenException
    {
        return new ForbiddenException();
    }
}
