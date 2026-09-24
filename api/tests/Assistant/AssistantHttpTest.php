<?php

declare(strict_types=1);

namespace App\Tests\Assistant;

use App\Module\Core\Entity\User;
use App\Module\Core\Enum\DateQualifier;
use App\Module\Core\Enum\Sex;
use App\Module\Core\Enum\SubjectKind;
use App\Module\Core\Service\CleDeRecherche;
use App\Module\Core\ValueObject\UncertainDate;
use App\Module\Membership\Entity\Member;
use App\Module\Proposal\Entity\ChangeRequest;
use App\Module\Proposal\Entity\ChangeRequestOp;
use App\Module\Tree\Entity\Person;
use App\Tests\Assistant\Double\AccesAuModeleSimule;
use App\Tests\Assistant\Double\ModeleScripte;
use App\Tests\Proposal\CasDeTestDemandesHttp;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * « Proposer avec l'assistant », **en HTTP et de bout en bout** : contrôleur, boucle
 * d'agent (modèle scripté), arbre réel (SQLite), dépôt à blanc de `Proposal`, cercle réel
 * de `Membership`, quota réel, dépôt au clic.
 *
 * ── La scène ─────────────────────────────────────────────────────────────────────
 *
 * - **Marne** Sureau, défunte : un fait historique, visible de tout compte ;
 * - **Tuf** Sureau, vivant, **hors du cercle** du membre : il le voit restreint ;
 * - **Opale**, le membre qui écrit, rattaché à sa propre fiche.
 *
 * Le modèle scripté cherche « Sureau », lit la fiche de Marne, puis propose cinq
 * demandes : une modification de Marne, une modification de Tuf (autorisée par le
 * commanditaire, mais **sans qu'aucune valeur de Tuf n'atteigne le navigateur**), la
 * création de leur fils, et deux propositions irrecevables — une fiche qui n'existe pas,
 * une valeur déjà présente — qui doivent devenir des avertissements.
 *
 * L'arbre est tenu à deux endroits, comme dans les autres tests HTTP de `Proposal` : en
 * SQLite pour `Tree` et `Membership` (recherche, fiche, vivant, cercle), et dans
 * `ArbreEnMemoire` pour le `PortArbre` de `Proposal` (verrous et dépôt). Les deux sont
 * posés ensemble, avec les mêmes identifiants et les mêmes valeurs.
 */
final class AssistantHttpTest extends CasDeTestDemandesHttp
{
    /** La naissance de Tuf : ce que le membre n'a **pas** le droit de lire. */
    private const string NAISSANCE_MASQUEE = '1990-04-12';

    private const string LIEU_MASQUE = 'Quimper';

    private int $marne;

    private int $tuf;

    private int $opale;

    protected function setUp(): void
    {
        parent::setUp();

        ModeleScripte::oublierTout();
        AccesAuModeleSimule::oublierTout();

        $naissanceDeMarne = new UncertainDate('1987', DateQualifier::Annee, new \DateTimeImmutable('1987-01-01'), new \DateTimeImmutable('1987-12-31'), 'Brest');
        $naissanceDeTuf = new UncertainDate('12 APR 1990', DateQualifier::Exacte, new \DateTimeImmutable(self::NAISSANCE_MASQUEE), new \DateTimeImmutable(self::NAISSANCE_MASQUEE), self::LIEU_MASQUE);

        $this->marne = $this->personne('Marne Dolomite', 'Sureau', Sex::Feminin, $naissanceDeMarne, decedee: true);
        $this->tuf = $this->personne('Tuf', 'Sureau', Sex::Masculin, $naissanceDeTuf, decedee: false);
        $this->opale = $this->personne('Opale', 'Tilleul', Sex::Feminin, new UncertainDate('1995', DateQualifier::Annee, new \DateTimeImmutable('1995-01-01'), new \DateTimeImmutable('1995-12-31')), decedee: false);

        $this->poserDansProposal($this->marne, 'Marne Dolomite Sureau', 'Marne Dolomite', 'Sureau', 'feminin', true, $naissanceDeMarne);
        $this->poserDansProposal($this->tuf, 'Tuf Sureau', 'Tuf', 'Sureau', 'masculin', false, $naissanceDeTuf);
    }

    // ─────────────────────────────────────────────────────────────────────────────
    //  L'accès
    // ─────────────────────────────────────────────────────────────────────────────

