<?php

declare(strict_types=1);

namespace App\Tests\Membership;

use App\Module\Core\Enum\UserStatus;
use App\Module\Core\Repository\UserRepository;
use App\Module\Membership\Entity\Member;
use App\Module\Membership\Enum\MemberStatus;
use App\Module\Membership\Repository\MemberRepository;
use App\Module\Membership\Service\MessageVocalDeDeclaration;
use App\Tests\Assistant\Double\ComptesARattacherEnMemoire;
use App\Tests\Assistant\Double\ModeleScripte;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Le **message vocal** de l'inscription sans fiche (ADR 0013, addendum du 2 octobre 2026).
 *
 * « Racontez-nous qui vous êtes » — par écrit, ou à voix haute. Le fichier part avec la
 * déclaration, en `multipart`, dans le même `POST /api/inscription/compte`. Ce que ce
 * fichier verrouille :
 *
 * - **texte OU vocal OU les deux** : le vocal rend la description facultative, et sans lui
 *   les 60 caractères tiennent toujours ;
 * - **le type se lit sur les octets** — un PNG renommé `.webm`, annoncé `audio/webm`, est
 *   refusé —, et la taille est bornée à 10 Mo ;
 * - **pas de jeton valide, pas de dépôt** : rien n'est écrit, ni ligne ni fichier ;
 * - **le son ne sort que vers un administrateur**, par une route à lui, requêtes partielles
 *   comprises — et ni un membre, ni le déposant lui-même, ni un anonyme ne l'obtiennent ;
 * - **il part avec la déclaration**, au rattachement comme au refus — fichier compris ;
 * - **sa transcription** (2 octobre 2026, soir) se range sur la ligne par la route de
 *   l'assistant — modèle scripté, `Membership` réel —, sort dans la liste en liste
 *   blanche, et part avec le vocal ;
 * - **sa correction à la main** (3 octobre 2026) se range sur la ligne, datée, sans que le
 *   journal mécanique en garde le texte, et part avec le vocal.
 *
 * `MEDIA_RACINE` est détournée vers `var/`, comme dans `RattachementDesComptesSansFicheTest` :
 * les messages vocaux vivent sous `messages-vocaux/` de cette racine.
 */
final class MessageVocalDeLInscriptionTest extends CasDeTestMembership
{
    private const string MOT_DE_PASSE = 'un-mot-de-passe-de-douze-signes';

    /** Un en-tête EBML suivi de remplissage : le serveur ne lit que la signature. */
    private const string WEBM = "\x1A\x45\xDF\xA3\x9F\x42\x86\x81\x01\x42\xF7\x81\x01\x42\xF2\x81";

    private string $ip;

    protected function setUp(): void
    {
        // AVANT `createClient()` : le conteneur lit l'environnement au premier appel.
        $racine = self::racineDesMedias();
        $_ENV['MEDIA_RACINE'] = $racine;
        $_SERVER['MEDIA_RACINE'] = $racine;

        parent::setUp();

        // La transcription passe par le module `Assistant` : son côté `Membership` doit
        // être le vrai (un test précédent a pu laisser le double en mémoire), et le modèle
        // est scripté.
        ComptesARattacherEnMemoire::brancherSurMembership();
        ModeleScripte::oublierTout();

        // Une adresse à ce test : le plafond de cinq comptes sans fiche par heure et par IP
        // ne doit pas dépendre de l'ordre d'exécution.
        $this->ip = \sprintf('10.%d.%d.%d', random_int(0, 255), random_int(0, 255), random_int(1, 254));
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        self::effacer(self::racineDesMedias());
        unset($_ENV['MEDIA_RACINE'], $_SERVER['MEDIA_RACINE']);
    }

    // ── Le type, lu sur les octets ────────────────────────────────────────────────

