<?php

declare(strict_types=1);

namespace App\Tests\Assistant;

use App\Module\Assistant\Contract\AccesAuModele;
use App\Module\Assistant\Contract\ArbrePourLAssistant;
use App\Module\Assistant\Contract\FicheDeLArbre;
use App\Module\Assistant\Contract\PersonneDeLArbre;
use App\Module\Assistant\Exception\AssistantEnErreur;
use App\Module\Assistant\Service\AgentDeLAssistant;
use App\Module\Assistant\Service\DocumentJoint;
use App\Module\Assistant\Service\EntreeDAnalyse;
use App\Module\Assistant\Service\OutilsDeLAssistant;
use App\Module\Assistant\Service\ReglagesDeLAssistant;
use App\Module\Assistant\Service\TransportGemini;
use App\Module\Assistant\Service\VueDuLecteur;
use App\Module\Core\Enum\DateQualifier;
use App\Module\Core\Enum\Regime;
use App\Module\Core\Enum\Sex;
use App\Module\Core\Security\CercleDeVisibiliteInterface;
use App\Module\Core\ValueObject\UncertainDate;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * La boucle d'agent, contre un **faux Gemini** au niveau HTTP (`MockHttpClient`).
 *
 * C'est le seul test qui voit les octets qui partent : la forme exacte des requêtes
 * `generateContent` (outils, `toolConfig`, `inlineData`, `functionResponse`), l'en-tête de
 * la clé, et ce que les outils renvoient au modèle — dont le drapeau `confidentielle`
 * sur un vivant hors du cercle du lecteur.
 *
 * Le scénario principal est celui du contrat : le modèle **cherche**, puis **lit une
 * fiche**, puis **propose**.
 */
final class AgentDeLAssistantTest extends TestCase
{
    private const string CLE = 'cle-secrete-de-test';

    private const string URL = 'https://gemini.test/v1beta/models/gemini-pro-latest:generateContent';

    /** @var list<array{methode: string, url: string, entetes: list<string>, corps: array<string, mixed>, brut: string}> */
    private array $requetes = [];

    public function testUnEnregistrementEstEnvoyeCommeUnDocumentEtDitTel(): void
    {
        $agent = $this->agent([
            ['candidates' => [['content' => ['role' => 'model', 'parts' => [
                ['functionCall' => ['name' => 'proposer', 'args' => ['resume' => 'Un témoignage.', 'demandes' => []]]],
            ]]]]],
        ]);

        $agent->analyser(new EntreeDAnalyse('', [
            new DocumentJoint('image/jpeg', "\xFF\xD8\xFFacte", 'acte.jpg'),
            new DocumentJoint('audio/mp4', "\x00\x00\x00\x20ftypM4A temoignage", 'enregistrement-1.m4a'),
        ]));

        $parts = $this->requetes[0]['corps']['contents'][0]['parts'];
        self::assertSame('Document 1 :', $parts[1]['text']);
        self::assertSame('Document 2 (enregistrement audio) :', $parts[3]['text'], 'Le modèle sait qu’il écoute un témoignage.');
        self::assertSame('audio/mp4', $parts[4]['inlineData']['mimeType']);
        self::assertStringContainsString('enregistrement audio est un TÉMOIGNAGE', $this->requetes[0]['corps']['systemInstruction']['parts'][0]['text']);
    }

