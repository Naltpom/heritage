<?php

declare(strict_types=1);

namespace App\Tests\Proposal;

use App\Module\Core\Entity\User;
use Symfony\Component\HttpFoundation\Response;

/**
 * La complétion sur place, le complément et la fusion, **traversés pour de vrai** :
 * pare-feu, voter, lecture du corps, enveloppe d'erreur, et la bascule « voir comme un
 * membre » posée par sa vraie route (ADR 0017).
 *
 * Les règles sont éprouvées au niveau des services ({@see CompletionDePropositionTest},
 * {@see ComplementDePropositionTest}, {@see FusionDeComplementTest}). Ce qui ne s'éprouve
 * qu'ici : les codes de statut, la forme exacte des charges — le front les consomme telles
 * quelles —, et que les gardes s'exécutent bien dans une requête.
 */
final class CompletionEtFusionEnHttpTest extends CasDeTestDemandesHttp
{
    private const int PERSONNE_OPALE = 63;

    private const int PERSONNE_TUF = 64;

    /** Tuf, l'auteur des compléments : la bascule l'emprunte par son rattachement. */
    private User $tuf;

    protected function setUp(): void
    {
        parent::setUp();

        $this->creerContributeur('opale@example.net', self::PERSONNE_OPALE, 'Opale Romarin');
        $this->tuf = $this->creerContributeur('tuf@example.net', self::PERSONNE_TUF, 'Tuf Romarin');
        $this->creerUtilisateur('natron@example.net', roles: ['ROLE_ADMIN'], nomAffiche: 'Natron Romarin');
    }

    // ---------------------------------------------------------------------------------
    // Compléter sur place — PUT /api/demandes/{id}/brouillons/{ref}
    // ---------------------------------------------------------------------------------

    public function testLAuteurCompleteSaPropositionEtRecoitLaDemandeAvecSaNouvelleEmpreinte(): void
    {
        $this->connexion('opale@example.net');
        $id = $this->proposer();

        $this->requete('GET', '/api/demandes/'.$id);
        $lue = $this->charge();
        self::assertIsString($lue['empreinte']);
        self::assertNull($lue['complementDe']);

        $this->requete('PUT', '/api/demandes/'.$id.'/brouillons/p1', [
            'fiche' => $this->fiche(['nickname' => 'Bébert']),
            'liens' => [['kind' => 'parent_de', 'subjectDraftRef' => '#p1', 'otherPersonId' => self::PERSONNE]],
            'empreinte' => $lue['empreinte'],
        ]);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        $charge = $this->charge();
        self::assertSame($id, $charge['id']);
        self::assertSame('Bébert', $charge['brouillons'][0]['nickname']);
        self::assertSame([1, 2], array_column($charge['liens'], 'seq'));
        self::assertNotSame($lue['empreinte'], $charge['empreinte'], 'La réponse porte l\'empreinte à renvoyer la fois suivante.');
    }

    public function testUnAutreMembreRecoit403(): void
    {
        $this->connexion('opale@example.net');
        $id = $this->proposer();

        $this->connexion('tuf@example.net');
        $this->requete('PUT', '/api/demandes/'.$id.'/brouillons/p1', ['fiche' => $this->fiche(['nickname' => 'Bébert'])]);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertSame('acces_refuse', $this->erreur()['code']);
    }

    public function testUneFicheInconnueRend404EtUneEmpreintePerimeeRend409(): void
    {
        $this->connexion('opale@example.net');
        $id = $this->proposer();

        $this->requete('PUT', '/api/demandes/'.$id.'/brouillons/p9', ['fiche' => $this->fiche()]);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        self::assertSame('introuvable', $this->erreur()['code']);

        $this->requete('PUT', '/api/demandes/'.$id.'/brouillons/p1', ['fiche' => $this->fiche(), 'empreinte' => str_repeat('0', 64)]);
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        $erreur = $this->erreur();
        self::assertSame('demande_modifiee_entre_temps', $erreur['code']);
        self::assertIsArray($erreur['details']);
        self::assertSame(['demandeId', 'empreinte', 'rejouable'], array_keys($erreur['details']));
        self::assertSame($id, $erreur['details']['demandeId']);
        self::assertTrue($erreur['details']['rejouable']);
    }