    public function testLeTypeSeLitSurLesOctets(): void
    {
        self::assertSame('audio/webm', MessageVocalDeDeclaration::typeRenifle(self::WEBM));
        self::assertSame('audio/ogg', MessageVocalDeDeclaration::typeRenifle("OggS\x00\x02".str_repeat("\x00", 10)));
        // Safari, iOS compris : MP4/AAC, marques `mp42`, `isom`, `M4A `…
        self::assertSame('audio/mp4', MessageVocalDeDeclaration::typeRenifle("\x00\x00\x00\x1Cftypmp42\x00\x00\x00\x00"));
        self::assertSame('audio/mp4', MessageVocalDeDeclaration::typeRenifle("\x00\x00\x00\x20ftypM4A \x00\x00\x00\x00"));

        // Une photo HEIC a la structure d'un MP4 : ce n'est pas un enregistrement.
        self::assertNull(MessageVocalDeDeclaration::typeRenifle("\x00\x00\x00\x18ftypheic\x00\x00\x00\x00"));
        self::assertNull(MessageVocalDeDeclaration::typeRenifle("\x89PNG\r\n\x1A\n\x00\x00\x00\x0DIHDR"));
        self::assertNull(MessageVocalDeDeclaration::typeRenifle('<?php echo 1; ?>'));
        self::assertNull(MessageVocalDeDeclaration::typeRenifle(''));
    }

    // ── Texte OU vocal OU les deux ────────────────────────────────────────────────

