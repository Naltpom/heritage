<?php

declare(strict_types=1);

namespace App\Tests\Proposal;

use App\Module\Core\Enum\SubjectKind;
use App\Module\Proposal\Entity\ChangeRequest;
use App\Module\Proposal\Service\LimiteurDesDoublons;

/**
 * ═══════════════════════════════════════════════════════════════════════════════
 * « CETTE PERSONNE EST DÉJÀ EN COURS DE VALIDATION » — les demandes, vues avant dépôt.
 *
 * Le besoin du commanditaire : *« vérifier si doublon soit dans l'arbre soit déjà en
 * demande […] est-ce bien une nouvelle personne, ou souhaites-tu mettre à jour la demande
 * existante, ou ne rien faire »*. Ce fichier tient la moitié « déjà en demande » et le
 * geste « mettre à jour » ; la moitié « dans l'arbre », qui est celle du cercle, est
 * éprouvée sur un vrai arbre par `Tree\DoublonsProbablesDansLArbreTest`.
 *
 * ── La frontière que ces tests gardent ──────────────────────────────────────────
 *
 * | Demande en attente | Ce que le lecteur reçoit |
 * |---|---|
 * | **la sienne** | la fiche, la demande, ses liens — de quoi la compléter |
 * | **celle d'un autre**, personne décédée | `enCoursAilleurs: true`, **rien d'autre** |
 * | **celle d'un autre**, personne vivante | rien du tout |
 *
 * Le contenu d'une demande en attente n'appartient qu'à son auteur et aux
 * administrateurs (ADR 0004, § 13.3). Le drapeau ne porte ni identifiant, ni auteur, ni
 * nombre — et un test le vérifie **sur la réponse brute**, pas sur les clés qu'on
 * s'attend à trouver.
 */
final class DoublonsAvantDepotEnHttpTest extends CasDeTestDemandesHttp
{
    private const int JARGON = 55;

    protected function setUp(): void
    {
        parent::setUp();

        $this->arbre()->poser(SubjectKind::Personne, self::JARGON, 'Jargon Sarriette', 3, [
            'givenNames' => 'Jargon',
            'surname' => 'Sarriette',
            'marriedName' => null,
            'nickname' => null,
            'sex' => 'masculin',
            'deceased' => true,
            'forcePrivacy' => false,
            'privacyNote' => null,
        ]);

        $this->creerContributeur('opale@heritage.test', 101, 'Opale Romarin');
        $this->creerContributeur('tuf@heritage.test', 102, 'Tuf Romarin');
    }

    public function testMaPropreDemandeEnAttenteMEstRendueEnEntier(): void
    {
        $this->connexion('opale@heritage.test');
        $id = $this->proposerBarnabe();

        $this->requete('POST', '/api/demandes/doublons-probables', $this->corpsBarnabe(naissance: '1903'));
        self::assertResponseIsSuccessful();

        $charge = $this->charge();
        self::assertSame(['personnes', 'mesDemandes', 'enCoursAilleurs'], array_keys($charge));
        self::assertSame([], $charge['personnes'], 'L’arbre de ce socle est vide : rien à y rapprocher.');
        self::assertFalse($charge['enCoursAilleurs'], 'Sa propre demande n’est pas « ailleurs ».');

        self::assertIsArray($charge['mesDemandes']);
        self::assertCount(1, $charge['mesDemandes']);
        $doublon = $charge['mesDemandes'][0];
        self::assertSame($id, $doublon['demandeId']);
        self::assertSame('#p1', $doublon['localRef']);
        self::assertSame('Barnabé Muguet', $doublon['displayName']);
        self::assertSame('vers 1901', $doublon['birth']['texte']);
        self::assertSame('en_attente', $doublon['status']);
        self::assertContains('naissance', $doublon['criteres'], '« vers 1901 » recouvre 1903 : la tolérance d’un « vers ».');
        self::assertCount(1, $doublon['liens']);
        self::assertSame(self::JARGON, $doublon['liens'][0]['otherPersonId']);
    }

    public function testUneAnneeIncompatibleNeRapprochePas(): void
    {
        $this->connexion('opale@heritage.test');
        $this->proposerBarnabe();

        // Un homonyme né trente ans plus tard : le père et le fils, le cas le plus courant.
        $this->requete('POST', '/api/demandes/doublons-probables', $this->corpsBarnabe(naissance: '1931'));
        self::assertResponseIsSuccessful();
        self::assertSame([], $this->charge()['mesDemandes']);
    }

