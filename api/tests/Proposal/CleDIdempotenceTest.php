<?php

declare(strict_types=1);

namespace App\Tests\Proposal;

use App\Module\Proposal\Exception\DonneesInvalides;
use App\Module\Proposal\Service\CleDIdempotence;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * L'en-tête `Idempotency-Key`, lu et jugé (ADR 0022), seul, sans noyau.
 *
 * Deux promesses, et ce fichier éprouve chacune :
 *
 * - **la forme** : 16 à 128 caractères `[A-Za-z0-9_-]`, rien d'autre, même pas un saut de
 *   ligne final ; une clé refusée nomme `Idempotency-Key` dans `champs` ;
 * - **l'empreinte** ne voit pas l'ordre des clés d'un objet, mais elle voit l'ordre d'une
 *   liste et une valeur par défaut écrite en toutes lettres : le serveur ne devine pas.
 *
 * Le rejeu lui-même, en HTTP, est éprouvé par {@see IdempotenceDuDepotEnHttpTest}.
 */
final class CleDIdempotenceTest extends TestCase
{
    public function testSansEnTetePasDeCle(): void
    {
        self::assertNull(CleDIdempotence::depuisLEnTete(null, ['kind' => 'ajout_note']));
    }

    /** La clé que le serveur MCP enverra : le sha256 de son plan, en hexadécimal. */
    public function testUneEmpreinteSha256EnHexadecimalEstAdmise(): void
    {
        $valeur = hash('sha256', 'un plan de dépôt');

        $cle = CleDIdempotence::depuisLEnTete($valeur, ['kind' => 'ajout_note']);

        self::assertNotNull($cle);
        self::assertSame($valeur, $cle->valeur);
        self::assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $cle->empreinte);
    }

    public function testLesBornesDeLongueur(): void
    {
        foreach ([16, 128] as $longueur) {
            self::assertNotNull(CleDIdempotence::depuisLEnTete(str_repeat('a', $longueur), []), $longueur.' caractères');
        }

        foreach ([15, 129] as $longueur) {
            try {
                CleDIdempotence::depuisLEnTete(str_repeat('a', $longueur), []);
                self::fail($longueur.' caractères : la clé doit être refusée.');
            } catch (DonneesInvalides $erreur) {
                self::assertArrayHasKey('Idempotency-Key', $erreur->champs() ?? [], $longueur.' caractères');
            }
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function valeursMalFormees(): iterable
    {
        yield 'vide' => [''];
        yield 'espaces' => ['avec un espace dedans'];
        yield 'entre guillemets (sf-string)' => ['"entre-guillemets-0123"'];
        yield 'accent' => ['clé-accentuée-0123456'];
        yield 'deux en-têtes fusionnés' => ['aaaaaaaaaaaaaaaa, bbbbbbbbbbbbbbbb'];
        yield 'saut de ligne final' => ["abcdefghijklmnop\n"];
    }

    #[DataProvider('valeursMalFormees')]
    public function testLesValeursMalFormeesSontRefusees(string $enTete): void
    {
        try {
            CleDIdempotence::depuisLEnTete($enTete, []);
            self::fail('La clé doit être refusée.');
        } catch (DonneesInvalides $erreur) {
            self::assertSame('donnees_invalides', $erreur->codeErreur());
            self::assertArrayHasKey('Idempotency-Key', $erreur->champs() ?? []);
        }
    }

    public function testLEmpreinteIgnoreLOrdreDesClesMaisPasCeluiDesListes(): void
    {
        self::assertSame(
            CleDIdempotence::empreinteDuCorps(['a' => 1, 'b' => [1, 2]]),
            CleDIdempotence::empreinteDuCorps(['b' => [1, 2], 'a' => 1]),
            'L’ordre des clés d’un objet ne fait pas un autre corps.',
        );
        self::assertNotSame(
            CleDIdempotence::empreinteDuCorps(['a' => 1, 'b' => [1, 2]]),
            CleDIdempotence::empreinteDuCorps(['b' => [2, 1], 'a' => 1]),
            'L’ordre d’une liste a un sens : les ops sont dans l’ordre où on les applique.',
        );
    }

    /**
     * Un constructeur lit un objet à la place d'une liste dans son ordre : `{"1": b, "0": a}`
     * dépose `[b, a]`. Trié en `[a, b]`, il aurait rejoué la demande `[a, b]`.
     */
    public function testUnObjetNePrendPasLEmpreinteDUneListe(): void
    {
        $liste = CleDIdempotence::empreinteDuCorps(['ops' => ['a', 'b']]);

        self::assertNotSame($liste, CleDIdempotence::empreinteDuCorps(['ops' => [1 => 'b', 0 => 'a']]));
        self::assertNotSame($liste, CleDIdempotence::empreinteDuCorps(['ops' => ['0' => 'a', 'x' => 'b']]));
        self::assertSame(
            hash('sha256', '{"kind":"ajout_note","ops":[{"a":1,"b":2}]}'),
            CleDIdempotence::empreinteDuCorps(['ops' => [['b' => 2, 'a' => 1]], 'kind' => 'ajout_note']),
            'Un corps ordinaire garde l’empreinte d’avant : les clés déjà posées en base restent valables.',
        );
    }

    /** `soumettre` absent vaut `true` au dépôt, mais le serveur ne le devine pas pour l'empreinte. */
    public function testUneValeurParDefautExpliciteChangeLEmpreinte(): void
    {
        $sans = ['kind' => 'ajout_note', 'message' => null];

        self::assertNotSame(
            CleDIdempotence::empreinteDuCorps($sans),
            CleDIdempotence::empreinteDuCorps($sans + ['soumettre' => true]),
        );
    }
}
