<?php

declare(strict_types=1);

namespace App\Tests\Assistant;

use App\Module\Assistant\Entity\Conversation;
use App\Module\Assistant\Entity\Echange;
use App\Module\Core\Entity\AuditLog;
use App\Module\Core\Entity\User;
use App\Module\Core\Enum\AuditAction;
use App\Module\Core\Enum\DateQualifier;
use App\Module\Core\Enum\Sex;
use App\Module\Core\Security\CercleDeVisibiliteInterface;
use App\Module\Core\Service\CleDeRecherche;
use App\Module\Core\ValueObject\UncertainDate;
use App\Module\Membership\Entity\Member;
use App\Module\Proposal\Entity\ChangeRequest;
use App\Module\Tree\Entity\Person;
use App\Tests\Assistant\Double\AccesAuModeleSimule;
use App\Tests\Assistant\Double\ModeleScripte;
use App\Tests\Proposal\CasDeTestDemandesHttp;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * « Poser une question », **en HTTP et de bout en bout** : contrôleur, boucle d'agent
 * (modèle scripté), arbre réel (SQLite), cercle réel de `Membership`, quota réel, tables
 * `ast_*`.
 *
 * ── La scène ─────────────────────────────────────────────────────────────────────
 *
 * - **Marne** Sureau, défunte, née à Brest en 1887 : un fait historique, visible de tous ;
 * - **Tuf** Sureau, vivant, né à Brest lui aussi, **hors du cercle** du membre ;
 * - **Opale**, le membre qui pose les questions, rattachée à sa propre fiche.
 *
 * Le point que ces tests gardent avant tous les autres : **le modèle ne reçoit jamais
 * Tuf** — ni son nom, ni ses dates, ni son lieu —, quel que soit l'outil qu'il appelle.
 * C'est la différence avec l'assistant de proposition, et c'est le contrat de cette
 * route : l'IA ne voit que ce que le lecteur a le droit de voir.
 */
final class ConversationHttpTest extends CasDeTestDemandesHttp
{
    private const string NAISSANCE_MASQUEE = '1990-04-12';

    private const string QUESTION = 'Qui est né à Brest ?';

    private int $marne;

    private int $tuf;

    private int $opale;

    protected function setUp(): void
    {
        parent::setUp();

        ModeleScripte::oublierTout();
        AccesAuModeleSimule::oublierTout();

        $this->marne = $this->personne('Marne Dolomite', 'Sureau', Sex::Feminin, new UncertainDate('1887', DateQualifier::Annee, new \DateTimeImmutable('1887-01-01'), new \DateTimeImmutable('1887-12-31'), 'Brest, Finistère'), decedee: true);
        $this->tuf = $this->personne('Tuf', 'Sureau', Sex::Masculin, new UncertainDate('12 APR 1990', DateQualifier::Exacte, new \DateTimeImmutable(self::NAISSANCE_MASQUEE), new \DateTimeImmutable(self::NAISSANCE_MASQUEE), 'Brest'), decedee: false);
        $this->opale = $this->personne('Opale', 'Tilleul', Sex::Feminin, new UncertainDate('1995', DateQualifier::Annee, new \DateTimeImmutable('1995-01-01'), new \DateTimeImmutable('1995-12-31')), decedee: false);
    }

    // ─────────────────────────────────────────────────────────────────────────────
    //  L'accès
    // ─────────────────────────────────────────────────────────────────────────────

    public function testUnVisiteurAnonymeRecoit401(): void
    {
        $this->requete('GET', '/api/assistant/conversations');
        self::assertResponseStatusCodeSame(401);

        $this->requete('POST', '/api/assistant/conversations', ['question' => self::QUESTION]);
        self::assertResponseStatusCodeSame(401);
        self::assertSame([], ModeleScripte::requetes());
    }

    public function testUnCompteEnAttenteNePosePasDeQuestion(): void
    {
        $this->creerUtilisateur('attente@example.net');
        $this->connexion('attente@example.net');

        $this->requete('POST', '/api/assistant/conversations', ['question' => self::QUESTION]);

        self::assertResponseStatusCodeSame(403);
        self::assertSame('regime_en_attente', $this->erreur()['code']);
        self::assertSame([], ModeleScripte::requetes());
    }

