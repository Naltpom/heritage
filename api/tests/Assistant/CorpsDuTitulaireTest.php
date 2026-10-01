<?php

declare(strict_types=1);

namespace App\Tests\Assistant;

use App\Module\Assistant\Service\CorpsDuTitulaire;
use App\Module\Core\Exception\ValidationException;
use App\Module\Membership\Contract\DeclarationDuCompte;
use PHPUnit\Framework\TestCase;

/**
 * Le corps `creation_personne` du titulaire d'un compte « à rattacher » : l'identité
 * reprise de la déclaration, la filiation remplacée par les parents choisis, et les
 * personnes nouvelles qui ne tiennent plus à `#p1` retirées.
 */
final class CorpsDuTitulaireTest extends TestCase
{
    public function testLeCorpsMinimalPorteLaDeclarationEtAucunLien(): void
    {
        $corps = CorpsDuTitulaire::minimal(self::declaration());

        self::assertSame('creation_personne', $corps['kind']);
        self::assertSame([], $corps['liens']);
        self::assertSame('#p1', $corps['brouillons'][0]['localRef']);
        self::assertSame('Léa Marie', $corps['brouillons'][0]['givenNames']);
        self::assertFalse($corps['brouillons'][0]['deceased']);
        self::assertSame(
            ['raw' => '12 APR 1998', 'qualifier' => 'exacte', 'min' => '1998-04-12', 'max' => '1998-04-12', 'place' => null],
            $corps['brouillons'][0]['birth'],
        );
    }

    public function testLIdentiteDuModeleEstRemplaceeSaufLeSexeEtLeLieu(): void
    {
        $corps = CorpsDuTitulaire::imposerLIdentite([
            'kind' => 'creation_personne',
            'brouillons' => [[
                'localRef' => '#p1', 'givenNames' => 'Lea', 'surname' => 'DUPONT', 'nickname' => 'Lili',
                'sex' => 'feminin', 'deceased' => true,
                'birth' => ['raw' => '1999', 'qualifier' => 'annee', 'min' => '1999-01-01', 'max' => '1999-12-31', 'place' => 'Lille'],
                'death' => ['raw' => '2020', 'qualifier' => 'annee', 'min' => null, 'max' => null, 'place' => null],
            ]],
            'liens' => [],
        ], self::declaration());

        $p1 = $corps['brouillons'][0];
        self::assertSame(['Léa Marie', 'Dupont', null, 'feminin', false, null], [$p1['givenNames'], $p1['surname'], $p1['nickname'], $p1['sex'], $p1['deceased'], $p1['death']]);
        self::assertSame('Lille', $p1['birth']['place']);
        self::assertSame('1998-04-12', $p1['birth']['min']);
    }

    public function testLesParentsChoisisRemplacentLaFiliationEtRetirentLesFlottants(): void
    {
        $corps = CorpsDuTitulaire::avecLesParents([
            'kind' => 'creation_personne',
            'brouillons' => [['localRef' => '#p1'], ['localRef' => '#p2'], ['localRef' => '#p3'], ['localRef' => '#p4']],
            'liens' => [
                ['seq' => 1, 'kind' => 'enfant_de', 'subjectDraftRef' => '#p1', 'otherDraftRef' => '#p2'],
                ['seq' => 2, 'kind' => 'enfant_de', 'subjectDraftRef' => '#p2', 'otherPersonId' => 9],
                ['seq' => 3, 'kind' => 'conjoint_de', 'subjectDraftRef' => '#p1', 'otherDraftRef' => '#p3'],
                ['seq' => 4, 'kind' => 'enfant_de', 'subjectDraftRef' => '#p4', 'otherDraftRef' => '#p3'],
            ],
        ], [12, 57]);

        self::assertSame(['#p1', '#p3', '#p4'], array_column($corps['brouillons'], 'localRef'), '#p2 ne tient plus à #p1 ; #p4 y tient par #p3.');
        self::assertSame(
            [
                ['kind' => 'enfant_de', 'subjectDraftRef' => '#p1', 'otherPersonId' => 12, 'other2PersonId' => 57, 'seq' => 1],
                ['seq' => 2, 'kind' => 'conjoint_de', 'subjectDraftRef' => '#p1', 'otherDraftRef' => '#p3'],
                ['seq' => 3, 'kind' => 'enfant_de', 'subjectDraftRef' => '#p4', 'otherDraftRef' => '#p3'],
            ],
            $corps['liens'],
        );
    }

    public function testUnTitulaireDecedeOuAbsentEstRefuse(): void
    {
        foreach ([
            ['kind' => 'creation_personne', 'brouillons' => [['localRef' => '#p1', 'deceased' => true]]],
            ['kind' => 'creation_personne', 'brouillons' => [['localRef' => '#p2', 'deceased' => false]]],
            ['kind' => 'ajout_lien', 'liens' => []],
        ] as $corps) {
            try {
                CorpsDuTitulaire::verifier($corps);
                self::fail('Le corps aurait dû être refusé.');
            } catch (ValidationException $e) {
                self::assertSame('donnees_invalides', $e->codeErreur());
            }
        }

        CorpsDuTitulaire::verifier(CorpsDuTitulaire::minimal(self::declaration()));
        $this->addToAssertionCount(1);
    }

    private static function declaration(): DeclarationDuCompte
    {
        return new DeclarationDuCompte(14, 22, 'lea@example.net', 'Dupont', 'Léa Marie', new \DateTimeImmutable('1998-04-12'), str_repeat('Description. ', 6), null, null, null);
    }
}
