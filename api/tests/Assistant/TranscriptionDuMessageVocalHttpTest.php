<?php

declare(strict_types=1);

namespace App\Tests\Assistant;

use App\Module\Assistant\Service\QuotasDeLAssistant;
use App\Module\Assistant\Service\ReglagesDeLAssistant;
use App\Module\Assistant\Service\TranscriptionDesMessagesVocaux;
use App\Module\Core\Entity\AuditLog;
use App\Module\Core\Enum\AuditAction;
use App\Module\Core\Service\QuotasDUsage;
use App\Module\Membership\Contract\DeclarationDuCompte;
use App\Module\Membership\Contract\MessageVocalDuCompte;
use App\Tests\Assistant\Double\AccesAuModeleSimule;
use App\Tests\Assistant\Double\ComptesARattacherEnMemoire;
use App\Tests\Assistant\Double\ModeleScripte;
use App\Tests\Proposal\CasDeTestDemandesHttp;
use Symfony\Component\HttpClient\Exception\TimeoutException;
use Symfony\Component\RateLimiter\LimiterInterface;

/**
 * La **transcription du message vocal** d'une inscription sans fiche, et ce que l'analyse
 * en fait (ADR 0013, addendum du 2 octobre 2026, soir).
 *
 * Même scène que {@see RattachementAssisteHttpTest} — modèle scripté, jamais de réseau ;
 * le côté `Membership` en mémoire —, avec cette fois une personne qui **n'a rien écrit** :
 * elle a dit sa famille dans un message vocal. Ce que ce fichier verrouille :
 *
 * - **le son part à Gemini, en ligne, sous le nom de type que Gemini connaît** — tel quel
 *   pour WebM et Ogg, `audio/m4a` pour le MP4 de Safari ; avec une consigne de verbatim ;
 *   par le modèle de transcription, pas par celui de l'analyse ;
 * - **la transcription est rangée sur le compte**, et l'échec aussi, avec son motif :
 *   `200` dans les deux cas, parce que l'état **est** la réponse ; « Réessayer » refait ;
 * - **l'analyse la lit comme la description**, étiquetée « transcription automatique », et
 *   le dit (`transcriptionPriseEnCompte`) ; une suggestion calculée sur une autre version
 *   de la transcription est rendue **périmée** — jamais recalculée en douce par une
 *   lecture (addendum du 3 octobre 2026) ;
 * - **la correction à la main** (`PATCH`, 3 octobre 2026) : sans appel ni quota, bornée,
 *   marquée ; la recherche suivante lit le texte corrigé et sait qu'il a été relu ; une
 *   retranscription efface la marque ;
 * - **les refus** : membre ordinaire `403`, compte sans vocal `404`, pas de clé `503`,
 *   quota global `429`, texte invalide `422` ;
 * - **le journal** : un appel, des comptes, jamais le texte.
 */
final class TranscriptionDuMessageVocalHttpTest extends CasDeTestDemandesHttp
{
    private const int MEMBRE = 14;

    /** Un en-tête EBML suivi de remplissage : ce qui part chez Gemini, encodé en base64. */
    private const string WEBM = "\x1A\x45\xDF\xA3\x9F\x42\x86\x81\x01\x42\xF7\x81\x01\x42\xF2\x81";

    private const string TRANSCRIPTION = 'Bonjour, je suis Léa Dupont, née le 12 avril 1998. Mon père, c’est Jean Dupont, né vers 1960 à Lille, et ma mère Marie Martin.';

    /** La même, relue par l'administrateur : « Martin » s'écrivait « Martain ». */
    private const string CORRIGEE = 'Bonjour, je suis Léa Dupont, née le 12 avril 1998. Mon père, c’est Jean Dupont, né vers 1960 à Lille, et ma mère Marie Martain.';