    public function testUnVisiteurAnonymeRecoit401(): void
    {
        $this->analyser(['texte' => 'Marne.']);

        self::assertResponseStatusCodeSame(401);
        self::assertSame([], ModeleScripte::requetes());
    }

    public function testUnCompteEnAttenteDeRattachementRecoit403(): void
    {
        $this->creerUtilisateur('attente@example.net');
        $this->connexion('attente@example.net');

        $this->analyser(['texte' => 'Marne est née en 1987.']);

        self::assertResponseStatusCodeSame(403);
        self::assertSame('regime_en_attente', $this->erreur()['code']);
        self::assertSame([], ModeleScripte::requetes(), 'Aucun appel ne part pour un compte qui ne contribue pas.');
    }

    public function testSousLaBasculeVoirCommeLAnalyseEtLeDepotRendent409(): void
    {
        $membre = $this->creerContributeur('opale@example.net', $this->opale, 'Opale Tilleul');
        $admin = $this->creerUtilisateur('natron@example.net', ['ROLE_ADMIN'], 'Natron');
        unset($admin);

        $this->connexion('natron@example.net');
        $this->requete('POST', '/api/administration/voir-comme', ['memberId' => $this->idDuMembre($membre)]);
        self::assertResponseIsSuccessful();

        $this->analyser(['texte' => 'Marne est née en 1987.']);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('vue_comme_membre_active', $this->erreur()['code']);

        $this->requete('POST', '/api/assistant/analyses/'.str_repeat('a', 32).'/demandes/d1');
        self::assertResponseStatusCodeSame(409);
        self::assertSame('vue_comme_membre_active', $this->erreur()['code']);

        self::assertSame([], ModeleScripte::requetes());
    }

    public function testSansCleLAssistantEstIndisponibleEtNeConsommeRien(): void
    {
        $this->seConnecterCommeOpale();
        AccesAuModeleSimule::retirerLaCle();

        $this->analyser(['texte' => 'Marne est née en 1987.']);

        self::assertResponseStatusCodeSame(503);
        self::assertSame('assistant_indisponible', $this->erreur()['code']);
        self::assertSame([], ModeleScripte::requetes());
        self::assertSame(20, $this->limiteur()->create((string) $this->idDOpale())->consume(0)->getRemainingTokens(), 'Une installation sans clé ne mange pas le quota du jour.');
    }

    public function testUneEntreeInvalideRend422AvantToutAppel(): void
    {
        $this->seConnecterCommeOpale();

        $this->analyser(['texte' => '   ']);
        self::assertResponseStatusCodeSame(422);
        self::assertArrayHasKey('texte', $this->erreur()['champs']);

        $this->analyser(['texte' => str_repeat('a', 8001)]);
        self::assertResponseStatusCodeSame(422);
        self::assertArrayHasKey('texte', $this->erreur()['champs']);

        $this->analyser(['texte' => 'Voici un tableur.'], [$this->fichier("PK\x03\x04pas une image", 'arbre.xlsx')]);
        self::assertResponseStatusCodeSame(422);
        self::assertArrayHasKey('fichiers', $this->erreur()['champs']);

        $sept = array_map(fn (int $i): UploadedFile => $this->fichier("\xFF\xD8\xFFphoto$i", "photo$i.jpg"), range(1, 7));
        $this->analyser([], $sept);
        self::assertResponseStatusCodeSame(422);
        self::assertArrayHasKey('fichiers', $this->erreur()['champs']);

        $this->analyser(['texte' => 'Marne.', 'personId' => 'dix-sept']);
        self::assertResponseStatusCodeSame(422);
        self::assertArrayHasKey('personId', $this->erreur()['champs']);

        self::assertSame([], ModeleScripte::requetes());
    }

    public function testLaVingtEtUniemeAnalyseDuJourRend429AvecRetryAfter(): void
    {
        $this->seConnecterCommeOpale();

        // Les vingt analyses du jour, consommées directement : c'est le même limiteur,
        // la même clé (le compte), le même stockage que la route.
        $limiteur = $this->limiteur()->create((string) $this->idDOpale());
        for ($i = 0; $i < 20; ++$i) {
            self::assertTrue($limiteur->consume()->isAccepted());
        }

        $this->analyser(['texte' => 'Marne est née en 1987.']);

        self::assertResponseStatusCodeSame(429);
        self::assertSame('trop_de_tentatives', $this->erreur()['code']);
        $apres = $this->client->getResponse()->headers->get('Retry-After');
        self::assertNotNull($apres);
        self::assertGreaterThan(0, (int) $apres);
        self::assertSame([], ModeleScripte::requetes());
    }