    public function testSousLaBasculeVoirCommeOnLitMaisOnNEcritPas(): void
    {
        $membre = $this->creerContributeur('opale@example.net', $this->opale, 'Opale Tilleul');
        $this->creerUtilisateur('natron@example.net', ['ROLE_ADMIN'], 'Natron');

        $this->connexion('natron@example.net');
        $this->requete('POST', '/api/administration/voir-comme', ['memberId' => $this->idDuMembre($membre)]);
        self::assertResponseIsSuccessful();

        $this->requete('GET', '/api/assistant/conversations');
        self::assertResponseIsSuccessful();

        $this->requete('POST', '/api/assistant/conversations', ['question' => self::QUESTION]);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('vue_comme_membre_active', $this->erreur()['code']);

        $this->requete('POST', '/api/assistant/conversations/1/questions', ['question' => self::QUESTION]);
        self::assertResponseStatusCodeSame(409);

        $this->requete('DELETE', '/api/assistant/conversations/1');
        self::assertResponseStatusCodeSame(409);

        self::assertSame([], ModeleScripte::requetes());
    }

    public function testSansCleLAssistantEstIndisponibleEtNeConsommeRien(): void
    {
        $this->seConnecterCommeOpale();
        AccesAuModeleSimule::retirerLaCle();

        $this->requete('POST', '/api/assistant/conversations', ['question' => self::QUESTION]);

        self::assertResponseStatusCodeSame(503);
        self::assertSame('assistant_indisponible', $this->erreur()['code']);
        self::assertSame([], ModeleScripte::requetes());
        self::assertSame(60, $this->limiteur()->create((string) $this->idDOpale())->consume(0)->getRemainingTokens());
    }

    public function testUneQuestionInvalideRend422AvantToutAppel(): void
    {
        $this->seConnecterCommeOpale();

        foreach ([['question' => '   '], ['question' => str_repeat('a', 1001)], ['question' => 12], []] as $corps) {
            $this->requete('POST', '/api/assistant/conversations', $corps);
            self::assertResponseStatusCodeSame(422);
            self::assertArrayHasKey('question', $this->erreur()['champs']);
        }

        self::assertSame([], ModeleScripte::requetes());
        self::assertSame(60, $this->limiteur()->create((string) $this->idDOpale())->consume(0)->getRemainingTokens());
    }

    public function testLaSoixanteEtUniemeQuestionDuJourRend429(): void
    {
        $this->seConnecterCommeOpale();

        $limiteur = $this->limiteur()->create((string) $this->idDOpale());
        for ($i = 0; $i < 60; ++$i) {
            self::assertTrue($limiteur->consume()->isAccepted());
        }

        $this->requete('POST', '/api/assistant/conversations', ['question' => self::QUESTION]);

        self::assertResponseStatusCodeSame(429);
        self::assertNotNull($this->client->getResponse()->headers->get('Retry-After'));
        self::assertSame([], ModeleScripte::requetes());
    }

    public function testUnModeleEnPanneRend502EtNeLaisseAucuneConversation(): void
    {
        $this->seConnecterCommeOpale();
        ModeleScripte::repondra(new \RuntimeException('Délai dépassé.'));

        $this->requete('POST', '/api/assistant/conversations', ['question' => self::QUESTION]);

        self::assertResponseStatusCodeSame(502);
        self::assertSame('assistant_erreur', $this->erreur()['code']);
        self::assertSame(0, $this->em()->getRepository(Conversation::class)->count([]), 'Une question sans réponse ne laisse pas de conversation vide.');
    }

    // ─────────────────────────────────────────────────────────────────────────────
    //  La conversation
    // ─────────────────────────────────────────────────────────────────────────────

