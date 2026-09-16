<?php

declare(strict_types=1);

namespace App\Tests\Media;

use App\Module\Core\Enum\DateQualifier;
use App\Module\Core\Exception\DomainException;
use App\Module\Media\Http\BlocDate;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * La date de prise de vue d'une preuve : un bloc à **cinq clés**, jamais une chaîne.
 *
 * « Une photo d'archive est datée, souvent approximativement — mêmes règles que partout
 * ailleurs, pas d'exception. » Les médias n'ont aucun privilège sur les fourchettes.
 */
final class BlocDateTest extends TestCase
{
    public function testUneFourchetteTraverseSansSAplatir(): void
    {
        $date = BlocDate::depuisJson(json_encode([
            'raw' => 'BET 1901 AND 1909',
            'qualifier' => 'entre',
            'min' => '1901-01-01',
            'max' => '1909-12-31',
            'place' => 'Saule, Ombrage, Sylvanie',
        ], \JSON_THROW_ON_ERROR));

        self::assertNotNull($date);
        self::assertSame(DateQualifier::Entre, $date->getQualifier());
        self::assertSame('1901-01-01', $date->getMin()?->format('Y-m-d'));
        self::assertSame('1909-12-31', $date->getMax()?->format('Y-m-d'));
        // La chaîne GEDCOM verbatim est conservée : c'est la provenance, jamais
        // reconstruite, jamais réécrite.
        self::assertSame('BET 1901 AND 1909', $date->getRaw());
    }

    public function testUnBlocAbsentResteAbsent(): void
    {
        self::assertNull(BlocDate::depuisJson(null));
        self::assertNull(BlocDate::depuisJson('   '));
    }

    public function testLaCleTexteEstRefuseeEnEntree(): void
    {
        // « Un back qui accepte `texte` en entrée accepte une sixième clé que la
        // comparaison JSONB de la détection de conflit fera diverger. » C'est le front
        // qui doit la retirer du bloc qu'il a reçu d'une fiche, et ce refus est ce qui
        // le lui apprend.
        $this->attendreUn422(json_encode([
            'raw' => null, 'qualifier' => 'annee', 'min' => '1899-01-01',
            'max' => '1899-12-31', 'place' => null, 'texte' => '1899',
        ], \JSON_THROW_ON_ERROR));
    }

    #[DataProvider('blocsInvalides')]
    public function testLesBlocsIncoherentsSontRefusesAvantLaBase(string $json): void
    {
        // Le `CHECK` de PostgreSQL est le filet, pas le contrôle : une violation de
        // contrainte remonterait en `500`, c'est-à-dire en panne, là où c'est une saisie.
        $this->attendreUn422($json);
    }

    /** @return iterable<string, array{string}> */
    public static function blocsInvalides(): iterable
    {
        yield 'qualificatif inconnu' => ['{"raw":null,"qualifier":"peut_etre","min":null,"max":null,"place":null}'];
        yield 'fourchette sans bornes' => ['{"raw":null,"qualifier":"entre","min":null,"max":null,"place":null}'];
        yield 'fourchette plate' => ['{"raw":null,"qualifier":"entre","min":"1899-01-01","max":"1899-01-01","place":null}'];
        yield 'fourchette inversée' => ['{"raw":null,"qualifier":"entre","min":"1899-12-31","max":"1899-01-01","place":null}'];
        yield 'inconnue mais bornée' => ['{"raw":null,"qualifier":"inconnue","min":"1899-01-01","max":null,"place":null}'];
        yield 'bornes inversées' => ['{"raw":null,"qualifier":"annee","min":"1900-01-01","max":"1899-12-31","place":null}'];
        yield 'horodatage' => ['{"raw":null,"qualifier":"exacte","min":"1899-01-01T00:00:00Z","max":null,"place":null}'];
        yield 'date impossible' => ['{"raw":null,"qualifier":"exacte","min":"1899-02-30","max":null,"place":null}'];
        yield 'json cassé' => ['{"qualifier":'];
        yield 'pas un objet' => ['"1899"'];
    }

    private function attendreUn422(string $json): void
    {
        try {
            BlocDate::depuisJson($json);
            self::fail('Ce bloc date aurait dû être refusé.');
        } catch (DomainException $erreur) {
            self::assertSame(422, $erreur->statutHttp());
            self::assertSame('donnees_invalides', $erreur->codeErreur());
            self::assertArrayHasKey('taken', (array) $erreur->champs());
        }
    }
}