    /**
     * **La règle de divulgation.** Un autre membre apprend qu'une proposition semblable
     * attend — et rien d'autre : ni le numéro de la demande, ni son auteur, ni ce qu'elle
     * dit. Vérifié sur la réponse brute.
     */
    public function testLaDemandeDUnAutreNeDitQuUnDrapeau(): void
    {
        $this->connexion('opale@heritage.test');
        $id = $this->proposerBarnabe(note: 'Registre paroissial de Saule, cote 4E12.');
        $this->deconnexion();

        $this->connexion('tuf@heritage.test');
        $this->requete('POST', '/api/demandes/doublons-probables', $this->corpsBarnabe(naissance: '1902'));
        self::assertResponseIsSuccessful();

        $charge = $this->charge();
        self::assertSame([], $charge['mesDemandes']);
        self::assertTrue($charge['enCoursAilleurs']);

        $brut = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString('Opale', $brut, 'L’auteur d’une demande ne sort pas.');
        self::assertStringNotContainsString('4E12', $brut, 'Le contenu d’une demande ne sort pas.');
        self::assertStringNotContainsString('"demandeId"', $brut);
        self::assertStringNotContainsString((string) $id, $brut, 'Le numéro de la demande ne sort pas.');
    }

    /**
     * Une personne **vivante** proposée par un autre ne lève même pas le drapeau : il
     * s'allumerait sur la bonne année de naissance, et serait l'oracle que la réciprocité
     * des vivants refuse (ADR 0016).
     */
    public function testLaDemandeDUnAutreSurUnVivantNeLevePasLeDrapeau(): void
    {
        $this->connexion('opale@heritage.test');
        $this->proposerBarnabe(decede: false, naissance: ['raw' => '1985', 'qualifier' => 'annee', 'min' => '1985-01-01', 'max' => '1985-12-31', 'place' => null]);
        $this->deconnexion();

        $this->connexion('tuf@heritage.test');
        $this->requete('POST', '/api/demandes/doublons-probables', $this->corpsBarnabe(naissance: '1985', decede: false));
        self::assertResponseIsSuccessful();
        self::assertFalse($this->charge()['enCoursAilleurs']);
        self::assertSame([], $this->charge()['mesDemandes']);
    }

    public function testUneSaisieQueLeDepotRefuseraitRendLaMemeErreur(): void
    {
        $this->connexion('opale@heritage.test');

        $corps = $this->corpsBarnabe(naissance: '1903');
        $corps['brouillons'][0]['givenNames'] = '';
        $corps['brouillons'][0]['surname'] = '';

        $this->requete('POST', '/api/demandes/doublons-probables', $corps);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('donnees_invalides', $this->erreur()['code']);
    }

    public function testLaRouteEstPlafonnee(): void
    {
        $this->connexion('opale@heritage.test');

        for ($i = 0; $i < LimiteurDesDoublons::REQUETES_PAR_COMPTE; ++$i) {
            $this->requete('POST', '/api/demandes/doublons-probables', $this->corpsBarnabe(naissance: '1903'));
            self::assertResponseIsSuccessful();
        }

        $this->requete('POST', '/api/demandes/doublons-probables', $this->corpsBarnabe(naissance: '1903'));
        self::assertResponseStatusCodeSame(429);
        self::assertSame('trop_de_tentatives', $this->erreur()['code']);
    }

    // ─────────────────────────────────────────────────────────────────────────────
    //  « Mettre à jour la demande existante »
    // ─────────────────────────────────────────────────────────────────────────────

    /**
     * Ce qui est saisi complète ; ce qui est laissé vide ne retire rien. La note
     * s'ajoute, la filiation saisie remplace, la clé de doublon suit le nouveau nom.
     */
    public function testCompleterSaDemandeAjouteCeQuiEstSaisiEtNeRetireRien(): void
    {
        $this->connexion('opale@heritage.test');
        $id = $this->proposerBarnabe(note: 'Acte de naissance.');

        $corps = $this->corpsBarnabe(naissance: '1902', exacte: '1902-03-12');
        $corps['brouillons'][0]['marriedName'] = 'Sarriette';
        $corps['brouillons'][0]['note'] = 'Livret de famille, p. 2.';
        $corps['brouillons'][0]['sex'] = 'inconnu';
        $corps['message'] = 'J’ai retrouvé le livret.';
        // Une union, qui s'ajoute à la filiation existante.
        $corps['liens'][] = ['seq' => 2, 'kind' => 'conjoint_de', 'subjectDraftRef' => '#p1', 'otherPersonId' => self::PERSONNE, 'divorced' => false];

        $this->requete('POST', '/api/demandes/'.$id.'/brouillons/p1/completer', $corps);
        self::assertResponseIsSuccessful();

        $demande = $this->charge();
        self::assertSame($id, $demande['id']);
        self::assertSame('en_attente', $demande['status'], 'La demande reste dans la file, à sa place.');
        $fiche = $demande['brouillons'][0];
        self::assertSame('Sarriette', $fiche['marriedName']);
        self::assertSame('masculin', $fiche['sex'], 'Un sexe laissé « inconnu » ne retire pas celui de la fiche.');
        self::assertSame('12 mars 1902', $fiche['birth']['texte']);
        self::assertSame('Saule, Ombrage, Sylvanie', $fiche['birth']['place'], 'Une date sans lieu garde le lieu déjà connu.');
        self::assertSame("Acte de naissance.\n\nLivret de famille, p. 2.", $fiche['note']);
        self::assertSame("Barnabé, fils de Jargon.\n\nJ’ai retrouvé le livret.", $demande['message']);

        self::assertCount(2, $demande['liens'], 'La filiation identique ne se double pas ; l’union s’ajoute.');
        self::assertSame(['enfant_de', 'conjoint_de'], array_column($demande['liens'], 'kind'));

        $enBase = $this->em()->getRepository(ChangeRequest::class)->find($id);
        self::assertNotNull($enBase);
        self::assertSame('muguet|barnabe|1902', $enBase->getDedupKey(), 'La clé de doublon suit la fiche complétée.');
    }

