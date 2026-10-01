<?php

declare(strict_types=1);

namespace App\Tests\Assistant;

use App\Module\Core\Entity\AuditLog;
use App\Module\Core\Enum\AuditAction;
use App\Module\Core\Enum\DateQualifier;
use App\Module\Core\Enum\Sex;
use App\Module\Core\Enum\SubjectKind;
use App\Module\Core\Exception\ConflictException;
use App\Module\Core\Service\CleDeRecherche;
use App\Module\Core\ValueObject\UncertainDate;
use App\Module\Membership\Contract\DeclarationDuCompte;
use App\Module\Membership\Entity\Member;
use App\Module\Tree\Entity\Person;
use App\Tests\Assistant\Double\AccesAuModeleSimule;
use App\Tests\Assistant\Double\ComptesARattacherEnMemoire;
use App\Tests\Assistant\Double\ModeleScripte;
use App\Tests\Proposal\CasDeTestDemandesHttp;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Le rattachement assisté, **en HTTP et de bout en bout** : contrôleur, boucle d'agent
 * (modèle scripté, jamais de réseau), arbre réel (SQLite) pour la reconnaissance, dépôt
 * et validation réels de `Proposal` (arbre en mémoire pour ses verrous), quota réel.
 *
 * Seul le côté `Membership` est un double ({@see ComptesARattacherEnMemoire}) : le
 * contrat `ComptesARattacher` est ce que l'assistant consomme, et l'implémentation réelle
 * s'éprouve dans les tests de `Membership`.
 *
 * ── La scène ─────────────────────────────────────────────────────────────────────
 *
 * - **Léa Marie Dupont** s'inscrit sans se trouver. Sa déclaration : née le 12 avril
 *   1998, « mon père est Jean Dupont, né vers 1960 à Lille ; ma mère Marie Martin ».
 * - **Jean Dupont** et **Marie Martin**, vivants, sont dans l'arbre : ses parents.
 * - **Léa Dupont**, vivante, née en 1997 : une fiche qui pourrait être elle.
 * - **Aïeul Dupont**, décédé : un candidat que l'analyse ne doit jamais retenir.
 */
final class RattachementAssisteHttpTest extends CasDeTestDemandesHttp
{
    private const int MEMBRE = 14;

    private const string COURRIEL_INSCRIT = 'lea.dupont@example.net';

    private const string DESCRIPTION = 'Mon père est Jean Dupont, né vers 1960 à Lille ; ma mère Marie Martin ; ma grand-mère paternelle Rose.';

    private int $jean;

    private int $marie;

    private int $leaExistante;

    private int $aieul;

    protected function setUp(): void
    {
        parent::setUp();

        ModeleScripte::oublierTout();
        AccesAuModeleSimule::oublierTout();
        ComptesARattacherEnMemoire::oublierTout();

        $this->jean = $this->personne('Jean', 'Dupont', Sex::Masculin, self::annee(1960), false);
        $this->marie = $this->personne('Marie', 'Martin', Sex::Feminin, self::annee(1962), false);
        $this->leaExistante = $this->personne('Léa', 'Dupont', Sex::Feminin, self::annee(1997), false);
        $this->aieul = $this->personne('Aïeul', 'Dupont', Sex::Masculin, self::annee(1880), true);

        $this->poserDansProposal($this->jean, 'Jean Dupont', 'Jean', 'Dupont', 'masculin');
        $this->poserDansProposal($this->marie, 'Marie Martin', 'Marie', 'Martin', 'feminin');

        ComptesARattacherEnMemoire::poser(self::declaration());

        $this->creerUtilisateur('natron@example.net', ['ROLE_ADMIN'], 'Natron');
    }

    // ─────────────────────────────────────────────────────────────────────────────
    //  L'accès
    // ─────────────────────────────────────────────────────────────────────────────

    public function testUnMembreOrdinaireNAccedePasAuxRattachements(): void
    {
        $this->creerContributeur('opale@example.net', $this->jean, 'Opale');
        $this->connexion('opale@example.net');

        $this->requete('GET', $this->route('suggestion'));
        self::assertResponseStatusCodeSame(403);

        $this->requete('POST', $this->route('creer-et-rattacher'), ['parents' => [$this->jean]]);
        self::assertResponseStatusCodeSame(403);
        self::assertSame([], ModeleScripte::requetes());
    }

