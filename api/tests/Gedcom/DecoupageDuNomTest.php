<?php

declare(strict_types=1);

namespace App\Tests\Gedcom;

use App\Module\Gedcom\Service\DecoupageDuNom;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * La syntaxe `NAME` de la norme GEDCOM, et rien d'autre.
 *
 * C'est le seul morceau de l'ancien `NormalisateurNom` resté dans `Gedcom` : la
 * composition de `display_name` et de `search_key` appartient à `Tree`, qui possède ces
 * colonnes, et le repliement des accents à `Core`, qui l'unifie entre celui qui écrit et
 * celui qui cherche.
 */
final class DecoupageDuNomTest extends TestCase
{
    /**
     * @return iterable<string, array{0: ?string, 1: string, 2: string, 3: string}>
     */
    public static function nomsGedcom(): iterable
    {
        yield 'forme complète' => ['Jade Pyrite /Romarin/', 'Jade Pyrite', 'Romarin', ''];
        yield 'avec suffixe' => ['Jade /Romarin/ Jr', 'Jade', 'Romarin', 'Jr'];
        yield 'sans prénom' => [' /Romarin/', '', 'Romarin', ''];
        // Les 4 individus sans nom du fichier réel : `1 NAME  //`. Données valides.
        yield 'sans rien' => [' //', '', '', ''];
        yield 'sans barres obliques' => ['Romarin', 'Romarin', '', ''];
        yield 'absent' => [null, '', '', ''];
    }

    #[DataProvider('nomsGedcom')]
    public function testLeDecoupageSuitLesBarresObliques(?string $name, string $prenoms, string $nom, string $suffixe): void
    {
        $decoupe = (new DecoupageDuNom())->decouper($name);

        self::assertSame($prenoms, $decoupe['prenoms']);
        self::assertSame($nom, $decoupe['nom']);
        self::assertSame($suffixe, $decoupe['suffixe']);
    }

    /** Un nom de famille composé garde ses espaces : `/De le Nigelle/` est un seul nom. */
    public function testUnNomComposeResteEntier(): void
    {
        self::assertSame('De le Nigelle', (new DecoupageDuNom())->decouper('Jade /De le Nigelle/')['nom']);
    }
}
