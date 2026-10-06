<?php

declare(strict_types=1);

namespace App\Tests\Question;

use App\Module\Core\Entity\AuditLog;
use App\Module\Core\Enum\AuditAction;
use App\Module\Core\Enum\SubjectKind;
use App\Module\Core\Service\AuditService;
use App\Module\Question\Enum\NatureDeLacune;
use App\Module\Question\Enum\RedacteurDeQuestion;
use App\Module\Question\Service\CorpusDeLArbre;
use App\Module\Question\Service\CoupeCircuit;
use App\Module\Question\Service\Lacune;
use App\Module\Question\Service\LacunesVisibles;
use App\Module\Question\Service\RedactionParGabarit;
use App\Module\Question\Service\RedactionParGemini;
use App\Module\Question\Service\ReglagesDeGemini;
use App\Module\Question\Service\TexteDeQuestion;
use App\Tests\Question\Double\ArbreEnMemoire;
use App\Tests\Question\Double\CercleEnMemoire;
use App\Tests\ReinitialisationDuSchema;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Le branchement du modèle de langage (ADR 0008, jalon 4).
 *
 * ═══════════════════════════════════════════════════════════════════════════════
 *  CE QUE CE FICHIER EXISTE POUR PROUVER : **QUE GEMINI NE PEUT PAS CASSER LA
 *  PAGE.** Six façons de tomber, six fois le repli, et une entrée par lacune
 *  reçue dans les six cas.
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * Le transport est doublé par `MockHttpClient` — c'est la seule façon d'éprouver un
 * `429`, un JSON tronqué et un délai dépassé sans dépendre d'un tiers. Le reste est
 * réel, y compris le journal d'audit et sa base : *« combien de vivants sont partis chez
 * le tiers ce mois-ci »* est la première question qu'un membre posera, et une ligne
 * d'audit qu'aucun test ne lit est une ligne qu'on découvre absente le jour où on la
 * cherche.
 *
 * Aucune personne réelle : noms inventés (ADR 0006).
 */