    public function testUnCompteQuiNAttendPasDeRattachementRend404(): void
    {
        $this->connexion('natron@example.net');

        $this->requete('GET', '/api/administration/rattachements/999/suggestion');
        self::assertResponseStatusCodeSame(404);

        $this->requete('POST', '/api/administration/rattachements/999/creer-et-rattacher', ['parents' => [$this->jean]]);
        self::assertResponseStatusCodeSame(404);
        self::assertSame([], ModeleScripte::requetes());
    }

    public function testSousLaBasculeLesTroisRoutesRendent409(): void
    {
        $membre = $this->creerContributeur('opale@example.net', $this->jean, 'Opale');
        $this->connexion('natron@example.net');
        $ligne = $this->em()->getRepository(Member::class)->findOneBy(['userId' => $membre->getId()]);
        \assert(null !== $ligne);
        $this->requete('POST', '/api/administration/voir-comme', ['memberId' => $ligne->getId()]);
        self::assertResponseIsSuccessful();

        foreach ([['GET', 'suggestion'], ['POST', 'suggestion'], ['POST', 'creer-et-rattacher']] as [$methode, $fin]) {
            $this->requete($methode, $this->route($fin), 'creer-et-rattacher' === $fin ? ['parents' => [$this->jean]] : null);
            self::assertResponseStatusCodeSame(409, "$methode $fin");
            self::assertSame('vue_comme_membre_active', $this->erreur()['code']);
        }

        self::assertSame([], ModeleScripte::requetes());
    }

    // ─────────────────────────────────────────────────────────────────────────────
    //  La suggestion
    // ─────────────────────────────────────────────────────────────────────────────