    public function testLeModeleChercheLitPuisProposeEtLaBouclesArreteLa(): void
    {
        $proposition = [
            'resume' => 'Acte de naissance de Marne.',
            'demandes' => [['nature' => 'modification_personne', 'titre' => 'Naissance de Marne', 'justification' => 'Document 1.', 'confiance' => 'haute', 'personId' => 17]],
        ];

        $agent = $this->agent([
            // Tour 1 : une recherche, avec la signature de pensée d'un modèle à raisonnement.
            ['candidates' => [['content' => ['role' => 'model', 'parts' => [
                ['functionCall' => ['name' => 'chercher_personnes', 'args' => ['requete' => 'Sureau']], 'thoughtSignature' => 'signature-opaque'],
            ]]]]],
            // Tour 2 : la lecture d'une fiche.
            ['candidates' => [['content' => ['role' => 'model', 'parts' => [
                ['functionCall' => ['name' => 'lire_personne', 'args' => ['personId' => 17]]],
            ]]]]],
            // Tour 3 : la proposition.
            ['candidates' => [['content' => ['role' => 'model', 'parts' => [
                ['functionCall' => ['name' => 'proposer', 'args' => $proposition]],
            ]]]]],
        ]);

        $resultat = $agent->analyser(new EntreeDAnalyse(
            'Marne est née le 4 mai 1987 à Brest.',
            [new DocumentJoint('image/jpeg', "\xFF\xD8\xFFacte", 'acte.jpg')],
            personneDeContexte: 17,
            personneDuMembre: 30,
        ));

        self::assertSame($proposition, $resultat->arguments, 'Les arguments de « proposer » sont le résultat, tels quels.');
        self::assertSame(3, $resultat->tours);
        self::assertSame(['chercher_personnes' => 1, 'lire_personne' => 1], $resultat->appelsDOutils);

        self::assertCount(3, $this->requetes, 'La boucle s’arrête au premier « proposer ».');

        foreach ($this->requetes as $requete) {
            self::assertSame('POST', $requete['methode']);
            self::assertSame(self::URL, $requete['url']);
            self::assertContains('x-goog-api-key: '.self::CLE, $requete['entetes'], 'La clé voyage en en-tête…');
            self::assertStringNotContainsString(self::CLE, $requete['url'], '… et jamais dans l’URL.');
            self::assertSame(['mode' => 'ANY'], $requete['corps']['toolConfig']['functionCallingConfig'], 'Le modèle appelle toujours un outil avant le dernier tour.');
            self::assertSame(
                ['chercher_personnes', 'lire_personne', 'proposer'],
                array_column($requete['corps']['tools'][0]['functionDeclarations'], 'name'),
            );
        }

        // Tour 1 : le texte, le contexte, puis le document en `inlineData`.
        $premier = $this->requetes[0]['corps']['contents'];
        self::assertCount(1, $premier);
        self::assertStringContainsString('Marne est née le 4 mai 1987 à Brest.', $premier[0]['parts'][0]['text']);
        self::assertStringContainsString('personne n° 30', $premier[0]['parts'][0]['text'], 'Le modèle sait qui écrit.');
        self::assertStringContainsString('personne n° 17', $premier[0]['parts'][0]['text'], 'Et depuis quelle fiche.');
        self::assertSame(['mimeType' => 'image/jpeg', 'data' => base64_encode("\xFF\xD8\xFFacte")], $premier[0]['parts'][2]['inlineData']);
        self::assertStringContainsString('ne propose que ce que les documents', mb_strtolower($this->requetes[0]['corps']['systemInstruction']['parts'][0]['text']));

        // Tour 2 : le tour du modèle rendu intact (signature comprise), puis la réponse de l'outil.
        $second = $this->requetes[1]['corps']['contents'];
        self::assertCount(3, $second);
        self::assertSame('model', $second[1]['role']);
        self::assertSame('signature-opaque', $second[1]['parts'][0]['thoughtSignature'], 'Une signature de pensée perdue fait repartir le raisonnement de zéro.');
        $reponse = $second[2]['parts'][0]['functionResponse'];
        self::assertSame('chercher_personnes', $reponse['name']);
        $trouvees = array_column($reponse['response']['personnes'], null, 'personId');
        self::assertSame(['Marne Dolomite Sureau', 'Tuf Sureau'], array_column($trouvees, 'nom'));
        self::assertFalse($trouvees[17]['confidentielle'], 'Une défunte n’a rien de confidentiel.');
        self::assertTrue($trouvees[18]['confidentielle'], 'Un vivant hors du cercle du lecteur est signalé au modèle.');
        self::assertSame(['Tuf Sureau'], $trouvees[17]['conjoints']);
        self::assertSame('1987, Brest', $trouvees[17]['naissance']);

        // Tour 3 : la fiche lue, avec sa version et ses champs éditables.
        $fiche = $this->requetes[2]['corps']['contents'][4]['parts'][0]['functionResponse'];
        self::assertSame('lire_personne', $fiche['name']);
        self::assertSame(7, $fiche['response']['version']);
        self::assertSame('Marne Dolomite', $fiche['response']['champs']['givenNames']);
        self::assertSame('annee', $fiche['response']['champs']['birth']['qualificatif']);
    }