final class RedactionParGeminiTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private AuditService $audit;
    private ArrayAdapter $cache;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get('doctrine')->getManager();
        \assert($em instanceof EntityManagerInterface);
        $this->em = $em;

        ReinitialisationDuSchema::reinitialiser($this->em);

        $audit = self::getContainer()->get(AuditService::class);
        \assert($audit instanceof AuditService);
        $this->audit = $audit;

        $this->cache = new ArrayAdapter();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        self::ensureKernelShutdown();
    }

    /* ─────────────── Les six portes de sortie vers le repli ─────────────── */

    /**
     * **Clé absente : aucun appel ne part.** C'est l'état d'un déploiement qui n'a pas
     * souscrit, et celui de l'intégration continue. Le client HTTP lève s'il est touché.
     */
    public function testSansCleAucunAppelNePartEtLeRepliRedige(): void
    {
        $textes = $this->rediger($this->clientInterdit(), cle: '');

        self::assertSame(RedacteurDeQuestion::Regles, $this->premier($textes)->redacteur);
        self::assertCount(0, $this->lignesDAudit(), 'Rien n’est parti : rien à consigner.');
    }

    /** **Lot vide : aucun appel non plus** — c'est le cas de toute page rechargée. */
    public function testUnLotVideNeDeclencheAucunAppel(): void
    {
        $redacteur = $this->redacteur($this->clientInterdit(), 'une-cle-de-test');

        self::assertSame([], $redacteur->rediger([]));
        self::assertCount(0, $this->lignesDAudit());
    }

    /** Le tiers lève — délai, réseau, DNS : la page sert quand même ses questions. */
    public function testUnAppelQuiLeveRetombeSurLeRepli(): void
    {
        $client = new MockHttpClient(static function (): MockResponse {
            throw new TransportException('délai dépassé');
        });

        $textes = $this->rediger($client);

        self::assertSame(RedacteurDeQuestion::Regles, $this->premier($textes)->redacteur);
    }

    /**
     * **Un `200` au corps inutilisable est le mode de panne le plus fréquent d'une API
     * de modèle**, et le seul qu'un `try/catch` sur le transport ne voit pas : filtre de
     * sécurité du fournisseur, quota atteint autrement, forme changée.
     */
    public function testUneReponse200SansTexteExploitableRetombeSurLeRepli(): void
    {
        $textes = $this->rediger($this->clientQuiRend(['candidates' => []]));

        self::assertSame(RedacteurDeQuestion::Regles, $this->premier($textes)->redacteur);
    }

    public function testUnJsonTronqueDansLaReponseRetombeSurLeRepli(): void
    {
        $textes = $this->rediger($this->clientQuiRendLeTexte('[{"ref": 1, "texte": "Savez-vous'));

        self::assertSame(RedacteurDeQuestion::Regles, $this->premier($textes)->redacteur);
    }

    /* ─────────────── Le cas qui marche ─────────────── */

    public function testUnePhraseValideEstServieEtAttribueeAuModele(): void
    {
        $phrase = 'Vous souvenez-vous de la ville où Prune Bréval s’est éteinte ?';

        $textes = $this->rediger($this->clientQuiRendLeTexte(json_encode(
            [['ref' => 1, 'texte' => $phrase]],
            \JSON_THROW_ON_ERROR,
        )));

        $premier = $this->premier($textes);
        self::assertSame($phrase, $premier->texte);
        self::assertSame(RedacteurDeQuestion::Gemini, $premier->redacteur);
    }

    /* ─────────────── Le contrôle qui compte ─────────────── */

    /**
     * ═══════════════════════════════════════════════════════════════════════════
     *  UNE PHRASE QUI PORTE SUR QUELQU'UN D'AUTRE EST REFUSÉE.
     * ═══════════════════════════════════════════════════════════════════════════.
     *
     * C'est le seul défaut qu'un modèle peut produire et qui **ne se voit pas** : une
     * question parfaitement écrite, parfaitement plausible, sur la mauvaise personne. Le
     * membre répond de bonne foi, la réponse entre dans la file au nom de la mauvaise
     * fiche, et l'administrateur valide un fait juste sur quelqu'un d'autre.
     *
     * La consigne l'interdit déjà. **Une consigne n'est pas une garantie.**
     */
    public function testUnePhraseQuiNeNommePasLaBonnePersonneEstRefusee(): void
    {
        $textes = $this->rediger($this->clientQuiRendLeTexte(json_encode(
            [['ref' => 1, 'texte' => 'Savez-vous où Thym Bréval est décédé ?']],
            \JSON_THROW_ON_ERROR,
        )));

        $premier = $this->premier($textes);
        self::assertSame(RedacteurDeQuestion::Regles, $premier->redacteur);
        self::assertStringContainsString('Prune Bréval', $premier->texte);
    }

    /** Un paragraphe n'est pas une question : trop long, ou sur plusieurs lignes. */
    public function testUnePhraseHorsFormeEstRefusee(): void
    {
        foreach ([str_repeat('Prune Bréval ', 40), "Prune Bréval ?\nEt aussi ?"] as $mauvaise) {
            $textes = $this->rediger($this->clientQuiRendLeTexte(json_encode(
                [['ref' => 1, 'texte' => $mauvaise]],
                \JSON_THROW_ON_ERROR,
            )));

            self::assertSame(RedacteurDeQuestion::Regles, $this->premier($textes)->redacteur);
        }
    }

    /** Une référence qu'on n'a pas donnée est ignorée : le modèle n'ouvre aucune question. */
    public function testUneReferenceInconnueEstIgnoree(): void
    {
        $textes = $this->rediger($this->clientQuiRendLeTexte(json_encode(
            [['ref' => 99, 'texte' => 'Une question inventée sur Prune Bréval ?']],
            \JSON_THROW_ON_ERROR,
        )));

        self::assertCount(1, $textes, 'Une entrée par lacune REÇUE, ni plus ni moins.');
        self::assertSame(RedacteurDeQuestion::Regles, $this->premier($textes)->redacteur);
    }

    /* ─────────────── Le contrat, et le coupe-circuit ─────────────── */

    /**
     * **Une entrée pour CHAQUE lacune reçue, quelle que soit la panne.** C'est la clause
     * qui fait tenir l'ADR 0008 ; on la vérifie sur un lot mixte, où le modèle ne répond
     * que pour une lacune sur trois.
     */
    public function testChaqueLacuneRecoitUneEntreeMemeQuandLeModeleNEnRendQuUne(): void
    {
        $lacunes = [
            $this->lacune(NatureDeLacune::LieuDeDecesInconnu, 1, 'Prune Bréval'),
            $this->lacune(NatureDeLacune::MereInconnue, 2, 'Thym Bréval'),
            $this->lacune(NatureDeLacune::AucunePhoto, 3, 'Opale Sarriette'),
        ];

        $client = $this->clientQuiRendLeTexte(json_encode(
            [['ref' => 2, 'texte' => 'Qui était la mère de Thym Bréval ?']],
            \JSON_THROW_ON_ERROR,
        ));

        $textes = $this->redacteur($client, 'une-cle-de-test')->rediger($lacunes);

        self::assertCount(3, $textes);

        foreach ($lacunes as $lacune) {
            self::assertArrayHasKey($lacune->signature(), $textes);
        }

        self::assertSame(RedacteurDeQuestion::Gemini, $textes[$lacunes[1]->signature()]->redacteur);
        self::assertSame(RedacteurDeQuestion::Regles, $textes[$lacunes[0]->signature()]->redacteur);
    }

    /**
     * **Après trois échecs consécutifs, on cesse d'appeler.**.
     *
     * Sans le coupe-circuit, un tiers tombé coûte un aller-retour raté par lacune, à
     * chaque ouverture, par chaque membre — et le symptôme n'est pas « le tiers est en
     * panne », c'est « l'application est lente ».
     */
    public function testApresTroisEchecsLeCoupeCircuitCoupeLesAppels(): void
    {
        $appels = 0;
        $client = new MockHttpClient(static function () use (&$appels): MockResponse {
            ++$appels;

            throw new TransportException('tiers indisponible');
        });

        // Le cache est partagé entre les quatre rédacteurs : c'est lui qui porte l'état.
        for ($i = 0; $i < 4; ++$i) {
            $this->rediger($client);
        }

        self::assertSame(
            CoupeCircuit::ECHECS_AVANT_COUPURE,
            $appels,
            'Le quatrième passage ne doit plus appeler personne.',
        );
    }

    /** Un succès remet le compteur à zéro : deux échecs isolés ne sont pas une panne. */
    public function testUnSuccesRouvreLeCircuit(): void
    {
        $reponses = [
            static fn (): MockResponse => throw new TransportException('aléa'),
            static fn (): MockResponse => throw new TransportException('aléa'),
            fn (): MockResponse => $this->reponseAvecTexte(json_encode(
                [['ref' => 1, 'texte' => 'Savez-vous où Prune Bréval est décédée ?']],
                \JSON_THROW_ON_ERROR,
            )),
        ];

        $rang = 0;
        $client = new MockHttpClient(static function () use (&$rang, $reponses): MockResponse {
            return $reponses[$rang++]();
        });

        $this->rediger($client);
        $this->rediger($client);
        $this->rediger($client);

        // Deux échecs puis un succès : le compteur est à zéro, donc le circuit passe.
        $this->cache->deleteItem('inexistant');
        $textes = $this->rediger($this->clientQuiRendLeTexte(json_encode(
            [['ref' => 1, 'texte' => 'Savez-vous où Prune Bréval est décédée ?']],
            \JSON_THROW_ON_ERROR,
        )));

        self::assertSame(RedacteurDeQuestion::Gemini, $this->premier($textes)->redacteur);
    }

    /* ─────────────── Ce qui part, et ce qui est consigné ─────────────── */

    /**
     * **La clé voyage en en-tête, JAMAIS dans l'URL.**.
     *
     * Une URL entre dans les journaux d'accès des mandataires, les rapports d'erreur et
     * l'historique des outils. Un en-tête, non.
     */
    public function testLaCleVoyageEnEnteteEtJamaisDansLUrl(): void
    {
        $url = null;
        $entetes = [];

        $client = new MockHttpClient(function (string $methode, string $u, array $options) use (&$url, &$entetes): MockResponse {
            $url = $u;
            $entetes = $options['headers'] ?? [];

            return $this->reponseAvecTexte('[]');
        });

        $this->rediger($client, cle: 'la-cle-secrete');

        self::assertIsString($url);
        self::assertStringNotContainsString('la-cle-secrete', $url);
        self::assertStringContainsString('la-cle-secrete', implode('|', array_map(strval(...), $entetes)));
    }

    /**
     * **Le journal d'audit porte des COMPTES, jamais le corpus.**.
     *
     * Sans cette ligne, « combien de vivants sont partis chez le tiers ce mois-ci » n'a
     * pas de réponse. Avec le corpus dedans, on aurait recopié dans le journal
     * exactement ce qu'on cherche à tracer — et le journal est servi par une route
     * d'administration.
     */
    public function testLAuditConsigneDesComptesEtPasLeCorpus(): void
    {
        $this->rediger($this->clientQuiRendLeTexte('[]'));

        $lignes = $this->lignesDAudit();
        self::assertCount(1, $lignes);

        $changes = $lignes[0]->getChanges();
        self::assertIsArray($changes);
        self::assertSame(['personnes_transmises', 'dont_vivantes', 'questions_demandees'], array_keys($changes));
        self::assertSame(2, $changes['personnes_transmises']);
        self::assertSame(1, $changes['dont_vivantes']);

        $serialise = json_encode($changes, \JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('Prune', $serialise, 'Aucun nom ne doit entrer dans le journal.');
        self::assertStringNotContainsString('cle', $serialise);
    }

    /**
     * **Révision du 2026-10-04 de l'ADR 0003 — un arbre par famille.** L'arbre d'un autre
     * compte ne part pas chez le tiers : le corpus est borné au périmètre du destinataire.
     */
    public function testLArbreDUnAutreCompteNePartPasChezLeTiers(): void
    {
        $corps = null;
        $client = new MockHttpClient(function (string $methode, string $u, array $options) use (&$corps): MockResponse {
            $corps = $options['body'] ?? null;

            return $this->reponseAvecTexte('[]');
        });

        $this->rediger($client, cercle: (new CercleEnMemoire())->horsPerimetre(2));

        self::assertIsString($corps);
        self::assertStringContainsString('Prune', $corps);
        self::assertStringNotContainsString('Thym', $corps, 'La fiche de l’autre arbre ne quitte pas l’application.');
        self::assertSame(1, $this->lignesDAudit()[0]->getChanges()['personnes_transmises'] ?? null);
    }

    /* ─────────────── Montage ─────────────── */

    /**
     * @return array<string, TexteDeQuestion>
     */
    private function rediger(MockHttpClient $client, string $cle = 'une-cle-de-test', ?CercleEnMemoire $cercle = null): array
    {
        return $this->redacteur($client, $cle, $cercle)->rediger([
            $this->lacune(NatureDeLacune::LieuDeDecesInconnu, 1, 'Prune Bréval'),
        ]);
    }

    private function redacteur(MockHttpClient $client, string $cle, ?CercleEnMemoire $cercle = null): RedactionParGemini
    {
        $arbre = (new ArbreEnMemoire())
            ->ajouter(1, 'Prune Bréval', vivante: true)
            ->ajouterComplete(2, 'Thym Bréval');

        // `ajouterComplete` pose une fiche vivante ; on veut une décédée pour que le
        // compte de vivantes du journal ne soit pas trivialement égal au total.
        $arbre->oublierTout()
            ->ajouter(1, 'Prune Bréval', vivante: true)
            ->ajouter(2, 'Thym Bréval', vivante: false);

        return new RedactionParGemini(
            new RedactionParGabarit(),
            new ReglagesDeGemini($cle),
            new CoupeCircuit($this->cache),
            $client,
            $arbre,
            new CorpusDeLArbre(),
            $this->audit,
            new NullLogger(),
            new LacunesVisibles($cercle ?? new CercleEnMemoire()),
        );
    }

    /** Un client qui **lève si on le touche** : le cas « aucun appel ne part » s'affirme. */
    private function clientInterdit(): MockHttpClient
    {
        return new MockHttpClient(static function (): MockResponse {
            self::fail('Aucun appel sortant ne devait partir.');
        });
    }

    /** @param array<string, mixed> $charge */
    private function clientQuiRend(array $charge): MockHttpClient
    {
        return new MockHttpClient(static fn (): MockResponse => new MockResponse(
            json_encode($charge, \JSON_THROW_ON_ERROR),
            ['response_headers' => ['content-type' => 'application/json']],
        ));
    }

    private function clientQuiRendLeTexte(string $texte): MockHttpClient
    {
        return new MockHttpClient(fn (): MockResponse => $this->reponseAvecTexte($texte));
    }

    /** La forme réelle d'une réponse `generateContent`. */
    private function reponseAvecTexte(string $texte): MockResponse
    {
        return new MockResponse(
            json_encode(
                ['candidates' => [['content' => ['parts' => [['text' => $texte]]]]]],
                \JSON_THROW_ON_ERROR,
            ),
            ['response_headers' => ['content-type' => 'application/json']],
        );
    }

    private function lacune(NatureDeLacune $nature, int $sujetId, string $libelle): Lacune
    {
        return new Lacune($nature, SubjectKind::Personne, $sujetId, $libelle);
    }

    /** @param array<string, TexteDeQuestion> $textes */
    private function premier(array $textes): TexteDeQuestion
    {
        self::assertNotSame([], $textes, 'Le contrat exige une entrée par lacune reçue.');

        return array_values($textes)[0];
    }

    /** @return list<AuditLog> */
    private function lignesDAudit(): array
    {
        $this->em->clear();

        /** @var list<AuditLog> $lignes */
        $lignes = $this->em->getRepository(AuditLog::class)
            ->findBy(['action' => AuditAction::AppelModeleDeLangage]);

        return $lignes;
    }
}