    public function testLaSuggestionReconnaitUneFicheExistanteEtLaGardeSurLeCompte(): void
    {
        $this->connexion('natron@example.net');
        ModeleScripte::repondra(
            ModeleScripte::appel('chercher_personnes', ['requete' => 'Léa Dupont']),
            ModeleScripte::appel('conclure', [
                'resume' => 'Elle écrit que son père est Jean Dupont.',
                'verdict' => 'fiche_existante',
                'fiche' => ['personId' => $this->leaExistante, 'confiance' => 'moyenne', 'justification' => 'Même nom, père Jean Dupont.'],
                'alternatives' => [
                    ['personId' => $this->aieul, 'justification' => 'Homonyme.'],
                    ['personId' => 987654, 'justification' => 'Inventée.'],
                ],
            ]),
        );

        $this->requete('GET', $this->route('suggestion'));
        self::assertResponseIsSuccessful();
        $suggestion = $this->charge();

        self::assertSame(self::MEMBRE, $suggestion['memberId']);
        self::assertSame('fiche_existante', $suggestion['verdict']);
        self::assertSame($this->leaExistante, $suggestion['fiche']['personId']);
        self::assertSame('Léa Dupont', $suggestion['fiche']['displayName']);
        self::assertSame('1997 –', $suggestion['fiche']['ligneDates']);
        self::assertFalse($suggestion['fiche']['dejaRattachee']);
        self::assertSame([], $suggestion['alternatives'], 'Un défunt et une fiche inventée ne sont jamais proposés.');
        self::assertCount(2, $suggestion['avertissements']);
        self::assertNull($suggestion['creation']);
        self::assertIsString($suggestion['calculeeLe']);
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));

        // Ce que le modèle a reçu : la déclaration, JAMAIS le courriel.
        $envoye = json_encode(ModeleScripte::requetes()[0]['charge'], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE);
        self::assertStringContainsString('Mon père est Jean Dupont', $envoye);
        self::assertStringContainsString('1998-04-12', $envoye);
        self::assertStringNotContainsString(self::COURRIEL_INSCRIT, $envoye);
        self::assertStringContainsString('"conclure"', $envoye);
        self::assertStringNotContainsString('"proposer"', $envoye, 'L’outil final de l’analyse de documents n’est pas offert ici.');

        self::assertNotNull(ComptesARattacherEnMemoire::suggestionGardee(self::MEMBRE), 'La suggestion est gardée sur le compte.');

        // Seconde lecture : servie du cache, sans appel (le script est épuisé), et
        // `dejaRattachee` est recalculé — une autre inscription a réclamé la fiche.
        ComptesARattacherEnMemoire::reclamer($this->leaExistante);
        $this->requete('GET', $this->route('suggestion'));
        self::assertResponseIsSuccessful();
        self::assertTrue($this->charge()['fiche']['dejaRattachee']);
        self::assertCount(2, ModeleScripte::requetes());
    }

    public function testUneFicheDecedeeProposeeEstEcarteeEtLeVerdictDevientIndecis(): void
    {
        $this->connexion('natron@example.net');
        ModeleScripte::repondra(ModeleScripte::appel('conclure', [
            'resume' => 'Peut-être l’aïeul.',
            'verdict' => 'fiche_existante',
            'fiche' => ['personId' => $this->aieul, 'confiance' => 'haute', 'justification' => 'Même nom.'],
        ]));

        $this->requete('GET', $this->route('suggestion'));
        self::assertResponseIsSuccessful();
        $suggestion = $this->charge();

        self::assertSame('indecise', $suggestion['verdict']);
        self::assertNull($suggestion['fiche']);
        self::assertStringContainsString('décédée', $suggestion['avertissements'][0]);
    }

    public function testAbsenteLaCreationProposeeEstRecevableEtPorteLIdentiteDeclaree(): void
    {
        $this->connexion('natron@example.net');
        ModeleScripte::repondra(ModeleScripte::appel('conclure', [
            'resume' => 'Absente de l’arbre ; ses parents y sont.',
            'verdict' => 'absente',
            'creation' => [
                'titre' => 'Ajouter Léa Dupont, fille de Jean Dupont et Marie Martin',
                'justification' => 'Elle nomme ses deux parents.',
                'confiance' => 'haute',
                // Le modèle « corrige » le prénom et la date : l'identité est reprise de
                // la déclaration, seuls le sexe et le lieu sont gardés.
                'personnes' => [['ref' => '#p1', 'prenoms' => 'Lea', 'nom' => 'DUPONT', 'sexe' => 'feminin', 'decedee' => true, 'naissance' => ['qualificatif' => 'annee', 'date' => '1999', 'lieu' => 'Lille']]],
                'liens' => [['nature' => 'enfant_de', 'sujet' => '#p1', 'autre' => (string) $this->jean, 'autre2' => (string) $this->marie]],
            ],
        ]));

        $this->requete('GET', $this->route('suggestion'));
        self::assertResponseIsSuccessful();
        $creation = $this->charge()['creation'];

        self::assertTrue($creation['recevable']);
        self::assertNull($creation['motifIrrecevable']);
        $p1 = $creation['corps']['brouillons'][0];
        self::assertSame(['#p1', 'Léa Marie', 'Dupont', 'feminin', false], [$p1['localRef'], $p1['givenNames'], $p1['surname'], $p1['sex'], $p1['deceased']]);
        self::assertSame(['exacte', '1998-04-12', '1998-04-12', 'Lille'], [$p1['birth']['qualifier'], $p1['birth']['min'], $p1['birth']['max'], $p1['birth']['place']]);
        self::assertSame($this->jean, $creation['corps']['liens'][0]['otherPersonId']);
        self::assertContains('Jean Dupont', array_column($creation['personnes'], 'displayName'));
        self::assertNotEmpty($creation['changements']);
    }

    public function testAbsenteSansLienLaCreationEstIrrecevableEtDitPourquoi(): void
    {
        $this->connexion('natron@example.net');
        ModeleScripte::repondra(ModeleScripte::appel('conclure', ['resume' => 'Aucun parent reconnu.', 'verdict' => 'absente']));

        $this->requete('GET', $this->route('suggestion'));
        self::assertResponseIsSuccessful();
        $creation = $this->charge()['creation'];

        self::assertNotNull($creation, '« absente » porte toujours une création, au pire tirée de la seule déclaration.');
        self::assertFalse($creation['recevable']);
        self::assertIsString($creation['motifIrrecevable']);
        self::assertSame('Léa Marie', $creation['corps']['brouillons'][0]['givenNames']);
    }

    public function testSansCleEtSansCacheLaSuggestionRend503SansConsommerLeQuota(): void
    {
        $this->connexion('natron@example.net');
        AccesAuModeleSimule::retirerLaCle();

        $this->requete('GET', $this->route('suggestion'));
        self::assertResponseStatusCodeSame(503);
        self::assertSame('assistant_indisponible', $this->erreur()['code']);
        self::assertSame(40, $this->limiteur()->create('rattachements')->consume(0)->getRemainingTokens());
    }

    public function testSansCleUneSuggestionGardeeResteServie(): void
    {
        $this->connexion('natron@example.net');
        ModeleScripte::repondra(ModeleScripte::appel('conclure', ['resume' => 'Indécis.', 'verdict' => 'indecise']));
        $this->requete('GET', $this->route('suggestion'));
        self::assertResponseIsSuccessful();

        AccesAuModeleSimule::retirerLaCle();
        $this->requete('GET', $this->route('suggestion'));
        self::assertResponseIsSuccessful();
        self::assertSame('indecise', $this->charge()['verdict']);

        // « Relancer » exige le modèle.
        $this->requete('POST', $this->route('suggestion'));
        self::assertResponseStatusCodeSame(503);
    }

    public function testRelancerRecalculeMalgreLeCache(): void
    {
        $this->connexion('natron@example.net');
        ModeleScripte::repondra(
            ModeleScripte::appel('conclure', ['resume' => 'Premier.', 'verdict' => 'indecise']),
            ModeleScripte::appel('conclure', ['resume' => 'Second.', 'verdict' => 'indecise']),
        );

        $this->requete('GET', $this->route('suggestion'));
        self::assertSame('Premier.', $this->charge()['resume']);

        $this->requete('POST', $this->route('suggestion'));
        self::assertResponseIsSuccessful();
        self::assertSame('Second.', $this->charge()['resume']);
        self::assertSame(38, $this->limiteur()->create('rattachements')->consume(0)->getRemainingTokens(), 'Chaque calcul consomme le quota global.');
    }

    public function testUnModeleEnPanneRend502EtLaQuaranteEtUniemeAnalyse429(): void
    {
        $this->connexion('natron@example.net');
        ModeleScripte::repondra(new \RuntimeException('Délai dépassé.'));

        $this->requete('GET', $this->route('suggestion'));
        self::assertResponseStatusCodeSame(502);
        self::assertSame('assistant_erreur', $this->erreur()['code']);

        $limiteur = $this->limiteur()->create('rattachements');
        while ($limiteur->consume()->isAccepted()) {
        }

        $this->requete('POST', $this->route('suggestion'));
        self::assertResponseStatusCodeSame(429);
        self::assertGreaterThan(0, (int) $this->client->getResponse()->headers->get('Retry-After'));
        self::assertCount(1, ModeleScripte::requetes());
    }

    public function testLAppelAuModeleEstAuditeSansContenu(): void
    {
        $this->connexion('natron@example.net');
        ModeleScripte::repondra(
            ModeleScripte::appel('lire_personne', ['personId' => $this->jean]),
            ModeleScripte::appel('conclure', ['resume' => 'Indécis.', 'verdict' => 'indecise']),
        );

        $this->requete('GET', $this->route('suggestion'));
        self::assertResponseIsSuccessful();

        $lignes = $this->em()->getRepository(AuditLog::class)->findBy(['action' => AuditAction::AppelModeleDeLangage]);
        self::assertCount(1, $lignes);
        self::assertSame('Assistant\\Rattachement', $lignes[0]->getSubjectType());
        self::assertSame(['tours' => 2, 'appels_d_outils' => ['lire_personne' => 1], 'verdict' => 'indecise', 'alternatives' => 0, 'creation_proposee' => false], $lignes[0]->getChanges());
        self::assertStringNotContainsString('Jean Dupont', (string) json_encode($lignes[0]->getChanges()));
    }

    // ─────────────────────────────────────────────────────────────────────────────
    //  « Créer la fiche et rattacher »
    // ─────────────────────────────────────────────────────────────────────────────

    public function testCreerLaFicheEtRattacherValideLaDemandeEtRattacheLeCompte(): void
    {
        $this->connexion('natron@example.net');

        $this->requete('POST', $this->route('creer-et-rattacher'), ['parents' => [$this->jean, $this->marie]]);
        self::assertResponseStatusCodeSame(201);
        $charge = $this->charge();

        $creees = $this->arbre()->personnesCreees();
        self::assertCount(1, $creees, 'Une seule fiche : celle de la personne inscrite.');
        self::assertSame($creees[0], $charge['personId']);
        self::assertSame('Léa Marie Dupont', $this->arbre()->chargerCible(SubjectKind::Personne, $creees[0])?->libelle);
        self::assertSame('actif', $charge['membre']['status']);

        $demande = $this->em()->getConnection()->fetchAssociative('SELECT id, status, message, auto_validated FROM prp_change_request');
        self::assertIsArray($demande);
        self::assertSame($charge['demandeId'], (int) $demande['id']);
        self::assertSame('validee', $demande['status']);
        self::assertSame('Fiche créée au rattachement d’une inscription.', $demande['message']);
        self::assertTrue((bool) $demande['auto_validated'], 'L’administrateur valide sa propre demande : autorisé, et marqué.');

        self::assertSame([['memberId' => self::MEMBRE, 'personId' => $creees[0], 'personLabel' => 'Léa Marie Dupont', 'administrateur' => 'natron@example.net']], ComptesARattacherEnMemoire::rattachements());
    }

    public function testLaCreationReprendLaSuggestionGardee(): void
    {
        $this->connexion('natron@example.net');
        ModeleScripte::repondra(ModeleScripte::appel('conclure', [
            'resume' => 'Absente ; sa grand-mère Rose aussi.',
            'verdict' => 'absente',
            'creation' => [
                'titre' => 'Ajouter Léa et sa grand-mère',
                'justification' => 'Texte.',
                'confiance' => 'moyenne',
                'personnes' => [
                    ['ref' => '#p1', 'sexe' => 'feminin'],
                    ['ref' => '#p2', 'prenoms' => 'Rose', 'nom' => 'Dupont', 'sexe' => 'feminin'],
                ],
                'liens' => [
                    ['nature' => 'enfant_de', 'sujet' => '#p1', 'autre' => (string) $this->jean],
                    ['nature' => 'parent_de', 'sujet' => '#p2', 'autre' => (string) $this->jean],
                ],
            ],
        ]));
        $this->requete('GET', $this->route('suggestion'));
        self::assertTrue($this->charge()['creation']['recevable']);

        // Sans parents : le corps gardé est déposé tel quel.
        $this->requete('POST', $this->route('creer-et-rattacher'), ['message' => 'Bienvenue Léa.']);
        self::assertResponseStatusCodeSame(201);
        self::assertCount(2, $this->arbre()->personnesCreees(), 'Léa et sa grand-mère Rose.');
    }

    public function testDesParentsChoisisRemplacentLaFiliationEtRetirentLesPersonnesFlottantes(): void
    {
        $this->connexion('natron@example.net');
        ModeleScripte::repondra(ModeleScripte::appel('conclure', [
            'resume' => 'Absente.',
            'verdict' => 'absente',
            'creation' => [
                'titre' => 'Ajouter Léa et un père inconnu de l’arbre',
                'justification' => 'Texte.',
                'confiance' => 'faible',
                'personnes' => [['ref' => '#p1'], ['ref' => '#p2', 'prenoms' => 'Jehan', 'nom' => 'Dupond']],
                'liens' => [['nature' => 'enfant_de', 'sujet' => '#p1', 'autre' => '#p2']],
            ],
        ]));
        $this->requete('GET', $this->route('suggestion'));
        self::assertResponseIsSuccessful();

        $this->requete('POST', $this->route('creer-et-rattacher'), ['parents' => [$this->jean]]);
        self::assertResponseStatusCodeSame(201);
        self::assertCount(1, $this->arbre()->personnesCreees(), '« Jehan » ne tient plus à Léa : il n’est pas créé.');
    }

    public function testDesParentsInvalidesRendent422SansRienEcrire(): void
    {
        $this->connexion('natron@example.net');

        foreach ([[1, 2, 3], [987654], ['douze'], [$this->jean, $this->jean]] as $parents) {
            $this->requete('POST', $this->route('creer-et-rattacher'), ['parents' => $parents]);
            self::assertResponseStatusCodeSame(422, json_encode($parents, \JSON_THROW_ON_ERROR));
            self::assertArrayHasKey('parents', $this->erreur()['champs']);
        }

        $this->assertRienNEstEcrit();
    }

    public function testSansSuggestionNiParentLaCreationRendDemandeSansLien(): void
    {
        $this->connexion('natron@example.net');

        $this->requete('POST', $this->route('creer-et-rattacher'));
        self::assertResponseStatusCodeSame(422);
        self::assertSame('demande_sans_lien', $this->erreur()['code']);

        $this->assertRienNEstEcrit();
    }

    public function testUnRattachementRefuseAnnuleLaFicheEtLaDemande(): void
    {
        $this->connexion('natron@example.net');
        ComptesARattacherEnMemoire::echouerAuRattachement(new ConflictException('personne_deja_rattachee', 'Cette personne est déjà rattachée à un compte.'));

        $this->requete('POST', $this->route('creer-et-rattacher'), ['parents' => [$this->jean]]);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('personne_deja_rattachee', $this->erreur()['code']);

        $this->assertRienNEstEcrit();
    }

    public function testUnVerrouConcurrentALaValidationNeLaisseAucuneLigne(): void
    {
        $this->connexion('natron@example.net');
        $this->arbre()->simulerVerrouConcurrent(true);

        $this->requete('POST', $this->route('creer-et-rattacher'), ['parents' => [$this->jean]]);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('verrou_concurrent', $this->erreur()['code']);

        $this->arbre()->simulerVerrouConcurrent(false);
        $this->assertRienNEstEcrit();
        self::assertSame([], ComptesARattacherEnMemoire::rattachements());
    }

    // ─────────────────────────────────────────────────────────────────────────────
    //  Outils
    // ─────────────────────────────────────────────────────────────────────────────

    private function assertRienNEstEcrit(): void
    {
        $connexion = $this->em()->getConnection();

        foreach (['prp_change_request', 'prp_draft_person', 'prp_draft_link', 'prp_revision'] as $table) {
            self::assertSame(0, (int) $connexion->fetchOne("SELECT COUNT(*) FROM $table"), "Aucune ligne dans $table.");
        }

        self::assertSame([], ComptesARattacherEnMemoire::rattachements());
    }

    private function route(string $fin): string
    {
        return \sprintf('/api/administration/rattachements/%d/%s', self::MEMBRE, $fin);
    }

    private static function declaration(): DeclarationDuCompte
    {
        return new DeclarationDuCompte(
            self::MEMBRE,
            22,
            self::COURRIEL_INSCRIT,
            'Dupont',
            'Léa Marie',
            new \DateTimeImmutable('1998-04-12'),
            self::DESCRIPTION,
            null,
            null,
            null,
        );
    }

    private static function annee(int $annee): UncertainDate
    {
        return new UncertainDate((string) $annee, DateQualifier::Annee, new \DateTimeImmutable("$annee-01-01"), new \DateTimeImmutable("$annee-12-31"));
    }

    private function limiteur(): RateLimiterFactoryInterface
    {
        $limiteur = self::getContainer()->get('assistant.limiteur_rattachements');
        \assert($limiteur instanceof RateLimiterFactoryInterface);

        return $limiteur;
    }

    private function personne(string $prenoms, string $nom, Sex $sexe, UncertainDate $naissance, bool $decedee): int
    {
        $personne = new Person($prenoms.' '.$nom);
        $personne
            ->setGivenNames($prenoms)
            ->setSurname($nom)
            ->setSearchKey((new CleDeRecherche())->cleDeNom($prenoms, $nom))
            ->setSex($sexe)
            ->setGeneration(1)
            ->setDeceased($decedee)
            ->setBirth($naissance)
            ->setDeath(UncertainDate::inconnue());

        $em = $this->em();
        $em->persist($personne);
        $em->flush();

        $id = $personne->getId();
        \assert(null !== $id);

        return $id;
    }

    private function poserDansProposal(int $id, string $libelle, string $prenoms, string $nom, string $sexe): void
    {
        $this->arbre()->poser(SubjectKind::Personne, $id, $libelle, 1, [
            'givenNames' => $prenoms,
            'surname' => $nom,
            'marriedName' => null,
            'nickname' => null,
            'sex' => $sexe,
            'deceased' => false,
            'birth' => UncertainDate::inconnue(),
            'death' => UncertainDate::inconnue(),
            'forcePrivacy' => false,
            'privacyNote' => null,
        ]);
    }
}