    public function testCompleterLaDemandeDUnAutreEstRefuse(): void
    {
        $this->connexion('opale@heritage.test');
        $id = $this->proposerBarnabe();
        $this->deconnexion();

        $this->connexion('tuf@heritage.test');
        $this->requete('POST', '/api/demandes/'.$id.'/brouillons/p1/completer', $this->corpsBarnabe(naissance: '1903'));
        self::assertResponseStatusCodeSame(403);
        self::assertSame('acces_refuse', $this->erreur()['code']);
    }

    public function testUneDemandeRetireeNeSeCompletePlus(): void
    {
        $this->connexion('opale@heritage.test');
        $id = $this->proposerBarnabe();

        $this->requete('POST', '/api/demandes/'.$id.'/retirer');
        self::assertResponseIsSuccessful();

        $this->requete('POST', '/api/demandes/'.$id.'/brouillons/p1/completer', $this->corpsBarnabe(naissance: '1903'));
        self::assertResponseStatusCodeSame(409);
        self::assertSame('demande_deja_decidee', $this->erreur()['code']);
    }

    public function testUneFicheInconnueDeLaDemandeRend404(): void
    {
        $this->connexion('opale@heritage.test');
        $id = $this->proposerBarnabe();

        $this->requete('POST', '/api/demandes/'.$id.'/brouillons/p7/completer', $this->corpsBarnabe(naissance: '1903'));
        self::assertResponseStatusCodeSame(404);
    }

    public function testUneDateDeDecesSansDecesResteRefuseeApresComplement(): void
    {
        $this->connexion('opale@heritage.test');
        $id = $this->proposerBarnabe(decede: false, naissance: null);

        $corps = $this->corpsBarnabe(naissance: '1903', decede: false);
        $corps['brouillons'][0]['death'] = ['raw' => '1970', 'qualifier' => 'annee', 'min' => '1970-01-01', 'max' => '1970-12-31', 'place' => null];

        $this->requete('POST', '/api/demandes/'.$id.'/brouillons/p1/completer', $corps);
        self::assertResponseStatusCodeSame(422);
    }

    // ─────────────────────────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed>|null $naissance
     */
    private function proposerBarnabe(
        bool $decede = true,
        ?array $naissance = ['raw' => 'ABT 1901', 'qualifier' => 'environ', 'min' => '1901-01-01', 'max' => '1901-12-31', 'place' => 'Saule, Ombrage, Sylvanie'],
        ?string $note = null,
    ): int {
        $this->requete('POST', '/api/demandes', [
            'kind' => 'creation_personne',
            'message' => 'Barnabé, fils de Jargon.',
            'soumettre' => true,
            'brouillons' => [[
                'localRef' => '#p1',
                'givenNames' => 'Barnabé',
                'surname' => 'Muguet',
                'sex' => 'masculin',
                'deceased' => $decede,
                'birth' => $naissance,
                'note' => $note,
            ]],
            'liens' => [
                ['seq' => 1, 'kind' => 'enfant_de', 'subjectDraftRef' => '#p1', 'otherPersonId' => self::JARGON],
            ],
        ]);

        self::assertResponseStatusCodeSame(201);
        $id = $this->charge()['id'];
        self::assertIsInt($id);

        return $id;
    }

    /**
     * Le corps que le formulaire de création enverrait pour Barnabé, à l'année près.
     *
     * @return array{kind: string, soumettre: bool, brouillons: list<array<string, mixed>>, liens: list<array<string, mixed>>, message?: string}
     */
    private function corpsBarnabe(string $naissance, bool $decede = true, ?string $exacte = null): array
    {
        $date = null === $exacte
            ? ['raw' => $naissance, 'qualifier' => 'annee', 'min' => $naissance.'-01-01', 'max' => $naissance.'-12-31', 'place' => null]
            : ['raw' => '12 MAR '.mb_substr($exacte, 0, 4), 'qualifier' => 'exacte', 'min' => $exacte, 'max' => $exacte, 'place' => null];

        return [
            'kind' => 'creation_personne',
            'soumettre' => true,
            'brouillons' => [[
                'localRef' => '#p1',
                'givenNames' => 'Barnabé',
                'surname' => 'Muguet',
                'marriedName' => null,
                'nickname' => null,
                'sex' => 'masculin',
                'deceased' => $decede,
                'birth' => $date,
                'death' => null,
                'note' => null,
            ]],
            'liens' => [
                ['seq' => 1, 'kind' => 'enfant_de', 'subjectDraftRef' => '#p1', 'otherPersonId' => self::JARGON],
            ],
        ];
    }
}