    public function testLeModeleNeVoitQueLeCercleDuLecteurEtLaReponseCiteLesFiches(): void
    {
        $this->seConnecterCommeOpale();
        ModeleScripte::repondra(
            ModeleScripte::appel('chercher_par_lieu', ['lieu' => 'brest', 'evenement' => 'naissance']),
            ModeleScripte::appel('chercher_personnes', ['requete' => 'Sureau']),
            ModeleScripte::appel('lire_personne', ['personId' => $this->tuf]),
            ModeleScripte::appel('lire_personne', ['personId' => $this->marne]),
            ModeleScripte::appel('repondre', [
                'texte' => \sprintf('D’après l’arbre, [Marne Dolomite Sureau](personne:%d) est née à Brest en 1887, sans source citée dans l’arbre. [Fantôme](personne:999999) n’existe pas.', $this->marne),
                'personIds' => [$this->marne, 999999],
            ]),
        );

        $this->requete('POST', '/api/assistant/conversations', ['question' => self::QUESTION]);
        self::assertResponseStatusCodeSame(201);
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));

        $charge = $this->charge();
        $conversation = $charge['conversation'];
        self::assertSame(59, $charge['restant']);
        self::assertSame(self::QUESTION, $conversation['titre']);
        self::assertSame(1, $conversation['nombreDEchanges']);
        self::assertCount(1, $conversation['echanges']);

        $echange = $conversation['echanges'][0];
        self::assertSame(self::QUESTION, $echange['question']);
        self::assertStringContainsString(\sprintf('[Marne Dolomite Sureau](personne:%d)', $this->marne), $echange['reponse']);
        self::assertStringContainsString('Fantôme n’existe pas', $echange['reponse'], 'Une mention vers une fiche inexistante perd son lien.');
        self::assertSame([[
            'personId' => $this->marne,
            'displayName' => 'Marne Dolomite Sureau',
            'visibilite' => 'complete',
            'ligneDates' => '1887 –',
        ]], $echange['personnes']);

        // ── Ce que le modèle a reçu : rien de Tuf, à aucun tour ─────────────────────
        $requetes = ModeleScripte::requetes();
        self::assertCount(5, $requetes);
        $tout = json_encode(array_column($requetes, 'charge'), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE);
        self::assertStringNotContainsString('Tuf', $tout, 'Le nom d’un vivant hors cercle ne part jamais chez le tiers.');
        self::assertStringNotContainsString('1990', $tout);
        self::assertStringNotContainsString('12 avril', $tout);

        // La recherche par lieu ne rend que Marne : Tuf, né à Brest, est absent.
        $parLieu = $requetes[1]['charge']['contents'][2]['parts'][0]['functionResponse']['response'];
        self::assertSame([$this->marne], array_column($parLieu['personnes'], 'personId'));
        self::assertFalse($parLieu['tronque']);

        // La recherche par nom aussi : absent, pas réduit.
        $parNom = $requetes[2]['charge']['contents'][4]['parts'][0]['functionResponse']['response'];
        self::assertSame([$this->marne], array_column($parNom['personnes'], 'personId'));

        // Sa fiche, lue par son numéro : la fiche réduite, et rien d'autre.
        $ficheDeTuf = $requetes[3]['charge']['contents'][6]['parts'][0]['functionResponse']['response'];
        self::assertSame(['personId', 'nom', 'vivant', 'confidentielle', 'remarque'], array_keys($ficheDeTuf));
        self::assertSame(CercleDeVisibiliteInterface::NOM_MASQUE, $ficheDeTuf['nom']);

        // La fiche de Marne porte ses sources (aucune ici) et le lien avec le lecteur.
        $ficheDeMarne = $requetes[4]['charge']['contents'][8]['parts'][0]['functionResponse']['response'];
        self::assertSame([], $ficheDeMarne['sources']);
        self::assertArrayHasKey('parenteAvecLeLecteur', $ficheDeMarne);

        // Le lecteur est situé, et les outils offerts sont de lecture : aucun n'écrit.
        self::assertStringContainsString(\sprintf('personne n° %d', $this->opale), $requetes[0]['charge']['contents'][0]['parts'][0]['text']);
        self::assertSame(
            ['chercher_personnes', 'lire_personne', 'chercher_par_lieu', 'repondre'],
            array_column($requetes[0]['charge']['tools'][0]['functionDeclarations'], 'name'),
        );
        self::assertSame(0, $this->em()->getRepository(ChangeRequest::class)->count([]), 'Lecture seule : aucune demande déposée.');
    }

    public function testLaRechercheParLieuIgnoreAccentsEtCasseEtSuitLaPeriode(): void
    {
        $this->seConnecterCommeOpale();
        ModeleScripte::repondra(
            ModeleScripte::appel('chercher_par_lieu', ['lieu' => 'FINISTERE brest', 'anneeMin' => 1880, 'anneeMax' => 1890]),
            ModeleScripte::appel('chercher_par_lieu', ['lieu' => 'finistere', 'anneeMax' => 1800.0]),
            ModeleScripte::appel('chercher_par_lieu', ['anneeMin' => 1880, 'anneeMax' => 1890, 'evenement' => 'naissance']),
            ModeleScripte::appel('repondre', ['texte' => \sprintf('[Marne Dolomite Sureau](personne:%d).', $this->marne), 'personIds' => [$this->marne]]),
        );

        $this->requete('POST', '/api/assistant/conversations', ['question' => 'Qui est né dans le Finistère vers 1885 ?']);
        self::assertResponseStatusCodeSame(201);

        $requetes = ModeleScripte::requetes();
        self::assertSame([$this->marne], array_column($requetes[1]['charge']['contents'][2]['parts'][0]['functionResponse']['response']['personnes'], 'personId'), '« FINISTERE brest » trouve « Brest, Finistère » : ni accents, ni casse, ni ordre.');
        self::assertSame([], $requetes[2]['charge']['contents'][4]['parts'][0]['functionResponse']['response']['personnes'], 'Hors de la période, personne.');
        self::assertSame([$this->marne], array_column($requetes[3]['charge']['contents'][6]['parts'][0]['functionResponse']['response']['personnes'], 'personId'), 'Une période seule suffit ; Tuf, hors cercle, n’y est de toute façon pas.');
    }

    public function testUneQuestionDeSuiviRecoitLHistoriqueEtLaConversationSeRelit(): void
    {
        $this->seConnecterCommeOpale();
        $id = $this->premiereConversation();

        ModeleScripte::repondra(ModeleScripte::appel('repondre', [
            'texte' => 'L’arbre ne dit pas qui était son père : je ne sais pas.',
            'personIds' => [],
        ]));

        $this->requete('POST', \sprintf('/api/assistant/conversations/%d/questions', $id), ['question' => 'Et son père ?']);
        self::assertResponseStatusCodeSame(201);
        self::assertSame(2, $this->charge()['conversation']['nombreDEchanges']);
        self::assertSame(self::QUESTION, $this->charge()['conversation']['titre'], 'Le titre reste la première question.');

        // Le modèle a reçu l'échange précédent, en tours user / model, avant la question.
        $requetes = ModeleScripte::requetes();
        self::assertCount(3, $requetes, 'Deux appels pour la première question, un pour la seconde.');
        $contenus = $requetes[2]['charge']['contents'];
        self::assertSame(['user', 'model', 'user'], array_column($contenus, 'role'));
        self::assertSame(self::QUESTION, $contenus[0]['parts'][0]['text']);
        self::assertStringContainsString('Marne Dolomite Sureau', $contenus[1]['parts'][0]['text']);
        self::assertStringContainsString(\sprintf('Fiches citées : %d.', $this->marne), $contenus[1]['parts'][0]['text']);
        self::assertStringContainsString('« Et son père ? »', $contenus[2]['parts'][0]['text']);

        // La liste, puis le détail.
        $this->requete('GET', '/api/assistant/conversations');
        self::assertResponseIsSuccessful();
        $elements = $this->charge()['elements'];
        self::assertCount(1, $elements);
        self::assertSame(['id', 'titre', 'createdAt', 'updatedAt', 'nombreDEchanges'], array_keys($elements[0]));
        self::assertSame(2, $elements[0]['nombreDEchanges']);

        $this->requete('GET', \sprintf('/api/assistant/conversations/%d', $id));
        self::assertResponseIsSuccessful();
        self::assertSame(['Qui est né à Brest ?', 'Et son père ?'], array_column($this->charge()['conversation']['echanges'], 'question'));
    }

    public function testUneMentionDUnVivantHorsCercleEstMasqueeALaLecture(): void
    {
        // Le modèle n'a pas reçu ce nom ; mais une réponse gardée peut citer quelqu'un
        // qu'un cercle rétréci ne montre plus. Le contrôle tient à la lecture.
        $this->seConnecterCommeOpale();
        ModeleScripte::repondra(ModeleScripte::appel('repondre', [
            'texte' => \sprintf('Voir [Tuf Sureau](personne:%d).', $this->tuf),
            'personIds' => [$this->tuf],
        ]));

        $this->requete('POST', '/api/assistant/conversations', ['question' => 'Qui est Tuf ?']);
        self::assertResponseStatusCodeSame(201);

        $echange = $this->charge()['conversation']['echanges'][0];
        self::assertSame(\sprintf('Voir [%s](personne:%d).', CercleDeVisibiliteInterface::NOM_MASQUE, $this->tuf), $echange['reponse']);
        self::assertSame([[
            'personId' => $this->tuf,
            'displayName' => CercleDeVisibiliteInterface::NOM_MASQUE,
            'visibilite' => 'restreinte',
        ]], $echange['personnes']);
        self::assertStringNotContainsString(self::NAISSANCE_MASQUEE, (string) $this->client->getResponse()->getContent());
    }

    public function testLaConversationDUnAutreEstIntrouvableEtSeSupprimeParSonAuteur(): void
    {
        $this->seConnecterCommeOpale();
        $id = $this->premiereConversation();

        $this->deconnexion();
        $this->creerContributeur('tuf@example.net', $this->tuf, 'Tuf Sureau');
        $this->connexion('tuf@example.net');

        foreach ([['GET', ''], ['DELETE', ''], ['POST', '/questions']] as [$methode, $suite]) {
            $this->requete($methode, \sprintf('/api/assistant/conversations/%d%s', $id, $suite), 'POST' === $methode ? ['question' => 'Et après ?'] : null);
            self::assertResponseStatusCodeSame(404, 'La conversation d’un autre n’existe pas pour ce compte — 404, pas 403.');
        }

        $this->requete('GET', '/api/assistant/conversations');
        self::assertSame([], $this->charge()['elements']);
        self::assertCount(2, ModeleScripte::requetes(), 'Aucun appel pour la conversation d’un autre : seuls restent les deux de la première question.');

        $this->deconnexion();
        $this->connexion('opale@example.net');
        $this->requete('DELETE', \sprintf('/api/assistant/conversations/%d', $id));
        self::assertResponseStatusCodeSame(204);

        $this->requete('GET', \sprintf('/api/assistant/conversations/%d', $id));
        self::assertResponseStatusCodeSame(404);
        self::assertSame(0, $this->em()->getRepository(Echange::class)->count([]), 'Les échanges partent avec la conversation.');
    }

    public function testAucunJournalNeContientLaQuestionNiLaReponse(): void
    {
        $this->seConnecterCommeOpale();
        $id = $this->premiereConversation();

        $this->requete('DELETE', \sprintf('/api/assistant/conversations/%d', $id));
        self::assertResponseStatusCodeSame(204);

        $lignes = $this->em()->getRepository(AuditLog::class)->findAll();
        $journal = json_encode(array_map(static fn (AuditLog $l): array => [
            $l->getSubjectType(), $l->getSubjectId(), $l->getSubjectLabel(), $l->getChanges(),
        ], $lignes), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE);

        self::assertStringNotContainsString('Brest', $journal, 'Ni la question…');
        self::assertStringNotContainsString('1887', $journal, '… ni la réponse…');
        self::assertStringNotContainsString('Marne', $journal, '… ni les fiches citées.');

        $appel = $this->em()->getRepository(AuditLog::class)->findOneBy(['action' => AuditAction::AppelModeleDeLangage, 'subjectType' => 'Assistant\\Question']);
        self::assertNotNull($appel, 'L’appel au tiers est compté.');
        self::assertSame((string) $id, $appel->getSubjectId());
        self::assertSame(['nouvelle_conversation', 'echanges_transmis', 'tours', 'appels_d_outils', 'personnes_citees'], array_keys($appel->getChanges() ?? []));
        self::assertSame(1, $appel->getChanges()['personnes_citees'] ?? null);
    }

    public function testSousIncarnationLaConversationEstCelleDuMembreEtLeJournalNommeLAdministrateur(): void
    {
        $this->creerContributeur('opale@example.net', $this->opale, 'Opale Tilleul');
        $admin = $this->creerUtilisateur('natron@example.net', ['ROLE_ADMIN'], 'Natron');

        $this->connexion('natron@example.net');
        $this->requete('POST', '/api/administration/incarnation', ['userId' => $this->idDOpale()]);
        self::assertResponseIsSuccessful();

        $id = $this->premiereConversation();

        $conversation = $this->em()->getRepository(Conversation::class)->find($id);
        self::assertInstanceOf(Conversation::class, $conversation);
        self::assertSame($this->idDOpale(), $conversation->getUserId());

        $creation = $this->em()->getRepository(AuditLog::class)->findOneBy(['action' => AuditAction::Creation, 'subjectType' => 'Assistant\\Conversation']);
        self::assertNotNull($creation);
        self::assertSame($this->idDOpale(), $creation->getActorUserId());
        self::assertSame($admin->getId(), $creation->getImpersonatorUserId());
    }

    // ─────────────────────────────────────────────────────────────────────────────
    //  Le décor
    // ─────────────────────────────────────────────────────────────────────────────

    /** Une première question, à une seule recherche, et l'identifiant de sa conversation. */
    private function premiereConversation(): int
    {
        ModeleScripte::repondra(
            ModeleScripte::appel('chercher_par_lieu', ['lieu' => 'Brest']),
            ModeleScripte::appel('repondre', [
                'texte' => \sprintf('[Marne Dolomite Sureau](personne:%d), en 1887.', $this->marne),
                'personIds' => [$this->marne],
            ]),
        );

        $this->requete('POST', '/api/assistant/conversations', ['question' => self::QUESTION]);
        self::assertResponseStatusCodeSame(201);

        $id = $this->charge()['conversation']['id'];
        self::assertIsInt($id);

        return $id;
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
        $limiteur = self::getContainer()->get('assistant.limiteur_questions');
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
}