    public function testUnModeleQuiNeProposeJamaisRendUneErreurEtSonDernierTourEstContraint(): void
    {
        $recherche = ['candidates' => [['content' => ['role' => 'model', 'parts' => [
            ['functionCall' => ['name' => 'chercher_personnes', 'args' => ['requete' => 'Sureau']]],
        ]]]]];

        $agent = $this->agent(array_fill(0, ReglagesDeLAssistant::TOURS_MAXIMAUX, $recherche));

        try {
            $agent->analyser(new EntreeDAnalyse('Marne.', []));
            self::fail('Un modèle qui ne conclut pas ne doit pas produire de résultat.');
        } catch (AssistantEnErreur $e) {
            self::assertSame(502, $e->statutHttp());
            self::assertSame('assistant_erreur', $e->codeErreur());
        }

        self::assertCount(ReglagesDeLAssistant::TOURS_MAXIMAUX, $this->requetes, 'Huit tours, pas un de plus.');
        self::assertSame(
            ['mode' => 'ANY', 'allowedFunctionNames' => ['proposer']],
            $this->requetes[ReglagesDeLAssistant::TOURS_MAXIMAUX - 1]['corps']['toolConfig']['functionCallingConfig'],
            'Au dernier tour, le modèle n’a plus le choix que de proposer.',
        );
    }

    public function testUnePanneDuFournisseurDevientUne502SansLaCle(): void
    {
        $agent = $this->agent([new MockResponse('{"error":{"code":500}}', ['http_code' => 500])]);

        try {
            $agent->analyser(new EntreeDAnalyse('Marne.', []));
            self::fail('Une 500 du fournisseur est une 502 pour le membre.');
        } catch (AssistantEnErreur $e) {
            self::assertSame(502, $e->statutHttp());
            self::assertStringNotContainsString(self::CLE, $e->getMessage());
            self::assertStringNotContainsString(self::CLE, (string) $e->getPrevious()?->getMessage());
        }
    }

    public function testUneReponseBloqueeEstUne502(): void
    {
        $agent = $this->agent([['promptFeedback' => ['blockReason' => 'SAFETY']]]);

        $this->expectException(AssistantEnErreur::class);
        $agent->analyser(new EntreeDAnalyse('Marne.', []));
    }

    /**
     * `args: {}` décodé en PHP devient `[]`, que `json_encode` réécrirait en liste : Gemini
     * refuserait alors la requête suivante. Il doit repartir en objet.
     */
    public function testDesArgumentsVidesRepartentEnObjet(): void
    {
        $agent = $this->agent([
            ['candidates' => [['content' => ['role' => 'model', 'parts' => [['functionCall' => ['name' => 'chercher_personnes', 'args' => new \stdClass()]]]]]]],
            ['candidates' => [['content' => ['role' => 'model', 'parts' => [['functionCall' => ['name' => 'proposer', 'args' => ['resume' => 'Rien.', 'demandes' => []]]]]]]]],
        ]);

        $agent->analyser(new EntreeDAnalyse('Marne.', []));

        self::assertStringContainsString('"args":{}', $this->requetes[1]['brut']);
        // Et l'outil a répondu au modèle plutôt que de lever : il peut se corriger.
        self::assertArrayHasKey('erreur', $this->requetes[1]['corps']['contents'][2]['parts'][0]['functionResponse']['response']);
    }