    public function testLeVocalSeulSuffitADeclarerSaFamille(): void
    {
        [, $jeton] = $this->invitation();

        $this->envoyer($jeton, description: '', vocal: $this->fichier(self::WEBM.str_repeat("\x00", 2048)), duree: '42');

        self::assertSame(201, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());

        $membre = $this->membreDe('lave@example.net');
        self::assertSame(MemberStatus::EnAttente, $membre->getStatus());
        self::assertNull($membre->getFamilyDescription(), 'Rien d’écrit se range à null, pas à une chaîne vide.');
        self::assertSame('Romarin', $membre->getDeclaredSurname());
        self::assertSame('audio/webm', $membre->getVoiceMessageMime());
        self::assertSame(\strlen(self::WEBM) + 2048, $membre->getVoiceMessageSize());
        self::assertSame(42, $membre->getVoiceMessageSeconds());

        $nom = $membre->getVoiceMessageName();
        self::assertNotNull($nom);
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}\.webm$/', $nom, 'Un nom aléatoire, jamais celui du client.');
        self::assertFileExists($this->cheminDuVocal($nom));
    }

    public function testUnTexteCourtSuffitQuandUnVocalLAccompagne(): void
    {
        [, $jeton] = $this->invitation();

        $this->envoyer($jeton, description: 'Voir mon message.', vocal: $this->fichier(self::WEBM));

        self::assertSame(201, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
        self::assertSame('Voir mon message.', $this->membreDe('lave@example.net')->getFamilyDescription());
        self::assertNull($this->membreDe('lave@example.net')->getVoiceMessageSeconds(), 'Sans durée annoncée, pas de durée.');
    }

    public function testSansVocalLeTexteResteObligatoire(): void
    {
        [, $jeton] = $this->invitation();

        $this->envoyer($jeton, description: 'Trop court.');

        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        self::assertSame(['declaration.description'], array_keys($this->champsEnErreur()));
        self::assertCount(0, $this->em()->getRepository(Member::class)->findAll());
    }

    // ── Les refus du fichier ──────────────────────────────────────────────────────

    /** Un PNG renommé `.webm` et annoncé `audio/webm` : refusé, et le texte court aussi. */
    public function testUnFichierQuiNEstPasUnEnregistrementEstRefuse(): void
    {
        [, $jeton] = $this->invitation();

        $this->envoyer($jeton, description: 'Court.', vocal: $this->fichier("\x89PNG\r\n\x1A\n".str_repeat("\x00", 64)));

        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        $champs = $this->champsEnErreur();
        self::assertArrayHasKey('declaration.messageVocal', $champs);
        // Un vocal refusé ne rend pas le texte facultatif : les deux erreurs viennent
        // ensemble, dans le même `422`.
        self::assertArrayHasKey('declaration.description', $champs);
        self::assertCount(0, $this->em()->getRepository(Member::class)->findAll());
        self::assertSame([], $this->vocauxSurLeDisque(), 'Rien n’est écrit tant que tout n’est pas valide.');
    }

    public function testUnVocalDePlusDeDixMoEstRefuse(): void
    {
        [, $jeton] = $this->invitation();

        $trop = self::WEBM.str_repeat("\x00", MessageVocalDeDeclaration::TAILLE_MAX);
        $this->envoyer($jeton, description: self::description(), vocal: $this->fichier($trop));

        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        self::assertSame(['declaration.messageVocal'], array_keys($this->champsEnErreur()));
        self::assertSame([], $this->vocauxSurLeDisque());
    }

    public function testUneDureeDePlusDeCinqMinutesEstRefusee(): void
    {
        [, $jeton] = $this->invitation();

        $this->envoyer($jeton, description: '', vocal: $this->fichier(self::WEBM), duree: '900');

        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        self::assertArrayHasKey('declaration.messageVocal', $this->champsEnErreur());
    }

    /** Pas de jeton valide, pas de dépôt : le même `404` qu'ailleurs, et rien sur le disque. */
    public function testSansJetonValideRienNEstDepose(): void
    {
        $this->invitation();

        $this->envoyer('jeton-invente-'.str_repeat('x', 30), description: '', vocal: $this->fichier(self::WEBM));

        self::assertSame(404, $this->client->getResponse()->getStatusCode());
        self::assertCount(0, $this->em()->getRepository(Member::class)->findAll());
        self::assertSame([], $this->vocauxSurLeDisque());
    }

    /** Un vocal n'accompagne qu'une déclaration : avec un `choix`, il est refusé. */
    public function testUnVocalSansDeclarationEstRefuse(): void
    {
        [, $jeton] = $this->invitation();

        $this->client->request(
            'POST',
            '/api/inscription/compte',
            parameters: ['donnees' => json_encode([
                'jeton' => $jeton,
                'choix' => 'une-reference',
                'email' => 'lave@example.net',
                'password' => self::MOT_DE_PASSE,
            ], \JSON_THROW_ON_ERROR)],
            files: ['messageVocal' => $this->fichier(self::WEBM)],
            server: ['HTTP_ACCEPT' => 'application/json', 'REMOTE_ADDR' => $this->ip],
        );

        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        self::assertArrayHasKey('messageVocal', $this->champsEnErreur());
    }

    // ── Côté administrateur ───────────────────────────────────────────────────────

    public function testLAdministrateurEcouteLeVocalEtLuiSeul(): void
    {
        $membreId = $this->compteAvecVocal();
        $membre = $this->membre($membreId);

        // La liste décrit le vocal ; elle ne le contient pas.
        $this->connexion('admin@example.net');
        $this->requete('GET', '/api/administration/rattachements');
        $element = $this->charge()['elements'][0];
        self::assertSame([
            'url' => \sprintf('/api/administration/rattachements/%d/message-vocal', $membreId),
            'type' => 'audio/webm',
            'taille' => $membre->getVoiceMessageSize(),
            'duree' => 42,
            // Jamais tentée : un clic la lancera.
            'transcription' => ['statut' => 'a_faire', 'texte' => null, 'erreur' => null, 'le' => null, 'corrigeeLe' => null],
        ], $element['messageVocal']);

        // Le son, en entier…
        $this->client->request('GET', $element['messageVocal']['url']);
        $reponse = $this->client->getResponse();
        self::assertSame(200, $reponse->getStatusCode());
        self::assertSame('audio/webm', $reponse->headers->get('Content-Type'));
        self::assertSame('nosniff', $reponse->headers->get('X-Content-Type-Options'));
        self::assertStringContainsString('no-store', (string) $reponse->headers->get('Cache-Control'));
        self::assertStringNotContainsString('lave', (string) $reponse->headers->get('Content-Disposition'));

        // … et par morceaux : un `<audio>` se déplace dans l'enregistrement par `Range`.
        $this->client->request('GET', $element['messageVocal']['url'], server: ['HTTP_RANGE' => 'bytes=0-3']);
        self::assertSame(206, $this->client->getResponse()->getStatusCode());
        self::assertSame('bytes 0-3/'.$membre->getVoiceMessageSize(), $this->client->getResponse()->headers->get('Content-Range'));

        // Un membre actif n'y a pas accès — `^/api/administration` est `ROLE_ADMIN`.
        $this->creerUtilisateur('membre@example.net');
        $this->connexion('membre@example.net');
        $this->client->request('GET', $element['messageVocal']['url']);
        self::assertSame(403, $this->client->getResponse()->getStatusCode());

        // Le déposant lui-même non plus : sa voix ne lui revient pas par l'API.
        $this->connexion('lave@example.net');
        $this->client->request('GET', $element['messageVocal']['url']);
        self::assertContains($this->client->getResponse()->getStatusCode(), [401, 403]);
    }

    public function testUnAnonymeNObtientRien(): void
    {
        $membreId = $this->compteAvecVocal();
        $this->client->getCookieJar()->clear();

        $this->client->request('GET', \sprintf('/api/administration/rattachements/%d/message-vocal', $membreId));

        self::assertSame(401, $this->client->getResponse()->getStatusCode());
    }

    public function testUnCompteSansVocalRend404(): void
    {
        $this->creerUtilisateur('admin@example.net', ['ROLE_ADMIN']);
        $compte = $this->creerUtilisateur('lave@example.net', statut: UserStatus::EnAttente);
        $membre = $this->creerMembreSansFiche($compte);

        $this->connexion('admin@example.net');
        $this->client->request('GET', \sprintf('/api/administration/rattachements/%d/message-vocal', $membre->getId()));

        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    // ── Il part avec la déclaration ───────────────────────────────────────────────

    public function testLeRefusEffaceLeVocalFichierCompris(): void
    {
        $membreId = $this->compteAvecVocal();
        $nom = $this->membre($membreId)->getVoiceMessageName();
        self::assertNotNull($nom);
        self::assertFileExists($this->cheminDuVocal($nom));

        $this->connexion('admin@example.net');
        $this->requete('POST', \sprintf('/api/administration/membres/%d/refuser', $membreId));
        self::assertResponseIsSuccessful();

        $membre = $this->membre($membreId);
        self::assertNull($membre->getVoiceMessageName());
        self::assertNull($membre->getVoiceMessageMime());
        self::assertNull($membre->getVoiceMessageSize());
        self::assertFileDoesNotExist($this->cheminDuVocal($nom));

        $this->client->request('GET', \sprintf('/api/administration/rattachements/%d/message-vocal', $membreId));
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    public function testLeRattachementEffaceLeVocalFichierCompris(): void
    {
        $membreId = $this->compteAvecVocal();
        $nom = $this->membre($membreId)->getVoiceMessageName();
        self::assertNotNull($nom);

        $this->connexion('admin@example.net');
        $this->requete('POST', \sprintf('/api/administration/membres/%d/activer', $membreId), ['personId' => ArbreFamilial::ETRANGER]);
        self::assertResponseIsSuccessful();

        self::assertNull($this->membre($membreId)->getVoiceMessageName());
        self::assertFileDoesNotExist($this->cheminDuVocal($nom));
    }

    // ── Sa transcription ──────────────────────────────────────────────────────────

    public function testLaTranscriptionSeRangeSurLaLigneEtSortDansLaListe(): void
    {
        $membreId = $this->compteAvecVocal();
        $this->connexion('admin@example.net');

        // Un échec d'abord : rangé avec son motif, et l'audio toujours servi.
        ModeleScripte::repondra(new \RuntimeException('Panne.'));
        $this->transcrire($membreId);
        self::assertResponseIsSuccessful();
        self::assertSame('echec', $this->charge()['statut']);

        $membre = $this->membre($membreId);
        self::assertNull($membre->getVoiceMessageTranscript());
        self::assertNotNull($membre->getVoiceMessageTranscriptError());
        self::assertNotNull($membre->getVoiceMessageTranscribedAt());
        $this->client->request('GET', \sprintf('/api/administration/rattachements/%d/message-vocal', $membreId));
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), 'L’audio reste écoutable quoi qu’il arrive.');

        // « Réessayer » : le texte remplace l'échec.
        ModeleScripte::repondra(self::reponseTexte('Mon père est Pyrite Romarin.'));
        $this->transcrire($membreId);
        self::assertResponseIsSuccessful();

        $membre = $this->membre($membreId);
        self::assertSame('Mon père est Pyrite Romarin.', $membre->getVoiceMessageTranscript());
        self::assertNull($membre->getVoiceMessageTranscriptError());

        // Le son envoyé est celui du disque, octet pour octet.
        $envoye = ModeleScripte::requetes()[1]['charge']['contents'][0]['parts'][1]['inlineData'];
        self::assertSame('audio/webm', $envoye['mimeType']);
        self::assertSame(self::WEBM.str_repeat("\x00", 4096), base64_decode($envoye['data'], true));

        $this->requete('GET', '/api/administration/rattachements');
        $transcription = $this->charge()['elements'][0]['messageVocal']['transcription'];
        self::assertSame(['statut', 'texte', 'erreur', 'le', 'corrigeeLe'], array_keys($transcription));
        self::assertNull($transcription['corrigeeLe']);
        self::assertSame('faite', $transcription['statut']);
        self::assertSame('Mon père est Pyrite Romarin.', $transcription['texte']);
        self::assertNull($transcription['erreur']);

        // Le texte n'entre pas dans le journal mécanique.
        $journal = (string) json_encode($this->em()->getConnection()->fetchAllAssociative('SELECT changes FROM core_audit_log'));
        self::assertStringNotContainsString('Pyrite', $journal);
    }

    public function testLeRefusEffaceLaTranscriptionAvecLeVocal(): void
    {
        $membreId = $this->compteAvecVocal();
        $this->connexion('admin@example.net');
        ModeleScripte::repondra(self::reponseTexte('Mon père est Pyrite Romarin.'));
        $this->transcrire($membreId);
        self::assertResponseIsSuccessful();

        $this->requete('POST', \sprintf('/api/administration/membres/%d/refuser', $membreId));
        self::assertResponseIsSuccessful();

        $membre = $this->membre($membreId);
        self::assertNull($membre->getVoiceMessageTranscript());
        self::assertNull($membre->getVoiceMessageTranscriptError());
        self::assertNull($membre->getVoiceMessageTranscribedAt());

        // Plus de vocal, plus rien à transcrire.
        $this->transcrire($membreId);
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    public function testLeRattachementEffaceLaTranscription(): void
    {
        $membreId = $this->compteAvecVocal();
        $this->connexion('admin@example.net');
        ModeleScripte::repondra(self::reponseTexte('Mon père est Pyrite Romarin.'));
        $this->transcrire($membreId);
        self::assertResponseIsSuccessful();

        $this->requete('POST', \sprintf('/api/administration/membres/%d/activer', $membreId), ['personId' => ArbreFamilial::ETRANGER]);
        self::assertResponseIsSuccessful();

        self::assertNull($this->membre($membreId)->getVoiceMessageTranscript());
    }

    public function testLaCorrectionSeRangeSurLaLigneSansEntrerAuJournal(): void
    {
        $membreId = $this->compteAvecVocal();
        $this->connexion('admin@example.net');
        ModeleScripte::repondra(self::reponseTexte('Mon père est Pyrite Romarain [?].'));
        $this->transcrire($membreId);
        self::assertResponseIsSuccessful();

        $this->corriger($membreId, 'Mon père est Pyrite Romarin, né à Quimperlé.');
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());

        $membre = $this->membre($membreId);
        self::assertSame('Mon père est Pyrite Romarin, né à Quimperlé.', $membre->getVoiceMessageTranscript());
        self::assertNull($membre->getVoiceMessageTranscriptError());
        self::assertNotNull($membre->getVoiceMessageTranscriptEditedAt());
        self::assertNotNull($membre->getVoiceMessageTranscribedAt());

        // La liste le dit.
        $this->requete('GET', '/api/administration/rattachements');
        $transcription = $this->charge()['elements'][0]['messageVocal']['transcription'];
        self::assertSame('faite', $transcription['statut']);
        self::assertSame('Mon père est Pyrite Romarin, né à Quimperlé.', $transcription['texte']);
        self::assertIsString($transcription['corrigeeLe']);

        // Journalisée — qui, quand —, jamais quoi : ni le texte de Gemini, ni la correction.
        $lignes = $this->em()->getConnection()->fetchAllAssociative(
            "SELECT changes FROM core_audit_log WHERE action = 'modification' AND subject_type LIKE '%Member'",
        );
        $journal = (string) json_encode($lignes);
        self::assertStringContainsString('voiceMessageTranscriptEditedAt', $journal);
        self::assertStringNotContainsString('Quimperl', $journal);
        self::assertStringNotContainsString('Pyrite', (string) json_encode($this->em()->getConnection()->fetchAllAssociative('SELECT changes FROM core_audit_log')));

        // Une retranscription efface la marque.
        ModeleScripte::repondra(self::reponseTexte('Mon père est Pyrite Romarain [?].'));
        $this->transcrire($membreId);
        self::assertNull($this->membre($membreId)->getVoiceMessageTranscriptEditedAt());

        // Et le refus emporte tout.
        $this->corriger($membreId, 'Mon père est Pyrite Romarin.');
        $this->requete('POST', \sprintf('/api/administration/membres/%d/refuser', $membreId));
        self::assertResponseIsSuccessful();
        $membre = $this->membre($membreId);
        self::assertNull($membre->getVoiceMessageTranscript());
        self::assertNull($membre->getVoiceMessageTranscriptEditedAt());

        $this->corriger($membreId, 'Trop tard.');
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    // ── Outils ────────────────────────────────────────────────────────────────────

    private function corriger(int $membreId, string $texte): void
    {
        $this->client->request(
            'PATCH',
            \sprintf('/api/administration/rattachements/%d/transcription', $membreId),
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'REMOTE_ADDR' => $this->ip],
            content: json_encode(['texte' => $texte], \JSON_THROW_ON_ERROR),
        );
    }

    private function transcrire(int $membreId): void
    {
        $this->client->request(
            'POST',
            \sprintf('/api/administration/rattachements/%d/transcription', $membreId),
            server: ['HTTP_ACCEPT' => 'application/json', 'REMOTE_ADDR' => $this->ip],
        );
    }

    /** @return array<string, mixed> une réponse Gemini qui rend du texte */
    private static function reponseTexte(string $texte): array
    {
        return ['candidates' => [['content' => ['parts' => [['text' => $texte]]], 'finishReason' => 'STOP']]];
    }

    /** @return array{0: \App\Module\Membership\Entity\Invitation, 1: string} */
    private function invitation(): array
    {
        $administrateur = $this->creerUtilisateur('admin@example.net', ['ROLE_ADMIN']);

        return $this->creerInvitation($administrateur, maxUses: null);
    }

    /** Un compte sans fiche inscrit **par la route**, avec un vocal de 42 s ; rend son `memberId`. */
    private function compteAvecVocal(): int
    {
        [, $jeton] = $this->invitation();
        $this->envoyer($jeton, description: '', vocal: $this->fichier(self::WEBM.str_repeat("\x00", 4096)), duree: '42');
        self::assertSame(201, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());

        $id = $this->membreDe('lave@example.net')->getId();
        \assert(null !== $id);

        return $id;
    }

    /** `POST /api/inscription/compte` en `multipart` — le corps JSON dans `donnees`. */
    private function envoyer(string $jeton, string $description, ?UploadedFile $vocal = null, ?string $duree = null): void
    {
        $declaration = [
            'surname' => 'Romarin',
            'givenNames' => 'Lave',
            'birthDate' => '1998-04-12',
            'description' => $description,
        ];

        if (null !== $duree) {
            $declaration['dureeMessageVocal'] = $duree;
        }

        $this->client->request(
            'POST',
            '/api/inscription/compte',
            parameters: ['donnees' => json_encode([
                'jeton' => $jeton,
                'declaration' => $declaration,
                'email' => 'lave@example.net',
                'password' => self::MOT_DE_PASSE,
            ], \JSON_THROW_ON_ERROR)],
            files: null !== $vocal ? ['messageVocal' => $vocal] : [],
            server: ['HTTP_ACCEPT' => 'application/json', 'REMOTE_ADDR' => $this->ip],
        );
    }

    /** Toujours annoncé `audio/webm`, nommé `.webm` : seul le contenu doit décider. */
    private function fichier(string $octets): UploadedFile
    {
        $chemin = self::racineDesMedias().'/entree-'.bin2hex(random_bytes(6)).'.webm';

        if (!is_dir(\dirname($chemin))) {
            mkdir(\dirname($chemin), 0o777, true);
        }

        file_put_contents($chemin, $octets);

        return new UploadedFile($chemin, 'message.webm', 'audio/webm', null, true);
    }

    private static function description(): string
    {
        return 'Mon père est Pyrite Romarin, né en 1956 à Lille ; ma mère Cobalt Cassis ; mon grand-père Jaspe.';
    }

    private function cheminDuVocal(string $nom): string
    {
        return \sprintf('%s/messages-vocaux/%s/%s', self::racineDesMedias(), substr($nom, 0, 2), $nom);
    }

    /** @return list<string> les messages vocaux présents sur le disque */
    private function vocauxSurLeDisque(): array
    {
        $dossier = self::racineDesMedias().'/messages-vocaux';

        if (!is_dir($dossier)) {
            return [];
        }

        $noms = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dossier, \FilesystemIterator::SKIP_DOTS)) as $fichier) {
            if ($fichier instanceof \SplFileInfo && $fichier->isFile()) {
                $noms[] = $fichier->getFilename();
            }
        }

        return $noms;
    }

    /** @return array<string, mixed> */
    private function champsEnErreur(): array
    {
        $corps = $this->charge();
        self::assertIsArray($corps['erreur']);
        self::assertSame('donnees_invalides', $corps['erreur']['code']);
        self::assertIsArray($corps['erreur']['champs']);

        return $corps['erreur']['champs'];
    }

    private function membreDe(string $courriel): Member
    {
        $depot = self::getContainer()->get(UserRepository::class);
        \assert($depot instanceof UserRepository);
        $id = $depot->findOneBy(['email' => $courriel])?->getId();
        self::assertNotNull($id);

        $membres = self::getContainer()->get(MemberRepository::class);
        \assert($membres instanceof MemberRepository);
        $this->em()->clear();
        $membre = $membres->pourUtilisateur($id);
        self::assertNotNull($membre);

        return $membre;
    }

    private function membre(int $membreId): Member
    {
        $em = $this->em();
        $em->clear();
        $membre = $em->find(Member::class, $membreId);
        self::assertInstanceOf(Member::class, $membre);

        return $membre;
    }

    private static function racineDesMedias(): string
    {
        return \dirname(__DIR__, 2).'/var/test-medias-vocal-'.getmypid();
    }

    private static function effacer(string $dossier): void
    {
        if (!is_dir($dossier)) {
            return;
        }

        $iterateur = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dossier, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterateur as $entree) {
            if ($entree instanceof \SplFileInfo) {
                $entree->isDir() ? @rmdir($entree->getPathname()) : @unlink($entree->getPathname());
            }
        }

        @rmdir($dossier);
    }
}
