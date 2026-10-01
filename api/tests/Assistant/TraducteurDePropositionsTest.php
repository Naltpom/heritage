<?php

declare(strict_types=1);

namespace App\Tests\Assistant;

use App\Module\Assistant\Service\DocumentJoint;
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

    /**
     * « Ma cousine Jeanne, qui n'est pas dans l'arbre, a eu deux enfants » : une seule
     * demande, le parent nouveau rattaché à l'arbre, ses enfants rattachés à lui.
     */
    public function testUnePersonneNouvelleEtSesEnfantsNouveauxFontUneSeuleDemande(): void
    {
        $traduites = (new TraducteurDePropositions())->traduire([
            'resume' => 'Jeanne et ses deux enfants.',
            'demandes' => [[
                'nature' => 'creation_personne',
                'titre' => 'Ajouter Jeanne Sureau et ses enfants',
                'justification' => 'Texte du membre.',
                'confiance' => 'moyenne',
                'personnes' => [
                    ['ref' => '#p1', 'prenoms' => 'Jeanne', 'nom' => 'Sureau', 'sexe' => 'feminin'],
                    ['ref' => '#p2', 'prenoms' => 'Louis', 'nom' => 'Sureau', 'sexe' => 'masculin'],
                    ['ref' => '#p3', 'prenoms' => 'Rose', 'nom' => 'Sureau', 'sexe' => 'feminin'],
                ],
                'liens' => [
                    ['nature' => 'enfant_de', 'sujet' => '#p1', 'autre' => '17'],
                    ['nature' => 'enfant_de', 'sujet' => '#p2', 'autre' => '#p1'],
                    ['nature' => 'enfant_de', 'sujet' => 'p3', 'autre' => '#P1', 'autre2' => '18'],
                ],
            ]],
        ]);

        self::assertSame([], $traduites->avertissements);
        $corps = $traduites->propositions[0]->corps;
        self::assertSame(['#p1', '#p2', '#p3'], array_column($corps['brouillons'], 'localRef'));
        self::assertSame([
            ['seq' => 1, 'kind' => 'enfant_de', 'subjectDraftRef' => '#p1', 'otherPersonId' => 17],
            ['seq' => 2, 'kind' => 'enfant_de', 'subjectDraftRef' => '#p2', 'otherDraftRef' => '#p1'],
            ['seq' => 3, 'kind' => 'enfant_de', 'subjectDraftRef' => '#p3', 'otherDraftRef' => '#p1', 'other2PersonId' => 18],
        ], $corps['liens']);
    }

    /**
     * Les deux parents nommés en **deux** liens à un parent deviennent **un** lien à deux
     * parents — c'est la forme qui a donné deux familles « fantômes » à Véronique (469)
     * avant que la validation sache les réunir. `parent_de` est lu dans l'autre sens.
     */
    public function testDeuxFiliationsAUnParentSurLeMemeEnfantDeviennentUneFiliationADeuxParents(): void
    {
        $traduites = (new TraducteurDePropositions())->traduire([
            'resume' => 'Véronique, fille de Jean-Marie et d’Hélène.',
            'demandes' => [
                [
                    'nature' => 'creation_personne',
                    'titre' => 'Ajouter Véronique',
                    'personnes' => [['ref' => '#p1', 'prenoms' => 'Véronique', 'sexe' => 'feminin']],
                    'liens' => [
                        ['nature' => 'enfant_de', 'sujet' => '#p1', 'autre' => '450', 'note' => 'acte de naissance'],
                        ['nature' => 'conjoint_de', 'sujet' => '#p1', 'autre' => '12'],
                        ['nature' => 'parent_de', 'sujet' => '468', 'autre' => '#p1'],
                    ],
                ],
                [
                    'nature' => 'ajout_lien',
                    'titre' => 'Rattacher Véronique à ses parents',
                    'personId' => 469,
                    'liens' => [
                        ['nature' => 'enfant_de', 'sujet' => '469', 'autre' => '468'],
                        ['nature' => 'enfant_de', 'sujet' => '469', 'autre' => '450'],
                    ],
                ],
            ],
        ]);

        self::assertSame([], $traduites->avertissements);
        self::assertSame([
            ['seq' => 1, 'kind' => 'enfant_de', 'subjectDraftRef' => '#p1', 'otherPersonId' => 450, 'other2PersonId' => 468, 'note' => 'acte de naissance'],
            ['seq' => 2, 'kind' => 'conjoint_de', 'subjectDraftRef' => '#p1', 'otherPersonId' => 12],
        ], $traduites->propositions[0]->corps['liens']);
        self::assertSame([
            ['seq' => 1, 'kind' => 'enfant_de', 'subjectPersonId' => 469, 'otherPersonId' => 468, 'other2PersonId' => 450],
        ], $traduites->propositions[1]->corps['liens']);
    }

    /** Trois parents pour un même enfant : une question pour le membre, pas une fusion à deviner. */
    public function testTroisParentsPourUnMemeEnfantNeSeReunissentPas(): void
    {
        $traduites = (new TraducteurDePropositions())->traduire([
            'resume' => 'x',
            'demandes' => [[
                'nature' => 'ajout_lien',
                'titre' => 'Parents de 469',
                'personId' => 469,
                'liens' => [
                    ['nature' => 'enfant_de', 'sujet' => '469', 'autre' => '468'],
                    ['nature' => 'enfant_de', 'sujet' => '469', 'autre' => '450'],
                    ['nature' => 'enfant_de', 'sujet' => '469', 'autre' => '12'],
                ],
            ]],
        ]);

        self::assertCount(3, $traduites->propositions[0]->corps['liens']);
    }

    /** Un enfant qui vise le parent nouveau d'une **autre** demande : écarté, et le motif le dit. */
    public function testUneReferenceDUneAutreDemandeEstEcarteeAvecSonMotif(): void
    {
        $traduites = (new TraducteurDePropositions())->traduire([
            'resume' => 'x',
            'demandes' => [
                ['nature' => 'creation_personne', 'titre' => 'Ajouter Jeanne', 'personnes' => [['ref' => '#p1', 'prenoms' => 'Jeanne']], 'liens' => [['nature' => 'enfant_de', 'sujet' => '#p1', 'autre' => '17']]],
                ['nature' => 'creation_personne', 'titre' => 'Ajouter Louis', 'personnes' => [['ref' => '#p2', 'prenoms' => 'Louis']], 'liens' => [['nature' => 'enfant_de', 'sujet' => '#p2', 'autre' => '#p1']]],
            ],
        ]);

        self::assertCount(1, $traduites->propositions);
        self::assertStringContainsString('« Ajouter Louis » a été écartée : un de ses liens vise la personne nouvelle « #p1 », qu’elle ne décrit pas', $traduites->avertissements[0]);
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

    // ─────────────────────────────────────────────────────────────────────────────
    //  Les portraits
    // ─────────────────────────────────────────────────────────────────────────────

    public function testUnPortraitDUnePersonneExistanteGardeSonCadreEtPasseEnRang0(): void
    {
        $traduites = (new TraducteurDePropositions())->traduire([
            'resume' => 'Une photo de Marne.',
            'demandes' => [],
            'portraits' => [[
                'document' => 2.0,
                'personne' => '17',
                'cadre' => ['haut' => 120, 'gauche' => 340.0, 'bas' => '520', 'droite' => 660],
                'justification' => ' Photo légendée « Marne, 1950 », document 2. ',
            ]],
        ], self::documents());

        self::assertSame([], $traduites->avertissements);
        self::assertCount(1, $traduites->portraits);

        $portrait = $traduites->portraits[0];
        self::assertSame(1, $portrait->document, '« Document 2 » pour le modèle, rang 1 dans `fichiers[]`.');
        self::assertSame(['haut' => 120, 'gauche' => 340, 'bas' => 520, 'droite' => 660], $portrait->cadre);
        self::assertSame(17, $portrait->personId);
        self::assertNull($portrait->proposition);
        self::assertNull($portrait->brouillonRef);
        self::assertSame('Photo légendée « Marne, 1950 », document 2.', $portrait->justification);
    }

    public function testUnPortraitPeutViserLeBrouillonDUneCreationDeLaMemeReponse(): void
    {
        $traduites = (new TraducteurDePropositions())->traduire([
            'resume' => 'x',
            'demandes' => [
                ['nature' => 'modification_personne', 'titre' => 'Surnom', 'personId' => 17, 'changements' => [['champ' => 'nickname', 'texte' => 'Mimi']]],
                self::creation('Ajouter Paul', 'P1'),
                self::creation('Ajouter Jeanne', '#p2'),
            ],
            'portraits' => [
                // Désigné par la demande : la 2e (rang 1-based), référence « p1 » du modèle.
                ['document' => 1, 'personne' => '#p1', 'demande' => 2, 'cadre' => self::cadre(), 'justification' => 'Paul.'],
                // Sans demande : la seule création qui porte « #p2 » l'emporte — et c'est
                // son premier brouillon, donc « #p1 » canonique.
                ['document' => 1, 'personne' => 'P2', 'cadre' => self::cadre(), 'justification' => 'Jeanne.'],
            ],
        ], self::documents());

        self::assertSame([], $traduites->avertissements);
        self::assertCount(2, $traduites->portraits);

        self::assertNull($traduites->portraits[0]->personId);
        self::assertSame(1, $traduites->portraits[0]->proposition, 'Le rang de la proposition traduite, pas celui de la demande brute.');
        self::assertSame('#p1', $traduites->portraits[0]->brouillonRef);

        self::assertSame(2, $traduites->portraits[1]->proposition);
        self::assertSame('#p1', $traduites->portraits[1]->brouillonRef, 'La référence canonique du corps, pas celle du modèle.');
    }

    public function testUnPortraitInvalideEstEcarteEtDit(): void
    {
        $traduites = (new TraducteurDePropositions())->traduire([
            'resume' => 'x',
            'demandes' => [
                // Écartée à la traduction : son lien ne désigne personne.
                ['nature' => 'creation_personne', 'titre' => 'Ajouter Paul', 'personnes' => [['ref' => '#p1', 'prenoms' => 'Paul']], 'liens' => [['nature' => 'enfant_de', 'sujet' => '#p1', 'autre' => 'le père']]],
                self::creation('Ajouter Jeanne', '#p1'),
                self::creation('Ajouter Luc', '#p1'),
            ],
            'portraits' => [
                ['document' => 1, 'personne' => '#p1', 'demande' => 1, 'cadre' => self::cadre(), 'justification' => 'Brouillon d’une création écartée.'],
                ['document' => 1, 'personne' => '#p1', 'cadre' => self::cadre(), 'justification' => 'Deux créations portent #p1 : ambigu.'],
                ['document' => 3, 'personne' => 17, 'cadre' => self::cadre(), 'justification' => 'Un PDF.'],
                ['document' => 4, 'personne' => 17, 'cadre' => self::cadre(), 'justification' => 'Pas de document 4.'],
                ['document' => 1, 'personne' => 17, 'cadre' => ['haut' => 500, 'gauche' => 100, 'bas' => 500, 'droite' => 300], 'justification' => 'Cadre vide.'],
                ['document' => 1, 'personne' => 17, 'cadre' => ['haut' => 0, 'gauche' => 100, 'bas' => 1200, 'droite' => 300], 'justification' => 'Cadre hors repère.'],
                ['document' => 1, 'personne' => 17, 'cadre' => ['haut' => 0, 'gauche' => 100, 'bas' => 300], 'justification' => 'Cadre incomplet.'],
                ['document' => 1, 'personne' => 'la mariée', 'cadre' => self::cadre(), 'justification' => 'Personne non désignée.'],
                ['document' => 1, 'personne' => 17, 'cadre' => self::cadre(), 'justification' => 'Le bon.'],
                ['document' => 2, 'personne' => 17.0, 'cadre' => self::cadre(), 'justification' => 'Un second pour la même personne.'],
            ],
        ], self::documents());

        self::assertCount(1, $traduites->portraits, 'Seul « Le bon » passe.');
        self::assertSame('Le bon.', $traduites->portraits[0]->justification);

        $portraits = \array_slice($traduites->avertissements, 1);
        self::assertCount(9, $portraits);
        self::assertStringContainsString('aucune demande retenue', $portraits[0]);
        self::assertStringContainsString('plusieurs demandes', $portraits[1]);
        self::assertStringContainsString('(document 3) a été écarté : un portrait ne se tire que d’une photo, jamais d’un PDF', $portraits[2]);
        self::assertStringContainsString('aucun document joint', $portraits[3]);
        self::assertStringContainsString('cadre est vide', $portraits[4]);
        self::assertStringContainsString('sort de l’image', $portraits[5]);
        self::assertStringContainsString('incomplet', $portraits[6]);
        self::assertStringContainsString('aucune personne', $portraits[7]);
        self::assertStringContainsString('un seul portrait par personne', $portraits[8]);
    }

    public function testSansPortraitsLaListeEstVide(): void
    {
        $traduites = (new TraducteurDePropositions())->traduire(['resume' => 'x', 'demandes' => []]);

        self::assertSame([], $traduites->portraits);
    }

    /**
     * Deux photos puis un PDF — dans l'ordre de `fichiers[]`.
     *
     * @return list<DocumentJoint>
     */
    private static function documents(): array
    {
        return [
            new DocumentJoint('image/jpeg', "\xFF\xD8\xFF", 'mariage.jpg'),
            new DocumentJoint('image/png', "\x89PNG", 'portrait.png'),
            new DocumentJoint('application/pdf', '%PDF-', 'acte.pdf'),
        ];
    }

    /** @return array{haut: int, gauche: int, bas: int, droite: int} */
    private static function cadre(): array
    {
        return ['haut' => 100, 'gauche' => 200, 'bas' => 400, 'droite' => 500];
    }

    /** @return array<string, mixed> */
    private static function creation(string $titre, string $reference): array
    {
        return [
            'nature' => 'creation_personne', 'titre' => $titre, 'justification' => 'Photo.', 'confiance' => 'moyenne',
            'personnes' => [['ref' => $reference, 'prenoms' => 'X']],
            'liens' => [['nature' => 'enfant_de', 'sujet' => $reference, 'autre' => '17']],
        ];
    }
}