    /**
     * @param list<array<string, mixed>|MockResponse> $reponses
     */
    private function agent(array $reponses): AgentDeLAssistant
    {
        $this->requetes = [];

        $http = new MockHttpClient(function (string $methode, string $url, array $options) use (&$reponses): MockResponse {
            $brut = \is_string($options['body'] ?? null) ? $options['body'] : '';
            $corps = json_decode($brut, true);

            $this->requetes[] = [
                'methode' => $methode,
                'url' => $url,
                'entetes' => array_values(array_filter($options['headers'] ?? [], 'is_string')),
                'corps' => \is_array($corps) ? $corps : [],
                'brut' => $brut,
            ];

            $suivante = array_shift($reponses) ?? throw new \LogicException('Script épuisé.');

            return $suivante instanceof MockResponse
                ? $suivante
                : new MockResponse(json_encode($suivante, \JSON_THROW_ON_ERROR), ['http_code' => 200, 'response_headers' => ['content-type' => 'application/json']]);
        });

        $acces = new class(self::CLE) implements AccesAuModele {
            public function __construct(private readonly string $cle)
            {
            }

            public function configure(): bool
            {
                return true;
            }

            public function cleApi(): string
            {
                return $this->cle;
            }

            public function pointDacces(): string
            {
                return 'https://gemini.test/v1beta';
            }
        };

        $cercle = new class implements CercleDeVisibiliteInterface {
            public function regime(): Regime
            {
                return Regime::Cercle;
            }

            public function voitPersonne(int $personId): bool
            {
                return 18 !== $personId;
            }

            public function filtrerPersonnes(array $personIds): array
            {
                return array_values(array_filter(array_unique($personIds), fn (int $id): bool => $this->voitPersonne($id)));
            }
        };

        return new AgentDeLAssistant(
            new TransportGemini($acces, $http),
            new OutilsDeLAssistant(new ArbreDeDeuxPersonnes(), new VueDuLecteur($cercle)),
            new ReglagesDeLAssistant(null),
            new NullLogger(),
        );
    }
}

/**
 * Deux personnes : Marne (17), défunte, et Tuf (18), vivant — que le lecteur du test ne
 * voit qu'en restreint.
 */
final class ArbreDeDeuxPersonnes implements ArbrePourLAssistant
{
    public function chercher(string $requete, int $limite): array
    {
        return [$this->marne(['Tuf Sureau']), $this->tuf()];
    }

    public function personne(int $personneId): ?PersonneDeLArbre
    {
        return match ($personneId) {
            17 => $this->marne(),
            18 => $this->tuf(),
            default => null,
        };
    }

    public function fiche(int $personneId): ?FicheDeLArbre
    {
        return 17 === $personneId
            ? new FicheDeLArbre($this->marne(), 7, 'Marne Dolomite', 'Sureau', null, null, true, [], [], [])
            : null;
    }

    /** @param list<string> $conjoints */
    private function marne(array $conjoints = []): PersonneDeLArbre
    {
        return new PersonneDeLArbre(
            17,
            'Marne Dolomite Sureau',
            Sex::Feminin,
            false,
            new UncertainDate('1987', DateQualifier::Annee, new \DateTimeImmutable('1987-01-01'), new \DateTimeImmutable('1987-12-31'), 'Brest'),
            UncertainDate::inconnue(),
            [],
            $conjoints,
        );
    }

    private function tuf(): PersonneDeLArbre
    {
        return new PersonneDeLArbre(18, 'Tuf Sureau', Sex::Masculin, true, UncertainDate::inconnue(), UncertainDate::inconnue());
    }
}
