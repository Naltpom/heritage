<?php

declare(strict_types=1);

namespace App\Tests\Assistant;

use App\Module\Assistant\Service\TraducteurDePropositions;
use PHPUnit\Framework\TestCase;

/**
 * Les arguments de `proposer` → des corps à la forme de `POST /api/demandes`.
 *
 * Le traducteur ne juge pas la recevabilité (c'est `Proposal`, éprouvé en HTTP par
 * {@see AssistantHttpTest}) : il garantit que ce qui sort a **la forme** d'un
 * `CorpsDemande` du front (`app/src/lib/api/propositions.ts`), et que ce qui ne peut pas
 * l'avoir est écarté **avec un avertissement**, jamais réparé en devinant.
 */
final class TraducteurDePropositionsTest extends TestCase
{
    public function testUneModificationDevientDesOpsDeChampSansLesVerrous(): void
    {
        $traduites = (new TraducteurDePropositions())->traduire([
            'resume' => 'Acte de naissance de Marne.',
            'demandes' => [[
                'nature' => 'modification_personne',
                'titre' => 'Préciser la naissance de Marne',
                'justification' => 'Document 1.',
                'confiance' => 'haute',
                'personId' => 17.0,
                'changements' => [
                    ['champ' => 'birth', 'date' => ['qualificatif' => 'exacte', 'date' => '1987-05-04', 'lieu' => 'Brest']],
                    ['champ' => 'nickname', 'texte' => ' Mimi '],
                    ['champ' => 'deceased', 'booleen' => true],
                    // Hors liste blanche : ignoré, jamais transmis.
                    ['champ' => 'searchKey', 'texte' => 'X'],
                    // Valeur absente : ignorée.
                    ['champ' => 'surname'],
                ],
            ]],
        ]);

        self::assertSame('Acte de naissance de Marne.', $traduites->resume);
        self::assertCount(1, $traduites->propositions);

        $corps = $traduites->propositions[0]->corps;
        self::assertSame('modification_personne', $corps['kind']);
        self::assertSame('personne', $corps['targetKind']);
        self::assertSame(17, $corps['targetId'], '`17.0` est la fiche 17 : Gemini rend parfois ses entiers en flottants.');
        self::assertSame(['birth', 'nickname', 'deceased'], array_column($corps['ops'], 'field'));

        foreach ($corps['ops'] as $op) {
            // Les deux verrous de l'ADR 0004 ne viennent JAMAIS du modèle : `Proposal` les
            // lit en base au moment de l'analyse.
            self::assertArrayNotHasKey('oldValue', $op);
            self::assertArrayNotHasKey('baseVersion', $op);
            self::assertSame('champ', $op['opKind']);
            self::assertSame(17, $op['subjectId']);
        }

        self::assertSame(
            ['raw' => '4 MAY 1987', 'qualifier' => 'exacte', 'min' => '1987-05-04', 'max' => '1987-05-04', 'place' => 'Brest'],
            $corps['ops'][0]['newValue'],
        );
        self::assertSame('Mimi', $corps['ops'][1]['newValue']);
        self::assertTrue($corps['ops'][2]['newValue']);
    }