    public function testUnModeleEnPanneRend502(): void
    {
        $this->seConnecterCommeOpale();
        ModeleScripte::repondra(new \RuntimeException('Délai dépassé.'));

        $this->analyser(['texte' => 'Marne est née en 1987.']);

        self::assertResponseStatusCodeSame(502);
        self::assertSame('assistant_erreur', $this->erreur()['code']);
    }

    // ─────────────────────────────────────────────────────────────────────────────
    //  L'analyse
    // ─────────────────────────────────────────────────────────────────────────────

    public function testLAnalyseRendDesDemandesValidesVuesParLeCercleDuLecteur(): void
    {
        $this->seConnecterCommeOpale();
        $this->scenarioComplet();

        $this->analyser(['texte' => 'Marne est née le 4 mai 1987 à Brest ; son fils Paul est né en 2012.', 'personId' => (string) $this->marne], [
            $this->fichier("\xFF\xD8\xFFacte-de-naissance", 'acte.jpg'),
        ]);

        self::assertResponseIsSuccessful();
        $brut = (string) $this->client->getResponse()->getContent();
        $charge = $this->charge();

        self::assertSame(['analyseId', 'resume', 'demandes', 'avertissements', 'restant'], array_keys($charge));
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $charge['analyseId']);
        self::assertSame(19, $charge['restant']);
        self::assertSame('Un acte de naissance et un témoignage.', $charge['resume']);
        self::assertSame(['d1', 'd2', 'd3'], array_column($charge['demandes'], 'cle'), 'Trois demandes recevables, numérotées dans l’ordre.');

        // Les deux irrecevables deviennent des avertissements, après celui du modèle.
        self::assertCount(3, $charge['avertissements']);
        self::assertSame('Le document est un peu flou.', $charge['avertissements'][0]);
        self::assertStringContainsString('« Personne fantôme » a été écartée', $charge['avertissements'][1]);
        self::assertStringContainsString('« Rien de neuf » a été écartée', $charge['avertissements'][2]);

        [$marne, $tuf, $paul] = $charge['demandes'];

        // ── d1 : Marne, défunte, visible — le corps est complet, verrous compris. ──
        self::assertSame('haute', $marne['confiance']);
        self::assertSame('modification_personne', $marne['corps']['kind']);
        self::assertSame($this->marne, $marne['corps']['targetId']);
        $op = $marne['corps']['ops'][0];
        self::assertSame(['seq', 'opKind', 'subjectKind', 'subjectId', 'field', 'valueKind', 'oldValue', 'newValue', 'baseVersion'], array_keys($op));
        self::assertSame('date_incertaine', $op['valueKind']);
        self::assertSame(3, $op['baseVersion'], '`baseVersion` est lu en base, pas repris du modèle.');
        self::assertSame(['raw' => '1987', 'qualifier' => 'annee', 'min' => '1987-01-01', 'max' => '1987-12-31', 'place' => 'Brest'], $op['oldValue']);
        self::assertSame(['raw' => '4 MAY 1987', 'qualifier' => 'exacte', 'min' => '1987-05-04', 'max' => '1987-05-04', 'place' => 'Brest'], $op['newValue']);
        self::assertSame([['libelle' => 'Naissance', 'avant' => '1987', 'apres' => '4 mai 1987']], $marne['changements']);
        self::assertSame([['personId' => $this->marne, 'displayName' => 'Marne Dolomite Sureau', 'visibilite' => 'complete', 'ligneDates' => '1987 –']], $marne['personnes']);

        // ── d2 : Tuf, vivant hors cercle — proposée, mais rien de lui ne sort. ────
        self::assertSame('modification_personne', $tuf['corps']['kind']);
        self::assertSame(['nickname'], array_column($tuf['corps']['ops'], 'field'));
        self::assertArrayNotHasKey('oldValue', $tuf['corps']['ops'][0], 'Le corps rendu n’est qu’une vue d’affichage : pas de valeur masquée.');
        self::assertArrayNotHasKey('baseVersion', $tuf['corps']['ops'][0]);
        self::assertSame('Tutu', $tuf['corps']['ops'][0]['newValue']);
        self::assertSame([['libelle' => 'Surnom', 'avant' => null, 'apres' => 'Tutu']], $tuf['changements'], '`avant` à null : le front écrit « (valeur masquée) ».');
        self::assertSame([['personId' => $this->tuf, 'displayName' => 'Tuf Sureau', 'visibilite' => 'restreinte']], $tuf['personnes'], 'Restreinte : le nom, pas de dates — la clé est absente.');