    public function testUnCorpsInvalideRend422(): void
    {
        $this->connexion('opale@example.net');
        $id = $this->proposer();

        $this->requete('PUT', '/api/demandes/'.$id.'/brouillons/p1', [
            'fiche' => $this->fiche(['death' => ['raw' => '1968', 'qualifier' => 'annee', 'min' => '1968-01-01', 'max' => '1968-12-31', 'place' => null]]),
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $erreur = $this->erreur();
        self::assertSame('donnees_invalides', $erreur['code']);
        self::assertIsArray($erreur['champs']);
        self::assertArrayHasKey('fiche.death', $erreur['champs']);
    }

    // ---------------------------------------------------------------------------------
    // Le complément — POST /api/demandes, `complementDe`
    // ---------------------------------------------------------------------------------

    public function testUnComplementSeDeposeParLaRouteDeDepotEtSeLitDansMesDemandes(): void
    {
        $this->connexion('opale@example.net');
        $origine = $this->proposer();
        $fiche = $this->ficheDeLaDemande($origine);

        $this->connexion('tuf@example.net');
        $complement = $this->proposer(['sex' => 'masculin'], $fiche);

        $this->requete('GET', '/api/demandes/'.$complement);
        self::assertSame(['draftPersonId' => $fiche], $this->charge()['complementDe']);

        $this->requete('GET', '/api/demandes');
        self::assertSame($origine, $this->charge()['elements'][0]['complementDeDemandeId']);
    }

    public function testUnePropositionTrancheeRend409SansDetails(): void
    {
        $this->connexion('opale@example.net');
        $origine = $this->proposer();
        $fiche = $this->ficheDeLaDemande($origine);
        $this->requete('POST', '/api/demandes/'.$origine.'/retirer');
        self::assertResponseIsSuccessful();

        $this->connexion('tuf@example.net');
        $this->requete('POST', '/api/demandes', $this->corpsDeCreation([], $fiche));

        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        $erreur = $this->erreur();
        self::assertSame('proposition_plus_en_attente', $erreur['code']);
        self::assertNull($erreur['details']);
    }

    // ---------------------------------------------------------------------------------
    // L'administration — la vue, l'aperçu, la fusion, la validation
    // ---------------------------------------------------------------------------------

    public function testLaVueDAdministrationLApercuEtLaFusion(): void
    {
        $this->connexion('opale@example.net');
        $origine = $this->proposer();
        $fiche = $this->ficheDeLaDemande($origine);

        $this->connexion('tuf@example.net');
        $complement = $this->proposer(['sex' => 'masculin'], $fiche);
        $ficheDuComplement = $this->ficheDeLaDemande($complement);

        // L'aperçu est réservé à l'administration.
        $this->requete('GET', '/api/administration/demandes/'.$origine.'/fusionner/'.$complement);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

        $this->connexion('natron@example.net');

        $this->requete('GET', '/api/administration/demandes/'.$origine);
        self::assertResponseIsSuccessful();
        $vue = $this->charge();
        self::assertSame('complements_en_attente', $vue['resolutionPrevue']);
        self::assertCount(1, $vue['complements']);
        self::assertSame($complement, $vue['complements'][0]['id']);
        self::assertSame($ficheDuComplement, $vue['complements'][0]['draftPersonId']);
        self::assertSame($fiche, $vue['complements'][0]['origineDraftPersonId']);
        self::assertSame(['sex', 'note'], array_column($vue['complements'][0]['apercu']['remplis'], 'champ'));

        $this->requete('GET', '/api/administration/demandes/'.$complement);
        $vueDuComplement = $this->charge();
        self::assertSame('complement_a_fusionner', $vueDuComplement['resolutionPrevue']);
        self::assertSame(['draftPersonId', 'demandeId', 'localRef', 'displayName', 'status'], array_keys($vueDuComplement['complementDe']));
        self::assertSame($origine, $vueDuComplement['complementDe']['demandeId']);

        // Valider l'un ou l'autre : 409, rien d'écrit.
        $this->requete('POST', '/api/administration/demandes/'.$origine.'/valider');
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        $erreur = $this->erreur();
        self::assertSame('complements_en_attente', $erreur['code']);
        self::assertSame(['demandeId' => $origine, 'statut' => 'en_attente', 'rejouable' => true, 'complements' => [$complement]], $erreur['details']);

        $this->requete('POST', '/api/administration/demandes/'.$complement.'/valider');
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        self::assertSame('complement_a_fusionner', $this->erreur()['code']);

        // L'aperçu, puis la fusion avec les deux empreintes qu'il donne.
        $this->requete('GET', '/api/administration/demandes/'.$origine.'/fusionner/'.$complement);
        self::assertResponseIsSuccessful();
        $apercu = $this->charge();
        self::assertSame(['origine', 'complement', 'rapport'], array_keys($apercu));
        self::assertSame(['id', 'empreinte'], array_keys($apercu['origine']));
        self::assertSame(['remplis', 'conflits', 'liensAjoutes', 'liensIgnores', 'mediasDeplaces', 'demandesRepointees'], array_keys($apercu['rapport']));

        $this->requete('POST', '/api/administration/demandes/'.$origine.'/fusionner/'.$complement, [
            'retenir' => [],
            'empreinte' => $apercu['origine']['empreinte'],
            'empreinteComplement' => $apercu['complement']['empreinte'],
        ]);
        self::assertResponseIsSuccessful();
        $fusion = $this->charge();
        self::assertSame(['origine', 'complement', 'rapport'], array_keys($fusion));
        self::assertSame($apercu['rapport'], $fusion['rapport'], 'La fusion fait ce que l\'aperçu annonçait.');
        self::assertSame('application', $fusion['origine']['resolutionPrevue'], 'Plus rien ne bloque l\'origine.');
        self::assertSame('refusee', $fusion['origine']['complements'][0]['status']);
        self::assertNull($fusion['origine']['complements'][0]['apercu']);
        self::assertSame('masculin', $fusion['origine']['brouillons'][0]['sex']);
        self::assertSame('refusee', $fusion['complement']['status']);
        self::assertSame($origine, $fusion['complement']['complementDeDemandeId']);

        // Le complément clos ne se fusionne pas deux fois.
        $this->requete('POST', '/api/administration/demandes/'.$origine.'/fusionner/'.$complement);
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        self::assertSame('demande_deja_decidee', $this->erreur()['code']);

        // L'auteur du complément lit où il est allé.
        $this->connexion('tuf@example.net');
        $this->requete('GET', '/api/demandes/'.$complement);
        $vueDeTuf = $this->charge();
        self::assertSame($origine, $vueDeTuf['duplicateOfId']);
        self::assertSame(\sprintf('Fusionné dans la demande n° %d.', $origine), $vueDeTuf['decisionMessage']);
    }

    public function testValiderAvecUneEmpreintePerimeeRend409SansRienEcrire(): void
    {
        $this->connexion('opale@example.net');
        $id = $this->proposer();

        $this->connexion('natron@example.net');
        $this->requete('POST', '/api/administration/demandes/'.$id.'/valider', ['empreinte' => str_repeat('a', 64)]);

        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        self::assertSame('demande_modifiee_entre_temps', $this->erreur()['code']);
        self::assertSame('en_attente', $this->statutDeLaDemande($id));
    }

    /** On ne fusionne pas sous une identité empruntée — mais on lit l'aperçu. */
    public function testLaFusionEstRefuseeSousLaBascule(): void
    {
        $this->connexion('opale@example.net');
        $origine = $this->proposer();
        $fiche = $this->ficheDeLaDemande($origine);

        $this->connexion('tuf@example.net');
        $complement = $this->proposer([], $fiche);

        $this->connexion('natron@example.net');
        $membre = $this->em()->getRepository(\App\Module\Membership\Entity\Member::class)->findOneBy(['userId' => $this->tuf->getId()]);
        self::assertNotNull($membre);
        $this->requete('POST', '/api/administration/voir-comme', ['memberId' => $membre->getId()]);
        self::assertResponseIsSuccessful('La bascule doit s’activer.');

        $this->requete('GET', '/api/administration/demandes/'.$origine.'/fusionner/'.$complement);
        self::assertResponseIsSuccessful('L’aperçu est une lecture : il reste ouvert.');

        $this->requete('POST', '/api/administration/demandes/'.$origine.'/fusionner/'.$complement);
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        self::assertSame('vue_comme_membre_active', $this->erreur()['code']);

        $this->requete('PUT', '/api/demandes/'.$origine.'/brouillons/p1', ['fiche' => $this->fiche()]);
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        self::assertSame('vue_comme_membre_active', $this->erreur()['code']);
    }

    // ---------------------------------------------------------------------------------
    // Outils
    // ---------------------------------------------------------------------------------

    /**
     * Dépose une création **par HTTP**, pour le compte connecté, et rend son identifiant.
     *
     * @param array<string, mixed> $fiche
     */
    private function proposer(array $fiche = [], ?int $complementDe = null): int
    {
        $this->requete('POST', '/api/demandes', $this->corpsDeCreation($fiche, $complementDe));
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        $charge = $this->charge();
        self::assertIsInt($charge['id']);

        if (null !== $complementDe) {
            self::assertSame(['draftPersonId' => $complementDe], $charge['complementDe']);
        }

        return $charge['id'];
    }

    /**
     * @param array<string, mixed> $fiche
     *
     * @return array<string, mixed>
     */
    private function corpsDeCreation(array $fiche = [], ?int $complementDe = null): array
    {
        $corps = [
            'kind' => 'creation_personne',
            'message' => null === $complementDe ? null : 'c’est aussi mon arrière-grand-père',
            'soumettre' => true,
            'brouillons' => [$this->fiche($fiche)],
            'liens' => [['seq' => 1, 'kind' => 'parent_de', 'subjectDraftRef' => '#p1', 'otherPersonId' => self::PERSONNE]],
        ];

        if (null !== $complementDe) {
            $corps['complementDe'] = $complementDe;
        }

        return $corps;
    }

    /**
     * @param array<string, mixed> $surcharges
     *
     * @return array<string, mixed>
     */
    private function fiche(array $surcharges = []): array
    {
        return array_replace([
            'localRef' => '#p1',
            'givenNames' => 'Barnabé',
            'surname' => 'Sureau',
            'marriedName' => null,
            'nickname' => null,
            'sex' => 'inconnu',
            'deceased' => false,
            'birth' => null,
            'death' => null,
            'note' => null,
        ], $surcharges);
    }

    /** L'identifiant de la fiche `#p1` d'une demande — ce qu'un complément cite. */
    private function ficheDeLaDemande(int $demandeId): int
    {
        $fiche = $this->em()->getRepository(\App\Module\Proposal\Entity\DraftPerson::class)->findOneBy(['request' => $demandeId, 'localRef' => '#p1']);
        self::assertNotNull($fiche);
        self::assertIsInt($fiche->getId());

        return $fiche->getId();
    }
}