    public function testUneCreationPorteDesBrouillonsCanoniquesEtDesLiensVersLArbre(): void
    {
        $traduites = (new TraducteurDePropositions())->traduire([
            'resume' => 'Naissance de Paul.',
            'demandes' => [[
                'nature' => 'creation_personne',
                'titre' => 'Ajouter Paul Sureau',
                'justification' => 'Acte, document 1.',
                'confiance' => 'moyenne',
                'personnes' => [[
                    // Le modèle écrit « P1 » ; le corps porte « #p1 ».
                    'ref' => 'P1',
                    'prenoms' => 'Paul',
                    'nom' => 'Sureau',
                    'sexe' => 'masculin',
                    'naissance' => ['qualificatif' => 'annee', 'date' => '2012'],
                    'deces' => ['qualificatif' => 'environ', 'date' => '2020'],
                ]],
                'liens' => [
                    ['nature' => 'enfant_de', 'sujet' => '#p1', 'autre' => '17', 'autre2' => '18'],
                ],
            ]],
        ]);

        $corps = $traduites->propositions[0]->corps;

        self::assertSame('creation_personne', $corps['kind']);
        self::assertSame([
            'localRef' => '#p1',
            'givenNames' => 'Paul',
            'surname' => 'Sureau',
            'marriedName' => null,
            'nickname' => null,
            'sex' => 'masculin',
            // Une date de décès attestée coche la case : sans elle, le dépôt refuserait
            // la fiche (« une date de décès implique le décès »).
            'deceased' => true,
            'birth' => ['raw' => '2012', 'qualifier' => 'annee', 'min' => '2012-01-01', 'max' => '2012-12-31', 'place' => null],
            'death' => ['raw' => 'ABT 2020', 'qualifier' => 'environ', 'min' => '2020-01-01', 'max' => '2020-12-31', 'place' => null],
            'note' => null,
        ], $corps['brouillons'][0]);
        self::assertSame(
            [['seq' => 1, 'kind' => 'enfant_de', 'subjectDraftRef' => '#p1', 'otherPersonId' => 17, 'other2PersonId' => 18]],
            $corps['liens'],
        );
    }

    public function testUnAjoutDeLienNeCreeAucuneFicheEtAnnonceSaCible(): void
    {
        $traduites = (new TraducteurDePropositions())->traduire([
            'resume' => '',
            'demandes' => [[
                'nature' => 'ajout_lien',
                'titre' => 'Marier Marne et Tuf',
                'justification' => 'Livret de famille.',
                'confiance' => 'haute',
                'personId' => 17,
                'liens' => [[
                    'nature' => 'conjoint_de', 'sujet' => '17', 'autre' => 18,
                    'mariage' => ['qualificatif' => 'exacte', 'date' => '2010-06-12', 'lieu' => 'Quimper'],
                ]],
            ]],
        ]);

        $corps = $traduites->propositions[0]->corps;

        self::assertSame('ajout_lien', $corps['kind']);
        self::assertSame(17, $corps['targetId']);
        self::assertArrayNotHasKey('brouillons', $corps);
        self::assertSame(17, $corps['liens'][0]['subjectPersonId']);
        self::assertSame(18, $corps['liens'][0]['otherPersonId']);
        self::assertSame('12 JUN 2010', $corps['liens'][0]['marriage']['raw']);
    }

    public function testCeQuiNePeutPasEtreTraduitEstEcarteEtDit(): void
    {
        $traduites = (new TraducteurDePropositions())->traduire([
            'resume' => 'x',
            'avertissements' => ['La photo 2 est floue.'],
            'demandes' => [
                ['nature' => 'suppression_personne', 'titre' => 'Supprimer Tuf', 'personId' => 18],
                ['nature' => 'modification_personne', 'titre' => 'Sans cible', 'changements' => [['champ' => 'nickname', 'texte' => 'Mimi']]],
                ['nature' => 'creation_personne', 'titre' => 'Lien vers personne', 'personnes' => [['ref' => '#p1', 'prenoms' => 'Paul']], 'liens' => [['nature' => 'enfant_de', 'sujet' => '#p1', 'autre' => 'le père de Marne']]],
                ['nature' => 'ajout_lien', 'titre' => 'Frère et sœur', 'personId' => 17, 'liens' => [['nature' => 'frere_de', 'sujet' => '17', 'autre' => '18']]],
            ],
        ]);

        self::assertSame([], $traduites->propositions, 'Aucune de ces quatre propositions n’a une forme de demande.');
        self::assertCount(5, $traduites->avertissements);
        self::assertSame('La photo 2 est floue.', $traduites->avertissements[0], 'Les avertissements du modèle passent en premier.');
        self::assertStringContainsString('« Supprimer Tuf » a été écartée', $traduites->avertissements[1], 'Jamais de suppression par l’assistant.');
        self::assertStringContainsString('« Sans cible » a été écartée', $traduites->avertissements[2]);
        self::assertStringContainsString('introuvable', $traduites->avertissements[3], 'Une extrémité qui n’est ni #p ni un identifiant n’est pas devinée.');
        self::assertStringContainsString('nature inconnue', $traduites->avertissements[4]);
    }
}