        // ── d3 : la création de Paul, enfant de Marne et de Tuf. ─────────────────
        self::assertSame('creation_personne', $paul['corps']['kind']);
        self::assertSame('#p1', $paul['corps']['brouillons'][0]['localRef']);
        self::assertSame(
            [['seq' => 1, 'kind' => 'enfant_de', 'subjectDraftRef' => '#p1', 'otherPersonId' => $this->marne, 'other2PersonId' => $this->tuf]],
            $paul['corps']['liens'],
        );
        self::assertSame(['complete', 'restreinte'], array_column($paul['personnes'], 'visibilite'));
        self::assertSame([
            ['libelle' => 'Nouvelle personne', 'avant' => null, 'apres' => 'Paul Sureau — naissance : 2012'],
            ['libelle' => 'Lien de parenté', 'avant' => null, 'apres' => 'Paul Sureau (proposée) enfant de Marne Dolomite Sureau et Tuf Sureau'],
        ], $paul['changements']);

        // ── La règle, sur les octets : aucune donnée datée de Tuf dans la réponse. ─
        foreach ([self::NAISSANCE_MASQUEE, self::LIEU_MASQUE, '12 APR 1990', '12 avril 1990', '1990'] as $interdit) {
            self::assertStringNotContainsString($interdit, $brut, \sprintf('« %s » est une donnée de Tuf que le lecteur ne voit pas.', $interdit));
        }

        // ── Et ce que le modèle a reçu. ─────────────────────────────────────────
        $requetes = ModeleScripte::requetes();
        self::assertCount(3, $requetes, 'Chercher, lire, proposer.');
        self::assertSame('gemini-pro-latest', $requetes[0]['modele']);
        $trouvees = array_column($requetes[1]['charge']['contents'][2]['parts'][0]['functionResponse']['response']['personnes'], null, 'personId');
        self::assertFalse($trouvees[$this->marne]['confidentielle']);
        self::assertTrue($trouvees[$this->tuf]['confidentielle'], 'Le modèle voit Tuf — décision du commanditaire — et sait qu’il ne doit rien en citer.');
        self::assertSame('12 avril 1990, Quimper', $trouvees[$this->tuf]['naissance'], 'Les outils lisent tout l’arbre.');