    protected function setUp(): void
    {
        parent::setUp();

        ModeleScripte::oublierTout();
        AccesAuModeleSimule::oublierTout();
        ComptesARattacherEnMemoire::oublierTout();

        ComptesARattacherEnMemoire::poser(self::declaration());
        ComptesARattacherEnMemoire::poserLeVocal(self::MEMBRE, new MessageVocalDuCompte(self::WEBM.str_repeat("\x00", 512), 'audio/webm', 42));

        $this->creerUtilisateur('natron@example.net', ['ROLE_ADMIN'], 'Natron');
    }

    // ─────────────────────────────────────────────────────────────────────────────
    //  La transcription
    // ─────────────────────────────────────────────────────────────────────────────

    public function testLeSonPartEnLigneEtLaTranscriptionEstRangeeSurLeCompte(): void
    {
        $this->connexion('natron@example.net');
        ModeleScripte::repondra(self::texte(self::TRANSCRIPTION));

        $this->requete('POST', $this->route('transcription'));
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));

        $charge = $this->charge();
        self::assertSame(['memberId', 'statut', 'texte', 'erreur', 'le', 'corrigeeLe'], array_keys($charge));
        self::assertNull($charge['corrigeeLe'], 'Un texte du modèle n’est pas une correction.');
        self::assertSame('faite', $charge['statut']);
        self::assertSame(self::TRANSCRIPTION, $charge['texte']);
        self::assertNull($charge['erreur']);

        // Ce que Gemini a reçu : le son en base64, sous son type, et la consigne de verbatim.
        $requete = ModeleScripte::requetes()[0];
        self::assertSame(ReglagesDeLAssistant::MODELE_TRANSCRIPTION_DEFAUT, $requete['modele'], 'Le modèle léger, pas celui de l’analyse.');
        $parts = $requete['charge']['contents'][0]['parts'];
        self::assertSame('audio/webm', $parts[1]['inlineData']['mimeType']);
        self::assertSame(base64_encode(self::WEBM.str_repeat("\x00", 512)), $parts[1]['inlineData']['data']);
        $consigne = $requete['charge']['systemInstruction']['parts'][0]['text'];
        self::assertStringContainsString('MOT POUR MOT', $consigne);
        self::assertStringContainsString('ne résume pas', $consigne);
        self::assertStringContainsString('en français', $consigne);
        self::assertStringNotContainsString('lea.dupont@example.net', (string) json_encode($requete['charge']));

        $gardee = ComptesARattacherEnMemoire::declarationGardee(self::MEMBRE);
        self::assertNotNull($gardee);
        self::assertSame(self::TRANSCRIPTION, $gardee->transcription);
        self::assertNull($gardee->transcriptionErreur);
        self::assertNotNull($gardee->transcriptionLe);

        self::assertSame(39, $this->limiteur()->consume(0)->getRemainingTokens(), 'Une transcription consomme le quota global des rattachements.');
    }

    /** Safari enregistre en MP4/AAC : Gemini le connaît sous le nom `audio/m4a`. */
    public function testLeMp4DeSafariEstAnnonceSousLeNomQueGeminiConnait(): void
    {
        $mp4 = new MessageVocalDuCompte("\x00\x00\x00\x1Cftypmp42".str_repeat("\x00", 64), 'audio/mp4', null);
        $ogg = new MessageVocalDuCompte('OggS'.str_repeat("\x00", 64), 'audio/ogg', null);

        self::assertSame('audio/m4a', TranscriptionDesMessagesVocaux::charge($mp4)['contents'][0]['parts'][1]['inlineData']['mimeType']);
        self::assertSame('audio/ogg', TranscriptionDesMessagesVocaux::charge($ogg)['contents'][0]['parts'][1]['inlineData']['mimeType']);
        self::assertSame(0.0, TranscriptionDesMessagesVocaux::charge($ogg)['generationConfig']['temperature']);
    }

    /** Les « pensées » d'un modèle à raisonnement ne sont pas la transcription. */
    public function testLesPenseesDuModeleNeSontPasTranscrites(): void
    {
        self::assertSame('Bonjour.', TranscriptionDesMessagesVocaux::texteDe(['candidates' => [['content' => ['parts' => [
            ['text' => 'Je dois transcrire…', 'thought' => true],
            ['text' => "  Bonjour.\n"],
        ]]]]]));
        self::assertNull(TranscriptionDesMessagesVocaux::texteDe(['candidates' => [['content' => ['parts' => [['text' => '   ']]]]]]));
        self::assertNull(TranscriptionDesMessagesVocaux::texteDe([]));
    }

    public function testUnEchecEstRangeAvecSonMotifEtReessayerRefait(): void
    {
        $this->connexion('natron@example.net');
        ModeleScripte::repondra(new TimeoutException('Idle timeout reached.'), self::texte(self::TRANSCRIPTION));

        $this->requete('POST', $this->route('transcription'));
        self::assertResponseIsSuccessful('Une panne du modèle n’est pas une erreur de la route : l’échec est un état.');
        $charge = $this->charge();
        self::assertSame('echec', $charge['statut']);
        self::assertNull($charge['texte']);
        self::assertStringContainsString('à temps', (string) $charge['erreur']);
        self::assertStringNotContainsString('Idle timeout', (string) $charge['erreur'], 'Le motif est écrit par le serveur, jamais recopié du transport.');

        $gardee = ComptesARattacherEnMemoire::declarationGardee(self::MEMBRE);
        self::assertNull($gardee?->transcription);
        self::assertNotNull($gardee?->transcriptionErreur);

        // « Réessayer ».
        $this->requete('POST', $this->route('transcription'));
        self::assertResponseIsSuccessful();
        self::assertSame('faite', $this->charge()['statut']);
        $gardee = ComptesARattacherEnMemoire::declarationGardee(self::MEMBRE);
        self::assertSame(self::TRANSCRIPTION, $gardee?->transcription);
        self::assertNull($gardee->transcriptionErreur, 'Un succès efface l’échec précédent.');
    }

    public function testUnRefusDuModeleEstUnEchecQuiDitSonMotif(): void
    {
        $this->connexion('natron@example.net');
        ModeleScripte::repondra(['promptFeedback' => ['blockReason' => 'PROHIBITED_CONTENT']]);

        $this->requete('POST', $this->route('transcription'));
        self::assertResponseIsSuccessful();
        self::assertSame('echec', $this->charge()['statut']);
        self::assertStringContainsString('PROHIBITED_CONTENT', (string) $this->charge()['erreur']);
    }

    public function testLAppelEstAuditeSansLeTexte(): void
    {
        $this->connexion('natron@example.net');
        ModeleScripte::repondra(self::texte(self::TRANSCRIPTION));

        $this->requete('POST', $this->route('transcription'));
        self::assertResponseIsSuccessful();

        $lignes = $this->em()->getRepository(AuditLog::class)->findBy(['action' => AuditAction::AppelModeleDeLangage]);
        self::assertCount(1, $lignes);
        self::assertSame('Assistant\\Transcription', $lignes[0]->getSubjectType());
        self::assertSame(
            ['statut' => 'faite', 'secondes_annoncees' => 42, 'octets' => \strlen(self::WEBM) + 512, 'caracteres' => mb_strlen(self::TRANSCRIPTION)],
            $lignes[0]->getChanges(),
        );
        self::assertStringNotContainsString('Jean Dupont', (string) json_encode($lignes[0]->getChanges()));
    }

    // ─────────────────────────────────────────────────────────────────────────────
    //  Les refus
    // ─────────────────────────────────────────────────────────────────────────────

    public function testUnMembreOrdinaireNObtientRien(): void
    {
        $this->creerContributeur('opale@example.net', 1, 'Opale');
        $this->connexion('opale@example.net');

        $this->requete('POST', $this->route('transcription'));
        self::assertResponseStatusCodeSame(403);
        self::assertSame([], ModeleScripte::requetes());
    }

    public function testUnCompteSansVocalRend404SansAppel(): void
    {
        ComptesARattacherEnMemoire::oublierTout();
        ComptesARattacherEnMemoire::poser(self::declaration(vocal: false));
        $this->connexion('natron@example.net');

        $this->requete('POST', $this->route('transcription'));
        self::assertResponseStatusCodeSame(404);

        $this->requete('POST', '/api/administration/rattachements/999/transcription');
        self::assertResponseStatusCodeSame(404);
        self::assertSame([], ModeleScripte::requetes());
        self::assertSame(40, $this->limiteur()->consume(0)->getRemainingTokens());
    }

    public function testSansCle503EtQuotaEpuise429(): void
    {
        $this->connexion('natron@example.net');
        AccesAuModeleSimule::retirerLaCle();

        $this->requete('POST', $this->route('transcription'));
        self::assertResponseStatusCodeSame(503);
        self::assertSame('assistant_indisponible', $this->erreur()['code']);

        AccesAuModeleSimule::oublierTout();
        $limiteur = $this->limiteur();
        while ($limiteur->consume()->isAccepted()) {
        }

        $this->requete('POST', $this->route('transcription'));
        self::assertResponseStatusCodeSame(429);
        self::assertSame([], ModeleScripte::requetes());
        self::assertNull(ComptesARattacherEnMemoire::declarationGardee(self::MEMBRE)?->transcriptionLe, 'Rien n’est rangé sans appel.');
    }

    // ─────────────────────────────────────────────────────────────────────────────
    //  L'analyse l'entend
    // ─────────────────────────────────────────────────────────────────────────────

    public function testLAnalyseLitLaTranscriptionCommeLaDescription(): void
    {
        $this->connexion('natron@example.net');
        ModeleScripte::repondra(
            self::texte(self::TRANSCRIPTION),
            ModeleScripte::appel('conclure', ['resume' => 'Elle dit que son père est Jean Dupont.', 'verdict' => 'indecise']),
        );

        $this->requete('POST', $this->route('transcription'));
        self::assertResponseIsSuccessful();

        $this->requete('POST', $this->route('suggestion'));
        self::assertResponseIsSuccessful();
        self::assertTrue($this->charge()['transcriptionPriseEnCompte']);
        self::assertFalse($this->charge()['perimee']);

        $premier = ModeleScripte::requetes()[1]['charge']['contents'][0]['parts'][0]['text'];
        self::assertStringContainsString('transcription automatique', $premier);
        self::assertStringContainsString(self::TRANSCRIPTION, $premier);
        self::assertStringContainsString('Sers-t’en exactement comme de la description écrite', $premier);
        self::assertStringNotContainsString('dont la transcription n’est pas disponible', $premier);
        // Le son, lui, ne repart pas avec l'analyse.
        self::assertStringNotContainsString('inlineData', (string) json_encode(ModeleScripte::requetes()[1]['charge']));
    }

    /**
     * La recherche a tourné avant la transcription. Avant le 3 octobre 2026, la lecture
     * suivante la recalculait d'elle-même ; désormais **une lecture ne calcule jamais** :
     * la suggestion est rendue telle quelle, marquée `perimee`, et c'est le clic
     * « Relancer la recherche » (`POST`) qui la refait.
     */
    public function testUneSuggestionCalculeeSansLaTranscriptionEstPerimeeQuandElleArrive(): void
    {
        $this->connexion('natron@example.net');
        ModeleScripte::repondra(
            ModeleScripte::appel('conclure', ['resume' => 'Sans la voix.', 'verdict' => 'indecise']),
            self::texte(self::TRANSCRIPTION),
            ModeleScripte::appel('conclure', ['resume' => 'Avec la voix.', 'verdict' => 'indecise']),
        );

        $this->requete('POST', $this->route('suggestion'));
        self::assertResponseIsSuccessful();
        self::assertFalse($this->charge()['transcriptionPriseEnCompte']);
        self::assertFalse($this->charge()['perimee']);
        $premier = ModeleScripte::requetes()[0]['charge']['contents'][0]['parts'][0]['text'];
        self::assertStringContainsString('dont la transcription n’est pas disponible', $premier);

        $this->requete('POST', $this->route('transcription'));
        self::assertResponseIsSuccessful();

        // Relue : la même suggestion, périmée — et aucun appel de plus.
        $this->requete('GET', $this->route('suggestion'));
        self::assertResponseIsSuccessful();
        self::assertSame('Sans la voix.', $this->charge()['resume']);
        self::assertTrue($this->charge()['perimee']);
        self::assertCount(2, ModeleScripte::requetes(), 'Une lecture ne relance rien.');

        // « Relancer la recherche ».
        $this->requete('POST', $this->route('suggestion'));
        self::assertResponseIsSuccessful();
        self::assertSame('Avec la voix.', $this->charge()['resume']);
        self::assertTrue($this->charge()['transcriptionPriseEnCompte']);
        self::assertFalse($this->charge()['perimee']);
        self::assertCount(3, ModeleScripte::requetes());
    }

    // ─────────────────────────────────────────────────────────────────────────────
    //  La correction à la main (3 octobre 2026)
    // ─────────────────────────────────────────────────────────────────────────────

    public function testLAdministrateurCorrigeLaTranscriptionSansAppelNiQuota(): void
    {
        $this->connexion('natron@example.net');
        ModeleScripte::repondra(self::texte(self::TRANSCRIPTION));
        $this->requete('POST', $this->route('transcription'));
        self::assertResponseIsSuccessful();
        $tentative = ComptesARattacherEnMemoire::declarationGardee(self::MEMBRE)?->transcriptionLe;

        // Fins de ligne Windows et blancs autour : ramenés.
        $this->requete('PATCH', $this->route('transcription'), ['texte' => '  '.self::CORRIGEE."\r\nFin.\r\n"]);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));

        $charge = $this->charge();
        self::assertSame(['memberId', 'statut', 'texte', 'erreur', 'le', 'corrigeeLe'], array_keys($charge));
        self::assertSame('faite', $charge['statut']);
        self::assertSame(self::CORRIGEE."\nFin.", $charge['texte']);
        self::assertNull($charge['erreur']);
        self::assertIsString($charge['corrigeeLe']);

        $gardee = ComptesARattacherEnMemoire::declarationGardee(self::MEMBRE);
        self::assertSame(self::CORRIGEE."\nFin.", $gardee?->transcription);
        self::assertNotNull($gardee->transcriptionCorrigeeLe);
        self::assertEquals($tentative, $gardee->transcriptionLe, 'La date de la tentative du modèle reste la sienne.');

        self::assertCount(1, ModeleScripte::requetes(), 'Corriger n’appelle pas le modèle.');
        self::assertSame(39, $this->limiteur()->consume(0)->getRemainingTokens(), 'Corriger ne consomme pas le quota.');
    }

    /**
     * Ce que le propriétaire a demandé, de bout en bout : transcrire, lancer la recherche,
     * corriger un nom, voir la recherche **périmée**, la relancer — et que la seconde lise
     * le texte corrigé, étiqueté comme relu.
     */
    public function testLaRechercheLitLaVersionCorrigeeEtLaPrecedenteEstPerimee(): void
    {
        $this->connexion('natron@example.net');
        ModeleScripte::repondra(
            self::texte(self::TRANSCRIPTION),
            ModeleScripte::appel('conclure', ['resume' => 'Sur le texte de Gemini.', 'verdict' => 'indecise']),
            ModeleScripte::appel('conclure', ['resume' => 'Sur le texte corrigé.', 'verdict' => 'indecise']),
        );

        $this->requete('POST', $this->route('transcription'));
        $this->requete('POST', $this->route('suggestion'));
        self::assertResponseIsSuccessful();
        self::assertFalse($this->charge()['perimee']);

        $this->requete('PATCH', $this->route('transcription'), ['texte' => self::CORRIGEE]);
        self::assertResponseIsSuccessful();

        $this->requete('GET', $this->route('suggestion'));
        self::assertResponseIsSuccessful();
        self::assertSame('Sur le texte de Gemini.', $this->charge()['resume'], 'La suggestion reste montrée…');
        self::assertTrue($this->charge()['perimee'], '… mais périmée : elle a lu une autre version.');
        self::assertCount(2, ModeleScripte::requetes());

        $this->requete('POST', $this->route('suggestion'));
        self::assertResponseIsSuccessful();
        self::assertSame('Sur le texte corrigé.', $this->charge()['resume']);
        self::assertFalse($this->charge()['perimee']);

        $premier = ModeleScripte::requetes()[2]['charge']['contents'][0]['parts'][0]['text'];
        self::assertStringContainsString(self::CORRIGEE, $premier);
        self::assertStringNotContainsString(self::TRANSCRIPTION, $premier);
        self::assertStringContainsString('relue et corrigée par l’administrateur', $premier);
        self::assertStringNotContainsString('tels qu’entendus', $premier);

        // Le journal de l'appel dit que le texte était relu — sans le texte.
        $lignes = $this->em()->getRepository(AuditLog::class)->findBy(['action' => AuditAction::AppelModeleDeLangage, 'subjectType' => 'Assistant\\Rattachement']);
        self::assertCount(2, $lignes);
        $changements = array_map(static fn (AuditLog $l): array => (array) $l->getChanges(), $lignes);
        self::assertContains(true, array_column($changements, 'transcription_corrigee'));
        self::assertStringNotContainsString('Dupont', (string) json_encode($changements));

        // C'est le texte lu qui compte, pas le fait d'avoir touché au champ : changer le
        // texte périme la recherche, revenir exactement à celui qu'elle a lu la rend de
        // nouveau valable.
        $this->requete('PATCH', $this->route('transcription'), ['texte' => self::TRANSCRIPTION]);
        $this->requete('GET', $this->route('suggestion'));
        self::assertTrue($this->charge()['perimee']);
        $this->requete('PATCH', $this->route('transcription'), ['texte' => self::CORRIGEE]);
        $this->requete('GET', $this->route('suggestion'));
        self::assertFalse($this->charge()['perimee']);
    }

    public function testRetranscrireEffaceLaMarqueDeCorrection(): void
    {
        $this->connexion('natron@example.net');
        ModeleScripte::repondra(self::texte(self::TRANSCRIPTION), self::texte(self::TRANSCRIPTION));

        $this->requete('POST', $this->route('transcription'));
        $this->requete('PATCH', $this->route('transcription'), ['texte' => self::CORRIGEE]);
        self::assertNotNull(ComptesARattacherEnMemoire::declarationGardee(self::MEMBRE)?->transcriptionCorrigeeLe);

        $this->requete('POST', $this->route('transcription'));
        self::assertResponseIsSuccessful();
        self::assertNull($this->charge()['corrigeeLe']);
        $gardee = ComptesARattacherEnMemoire::declarationGardee(self::MEMBRE);
        self::assertSame(self::TRANSCRIPTION, $gardee?->transcription);
        self::assertNull($gardee->transcriptionCorrigeeLe, 'Le texte est de nouveau celui du modèle.');
    }

    /** Le modèle a échoué, ou il n'y a pas de clé : l'administrateur écoute et l'écrit. */
    public function testOnPeutEcrireLaTranscriptionSoiMemeSansCleNiTentative(): void
    {
        $this->connexion('natron@example.net');
        AccesAuModeleSimule::retirerLaCle();

        $this->requete('PATCH', $this->route('transcription'), ['texte' => self::CORRIGEE]);
        self::assertResponseIsSuccessful();
        self::assertSame('faite', $this->charge()['statut']);

        $gardee = ComptesARattacherEnMemoire::declarationGardee(self::MEMBRE);
        self::assertSame(self::CORRIGEE, $gardee?->transcription);
        self::assertNotNull($gardee->transcriptionLe, 'Un texte porte sa date.');
        self::assertNotNull($gardee->transcriptionCorrigeeLe);
        self::assertSame([], ModeleScripte::requetes());
    }

    public function testUneCorrectionInvalideRend422SansRienEcrire(): void
    {
        $this->connexion('natron@example.net');
        ModeleScripte::repondra(self::texte(self::TRANSCRIPTION));
        $this->requete('POST', $this->route('transcription'));

        foreach ([[], ['texte' => null], ['texte' => 42], ['texte' => ''], ['texte' => " \n\t "], ['texte' => str_repeat('é', TranscriptionDesMessagesVocaux::TEXTE_MAX + 1)]] as $corps) {
            $this->requete('PATCH', $this->route('transcription'), $corps);
            self::assertResponseStatusCodeSame(422, (string) json_encode($corps));
        }

        $gardee = ComptesARattacherEnMemoire::declarationGardee(self::MEMBRE);
        self::assertSame(self::TRANSCRIPTION, $gardee?->transcription);
        self::assertNull($gardee->transcriptionCorrigeeLe);

        // La borne elle-même passe.
        $this->requete('PATCH', $this->route('transcription'), ['texte' => str_repeat('é', TranscriptionDesMessagesVocaux::TEXTE_MAX)]);
        self::assertResponseIsSuccessful();
    }

    public function testCorrigerEstReserveAuxAdministrateursEtAuxComptesAvecVocal(): void
    {
        $this->creerContributeur('opale@example.net', 1, 'Opale');
        $this->connexion('opale@example.net');
        $this->requete('PATCH', $this->route('transcription'), ['texte' => self::CORRIGEE]);
        self::assertResponseStatusCodeSame(403);
        self::assertNull(ComptesARattacherEnMemoire::declarationGardee(self::MEMBRE)?->transcription);

        $this->connexion('natron@example.net');
        $this->requete('PATCH', '/api/administration/rattachements/999/transcription', ['texte' => self::CORRIGEE]);
        self::assertResponseStatusCodeSame(404);

        ComptesARattacherEnMemoire::oublierTout();
        ComptesARattacherEnMemoire::poser(self::declaration(vocal: false));
        $this->requete('PATCH', $this->route('transcription'), ['texte' => self::CORRIGEE]);
        self::assertResponseStatusCodeSame(404);
        self::assertNull(ComptesARattacherEnMemoire::declarationGardee(self::MEMBRE)?->transcription);
    }

    // ─────────────────────────────────────────────────────────────────────────────

    private function route(string $fin): string
    {
        return \sprintf('/api/administration/rattachements/%d/%s', self::MEMBRE, $fin);
    }

    private static function declaration(bool $vocal = true): DeclarationDuCompte
    {
        return new DeclarationDuCompte(
            self::MEMBRE,
            22,
            'lea.dupont@example.net',
            'Dupont',
            'Léa Marie',
            new \DateTimeImmutable('1998-04-12'),
            // Rien d'écrit : la voix seule.
            '',
            null,
            null,
            null,
            aUnMessageVocal: $vocal,
        );
    }

    /**
     * Une réponse Gemini qui rend du texte.
     *
     * @return array<string, mixed>
     */
    private static function texte(string $texte): array
    {
        return ['candidates' => [[
            'content' => ['role' => 'model', 'parts' => [['text' => $texte]]],
            'finishReason' => 'STOP',
        ]]];
    }

    private function limiteur(): LimiterInterface
    {
        $quotas = self::getContainer()->get(QuotasDUsage::class);
        $declares = self::getContainer()->get(QuotasDeLAssistant::class);
        \assert($quotas instanceof QuotasDUsage && $declares instanceof QuotasDeLAssistant);

        return $quotas->limiteur($declares->rattachements(), null);
    }
}
