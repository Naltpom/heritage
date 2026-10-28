<?php

declare(strict_types=1);

namespace App\Module\Proposal\Service;

use App\Module\Proposal\Exception\DonneesInvalides;

/**
 * L'en-tête `Idempotency-Key` d'un dépôt, lu et jugé (§ 6.1 bis du contrat d'API, ADR 0022).
 *
 * Il porte deux choses qui ne vont pas l'une sans l'autre : la clé que le client a choisie,
 * et l'empreinte du corps qu'elle protège. La clé seule reconnaîtrait un rejeu ; l'empreinte
 * dit si c'est **le même**. Sans elle, un second corps envoyé par erreur sous une clé déjà
 * employée rendrait en silence la première demande, et la seconde disparaîtrait.
 *
 * ── Pourquoi un motif aussi étroit ───────────────────────────────────────────────
 *
 * `[A-Za-z0-9_-]{16,128}` couvre ce qu'un client envoie réellement : une empreinte sha256 en
 * hexadécimal (ce que fera le serveur MCP), un UUID, un ULID, du base64url. Le minimum
 * écarte les clés de fortune (« 1 », « test ») qu'on réutilise par accident. Les guillemets
 * de la forme *sf-string* du brouillon IETF sont refusés plutôt que lus de deux façons, et
 * deux en-têtes fusionnés par un intermédiaire (« a, b ») le sont aussi : le serveur ne
 * choisit pas à la place du client.
 */
final readonly class CleDIdempotence
{
    private function __construct(
        public string $valeur,
        public string $empreinte,
    ) {
    }

    /**
     * `null` sans l'en-tête : le dépôt est inchangé. Présent, il doit être bien formé, même
     * vide. `\A…\z` et non `^…$` : `$` accepte un saut de ligne final.
     *
     * @param array<string, mixed> $corps le corps décodé, celui que lira le dépôt
     *
     * @throws DonneesInvalides `champs.Idempotency-Key`
     */
    public static function depuisLEnTete(?string $enTete, array $corps): ?self
    {
        if (null === $enTete) {
            return null;
        }

        if (1 !== preg_match('/\A[A-Za-z0-9_-]{16,128}\z/', $enTete)) {
            throw DonneesInvalides::pourChamp('Idempotency-Key', 'La clé d\'idempotence compte de 16 à 128 caractères parmi A-Z, a-z, 0-9, « - » et « _ ».');
        }

        return new self($enTete, self::empreinteDuCorps($corps));
    }

    /**
     * sha256 du JSON canonique : les clés d'objet sont triées (l'ordre et les espaces ne
     * font pas un autre corps), l'ordre des listes est gardé (il a un sens). Une clé
     * absente et la même clé à sa valeur par défaut font deux corps différents : le
     * serveur ne devine pas.
     *
     * @param array<mixed> $corps
     */
    public static function empreinteDuCorps(array $corps): string
    {
        return hash('sha256', json_encode(
            self::canonique($corps),
            \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PRESERVE_ZERO_FRACTION,
        ));
    }

    private static function canonique(mixed $valeur): mixed
    {
        if (!\is_array($valeur)) {
            return $valeur;
        }

        $valeur = array_map(self::canonique(...), $valeur);

        if (!array_is_list($valeur)) {
            ksort($valeur, \SORT_STRING);
        }

        return $valeur;
    }
}