        // ── Le dépôt à blanc n'a rien écrit. ─────────────────────────────────────
        self::assertSame(0, $this->em()->getRepository(ChangeRequest::class)->count([]), 'Analyser ne dépose rien.');
    }

    // ─────────────────────────────────────────────────────────────────────────────
    //  Le dépôt
    // ─────────────────────────────────────────────────────────────────────────────

    public function testLeDepotEnvoieLaDemandeCompleteSansJamaisMontrerLaValeurMasquee(): void
    {
        $this->seConnecterCommeOpale();
        $this->scenarioComplet();
        $analyseId = $this->analyserLeScenario();

        // d2 : Tuf, restreint. Le corps complet part du serveur, pas du navigateur.
        $this->requete('POST', \sprintf('/api/assistant/analyses/%s/demandes/d2', $analyseId), ['message' => 'Tout le monde l’appelle Tutu.']);

        self::assertResponseStatusCodeSame(201);
        $brut = (string) $this->client->getResponse()->getContent();
        $demande = $this->charge();
        self::assertSame('en_attente', $demande['status'], 'Déposée soumise : elle entre dans la file.');
        self::assertSame('modification_personne', $demande['kind']);
        self::assertSame($this->tuf, $demande['targetId']);
        self::assertSame('Tout le monde l’appelle Tutu.', $demande['message']);
        self::assertArrayNotHasKey('oldValue', $demande['ops'][0], 'La vue auteur ne rend pas la valeur d’avant d’un vivant hors cercle.');
        self::assertStringNotContainsString(self::NAISSANCE_MASQUEE, $brut);

        // En base, en revanche, la demande porte les vrais verrous.
        $op = $this->em()->getRepository(ChangeRequestOp::class)->findOneBy(['field' => 'nickname']);
        self::assertInstanceOf(ChangeRequestOp::class, $op);
        self::assertNull($op->getOldValue());
        self::assertSame(5, $op->getBaseVersion());

        // Relue par « mes demandes » : toujours rien de masqué.
        $this->requete('GET', '/api/demandes/'.$demande['id']);
        self::assertResponseIsSuccessful();
        self::assertArrayNotHasKey('oldValue', $this->charge()['ops'][0]);

        // Une clé ne se dépose qu'une fois.
        $this->requete('POST', \sprintf('/api/assistant/analyses/%s/demandes/d2', $analyseId));
        self::assertResponseStatusCodeSame(409);
        self::assertSame('deja_deposee', $this->erreur()['code']);
        self::assertSame(['demandeId' => $demande['id']], $this->erreur()['details']);

        // d1 sans mot : la justification de l'assistant en tient lieu.
        $this->requete('POST', \sprintf('/api/assistant/analyses/%s/demandes/d1', $analyseId));
        self::assertResponseStatusCodeSame(201);
        $marne = $this->charge();
        self::assertSame('Proposée avec l’assistant. Acte de naissance, document 1.', $marne['message']);
        self::assertSame(['raw' => '1987', 'qualifier' => 'annee', 'min' => '1987-01-01', 'max' => '1987-12-31', 'place' => 'Brest'], array_diff_key($marne['ops'][0]['oldValue'], ['texte' => true]), 'Sur une défunte, la valeur d’avant est rendue comme d’habitude.');

        // d3 : la création passe elle aussi.
        $this->requete('POST', \sprintf('/api/assistant/analyses/%s/demandes/d3', $analyseId));
        self::assertResponseStatusCodeSame(201);
        self::assertSame('creation_personne', $this->charge()['kind']);

        self::assertSame(3, $this->em()->getRepository(ChangeRequest::class)->count([]));
        self::assertCount(3, $this->notificateur()->de('demande_soumise'),'Trois demandes soumises, trois notifications — le circuit habituel.');
    }

    public function testLAnalyseDUnAutreEstIntrouvable(): void
    {
        $this->seConnecterCommeOpale();
        $this->scenarioComplet();
        $analyseId = $this->analyserLeScenario();

        $this->deconnexion();
        $this->creerContributeur('tuf@example.net', $this->tuf, 'Tuf Sureau');
        $this->connexion('tuf@example.net');

        $this->requete('POST', \sprintf('/api/assistant/analyses/%s/demandes/d1', $analyseId));
        self::assertResponseStatusCodeSame(404, 'Une analyse d’autrui n’existe pas pour ce compte — 404, pas 403.');

        $this->requete('POST', '/api/assistant/analyses/'.str_repeat('b', 32).'/demandes/d1');
        self::assertResponseStatusCodeSame(404);

        $this->requete('POST', \sprintf('/api/assistant/analyses/%s/demandes/d1', 'pas-un-identifiant'));
        self::assertResponseStatusCodeSame(404);

        self::assertSame(0, $this->em()->getRepository(ChangeRequest::class)->count([]));
    }

    public function testUneCleInconnueDeLAnalyseEstIntrouvable(): void
    {
        $this->seConnecterCommeOpale();
        $this->scenarioComplet();
        $analyseId = $this->analyserLeScenario();

        $this->requete('POST', \sprintf('/api/assistant/analyses/%s/demandes/d9', $analyseId));
        self::assertResponseStatusCodeSame(404);
    }

    // ─────────────────────────────────────────────────────────────────────────────
    //  Le décor
    // ─────────────────────────────────────────────────────────────────────────────

    /** Le modèle cherche « Sureau », lit la fiche de Marne, puis propose cinq demandes. */
    private function scenarioComplet(): void
    {
        ModeleScripte::repondra(
            ModeleScripte::appel('chercher_personnes', ['requete' => 'Sureau']),
            ModeleScripte::appel('lire_personne', ['personId' => $this->marne]),
            ModeleScripte::appel('proposer', [
                'resume' => 'Un acte de naissance et un témoignage.',
                'avertissements' => ['Le document est un peu flou.'],
                'demandes' => [
                    [
                        'nature' => 'modification_personne', 'titre' => 'Préciser la naissance de Marne',
                        'justification' => 'Acte de naissance, document 1.', 'confiance' => 'haute',
                        'personId' => $this->marne,
                        'changements' => [['champ' => 'birth', 'date' => ['qualificatif' => 'exacte', 'date' => '1987-05-04', 'lieu' => 'Brest']]],
                    ],
                    [
                        'nature' => 'modification_personne', 'titre' => 'Le surnom de Tuf',
                        'justification' => 'Le membre écrit que Tuf est surnommé Tutu.', 'confiance' => 'moyenne',
                        'personId' => $this->tuf,
                        'changements' => [['champ' => 'nickname', 'texte' => 'Tutu']],
                    ],
                    [
                        'nature' => 'creation_personne', 'titre' => 'Ajouter Paul Sureau (2012)',
                        'justification' => 'Le membre écrit que Paul est né en 2012.', 'confiance' => 'moyenne',
                        'personnes' => [['ref' => '#p1', 'prenoms' => 'Paul', 'nom' => 'Sureau', 'sexe' => 'masculin', 'naissance' => ['qualificatif' => 'annee', 'date' => '2012']]],
                        'liens' => [['nature' => 'enfant_de', 'sujet' => '#p1', 'autre' => (string) $this->marne, 'autre2' => (string) $this->tuf]],
                    ],
                    [
                        'nature' => 'modification_personne', 'titre' => 'Personne fantôme', 'justification' => '?', 'confiance' => 'faible',
                        'personId' => 999999,
                        'changements' => [['champ' => 'nickname', 'texte' => 'Fantôme']],
                    ],
                    [
                        'nature' => 'modification_personne', 'titre' => 'Rien de neuf', 'justification' => 'Acte.', 'confiance' => 'haute',
                        'personId' => $this->marne,
                        'changements' => [['champ' => 'surname', 'texte' => 'Sureau']],
                    ],
                ],
            ]),
        );
    }

    private function analyserLeScenario(): string
    {
        $this->analyser(['texte' => 'Marne est née le 4 mai 1987 à Brest.']);
        self::assertResponseIsSuccessful();

        $identifiant = $this->charge()['analyseId'];
        self::assertIsString($identifiant);

        return $identifiant;
    }

    /**
     * @param array<string, string> $champs
     * @param list<UploadedFile>    $fichiers
     */
    private function analyser(array $champs, array $fichiers = []): void
    {
        $this->client->request(
            'POST',
            '/api/assistant/analyses',
            $champs,
            [] === $fichiers ? [] : ['fichiers' => $fichiers],
            ['HTTP_ACCEPT' => 'application/json'],
        );
    }

    private function fichier(string $octets, string $nom): UploadedFile
    {
        $chemin = tempnam(sys_get_temp_dir(), 'assistant');
        \assert(\is_string($chemin));
        file_put_contents($chemin, $octets);

        return new UploadedFile($chemin, $nom, null, null, true);
    }

    private function seConnecterCommeOpale(): void
    {
        $this->creerContributeur('opale@example.net', $this->opale, 'Opale Tilleul');
        $this->connexion('opale@example.net');
    }

    private function idDOpale(): int
    {
        $compte = $this->em()->getRepository(User::class)->findOneBy(['email' => 'opale@example.net']);
        \assert($compte instanceof User && null !== $compte->getId());

        return $compte->getId();
    }

    private function idDuMembre(User $compte): int
    {
        $membre = $this->em()->getRepository(Member::class)->findOneBy(['userId' => $compte->getId()]);
        \assert(null !== $membre && null !== $membre->getId());

        return $membre->getId();
    }

    private function limiteur(): RateLimiterFactoryInterface
    {
        $limiteur = self::getContainer()->get('assistant.limiteur_analyses');
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

    /**
     * La même personne, côté `Proposal` : c'est là que le dépôt lit les verrous. Marne
     * porte la version 3, Tuf la 5 — deux nombres distincts, pour qu'un verrou recopié de
     * la mauvaise fiche se voie.
     */
    private function poserDansProposal(int $id, string $libelle, string $prenoms, string $nom, string $sexe, bool $decedee, UncertainDate $naissance): void
    {
        $this->arbre()->poser(SubjectKind::Personne, $id, $libelle, $decedee ? 3 : 5, [
            'givenNames' => $prenoms,
            'surname' => $nom,
            'marriedName' => null,
            'nickname' => null,
            'sex' => $sexe,
            'deceased' => $decedee,
            'birth' => $naissance,
            'death' => UncertainDate::inconnue(),
            'forcePrivacy' => false,
            'privacyNote' => null,
        ]);
    }
}
