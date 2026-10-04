<?php

declare(strict_types=1);

namespace App\Module\Question\Service;

use App\Module\Core\Enum\AuditAction;
use App\Module\Core\Service\AuditService;
use App\Module\Question\Contract\FicheEtudiee;
use App\Module\Question\Contract\PortArbrePourQuestions;
use App\Module\Question\Enum\RedacteurDeQuestion;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * ═══════════════════════════════════════════════════════════════════════════════
 *  LE PREMIER APPEL SORTANT DE CETTE APPLICATION — ET IL NE PEUT PAS LA CASSER.
 * ═══════════════════════════════════════════════════════════════════════════════.
 *
 * Avant ce fichier, `grep -rn "HttpClientInterface" api/src` ne rendait rien : le
 * produit n'appelait personne à l'extérieur. Il n'y avait donc **aucun motif maison à
 * recopier** pour les délais, les reprises, les pannes et les quotas. Ils sont établis
 * ici, et le principe qui les gouverne tient en une ligne :
 *
 * > **Ce décorateur enveloppe le repli, et lui repasse tout ce qu'il n'a pas su
 * > écrire.** Clé absente, délai dépassé, quota épuisé, réponse illisible, tiers en
 * > panne : la page sert les mêmes questions, moins bien tournées.
 *
 * C'est la clause du contrat de {@see RedacteurDeQuestions} — *« rédiger rend une entrée
 * pour CHAQUE lacune reçue, sans exception »* — et la décoration est ce qui la rend
 * structurelle plutôt que vigilante. Un `try/catch` chez l'appelant n'aurait pas marché :
 * **il n'aurait aucun moyen de savoir qu'il doit se rattraper**, un tableau plus court
 * n'ayant rien d'anormal.
 *
 * ── Les six portes de sortie vers le repli, et ce que chacune attrape ────────────
 *
 * | Porte | Cas réel |
 * |---|---|
 * | lot vide | toute page rechargée — **aucun appel ne part** |
 * | clé absente | déploiement sans souscription, intégration continue |
 * | coupe-circuit ouvert | le tiers est tombé il y a moins de cinq minutes |
 * | l'appel lève | délai, réseau, `429`, `500` |
 * | la réponse est illisible | JSON tronqué, forme inattendue, refus du modèle |
 * | une phrase est refusée | voir {@see phraseAcceptable()} |
 *
 * Les deux dernières sont celles qu'on oublie : un tiers qui répond `200` avec un corps
 * inutilisable est **le mode de panne le plus fréquent** d'une API de modèle, et le seul
 * qu'un `try/catch` sur le transport ne voit pas.
 *
 * ── Ce que ce fichier n'a PAS le droit de faire ─────────────────────────────────
 *
 * - **ouvrir ou fermer une question.** Il reçoit les lacunes que le code a trouvées et
 *   rend une phrase pour chacune. Une entrée pour une référence qu'on ne lui a pas
 *   donnée est ignorée ;
 * - **mentir sur `redacteur`.** Une phrase issue du repli porte
 *   {@see RedacteurDeQuestion::Regles}, y compris quand elle sort d'ici. C'est ce qui
 *   permet de répondre à « le repli a-t-il servi ? » par une requête plutôt que par une
 *   fouille de journaux ;
 * - **laisser la clé sortir.** Elle n'entre dans aucun message, aucun journal, aucune
 *   trace. Elle ne quitte {@see ReglagesDeGemini} que pour l'en-tête de la requête.
 */
/*
 * ── POURQUOI LA DÉCORATION EST DÉCLARÉE ICI ET NON EN YAML ──────────────────────
 *
 * Elle l'a été, et **le conteneur s'est compilé sans rien dire** : `lint:container`
 * vert, `debug:container` montrant l'alias attendu, et un `ArgumentCountError: 1 passed,
 * exactly 8 expected` au premier appel réel.
 *
 * La cause est écrite en toutes lettres dans `config/services/almanach.yaml`, et elle a
 * déjà coûté vingt-cinq tests au module `Timeline` : **`_defaults` n'est hérité ni d'un
 * fichier à l'autre, ni d'un bloc `when@` au bloc principal.** Une définition concrète
 * posée dans un fichier de module sans `_defaults: { autowire: true }` ne complète pas
 * la définition du prototype `App\:` — elle la **remplace**, sans autowiring.
 *
 * L'attribut n'a pas ce défaut : il est appliqué par une passe de compilation, sur la
 * définition que le prototype a déjà construite. C'est aussi la règle que
 * `config/services/_autoload.yaml` énonce — *« configurer À CÔTÉ DE LA CLASSE »*.
 */
#[AsDecorator(RedacteurDeQuestions::class)]
final readonly class RedactionParGemini implements RedacteurDeQuestions
{
    /**
     * Le délai d'attente, en secondes.
     *
     * **Il est court, et c'est délibéré.** La rédaction se fait dans la requête du
     * lecteur, pendant qu'il attend sa page. Mieux vaut une phrase de gabarit servie en
     * huit secondes qu'une belle phrase servie en trente — et à trente, il aura rechargé.
     */
    private const float DELAI_SECONDES = 8.0;

    /**
     * Combien de lacunes partent en un appel.
     *
     * Un seul appel pour tout le lot plutôt qu'un par question : le corpus de l'arbre est
     * la partie coûteuse de la charge, et l'envoyer vingt fois pour vingt phrases serait
     * vingt fois le même export. Le plafond existe pour que le lot reste lisible par le
     * modèle et que la réponse reste bornée.
     */
    private const int LOT_MAXIMAL = 25;

    /** Une question est une phrase. Au-delà, ce n'est plus une question. */
    private const int LONGUEUR_MAXIMALE = 280;

    public function __construct(
        /**
         * Le service décoré — {@see RedactionParGabarit} en production.
         *
         * `#[AutowireDecorated]` injecte `.inner`. **L'ordre importe** : le repli est le
         * dernier maillon, et c'est lui qui garantit une entrée par lacune reçue. Inverser
         * les deux ferait d'une panne du tiers une page vide.
         */
        #[AutowireDecorated]
        private RedacteurDeQuestions $repli,
        private ReglagesDeGemini $reglages,
        private CoupeCircuit $coupeCircuit,
        private HttpClientInterface $http,
        private PortArbrePourQuestions $arbre,
        private CorpusDeLArbre $corpus,
        private AuditService $audit,
        private LoggerInterface $journal,
        // Le corpus est borné au périmètre du destinataire (ADR 0003, révision du
        // 2026-10-04) — par le point unique du module, pas par une règle d'ici.
        private LacunesVisibles $visibles,
    ) {
    }

    public function rediger(array $lacunes): array
    {
        // Porte 1 : aucun appel sortant sur une page qui n'a rien de neuf à rédiger —
        // c'est-à-dire sur la quasi-totalité des chargements.
        if ([] === $lacunes) {
            return [];
        }

        // Portes 2 et 3. Aucune alerte : une clé absente est un état normal, et un
        // coupe-circuit ouvert a déjà été journalisé au moment où il s'est ouvert.
        if (!$this->reglages->configure() || !$this->coupeCircuit->passant()) {
            return $this->repli->rediger($lacunes);
        }

        $lot = \array_slice($lacunes, 0, self::LOT_MAXIMAL);

        try {
            $phrases = $this->appeler($lot);
            $this->coupeCircuit->succes();
        } catch (\Throwable $e) {
            $this->coupeCircuit->echec();

            // Le message de l'exception, jamais la charge ni la clé. Le niveau est
            // `warning` et non `error` : rien n'est cassé côté produit, la page sert
            // ses questions — c'est la qualité de la formulation qui baisse.
            $this->journal->warning('Rédaction des questions : le modèle n\'a pas répondu, repli sur les gabarits.', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
                'lacunes' => \count($lot),
            ]);

            return $this->repli->rediger($lacunes);
        }

        return $this->completerParLeRepli($lacunes, $phrases);
    }

    /**
     * L'appel, et la lecture de sa réponse.
     *
     * @param list<Lacune> $lot
     *
     * @return array<string, string> indexé par signature — **incomplet est normal**
     */
    private function appeler(array $lot): array
    {
        $fiches = $this->visibles->bornerLeCorpus($this->arbre->fiches());

        $reponse = $this->http->request(
            'POST',
            \sprintf('%s/models/%s:generateContent', $this->reglages->pointDacces(), $this->reglages->modele()),
            [
                // La clé voyage en en-tête et **jamais en paramètre d'URL** : une URL
                // entre dans les journaux d'accès des mandataires, les rapports
                // d'erreur et l'historique des outils. Un en-tête, non.
                'headers' => ['x-goog-api-key' => $this->reglages->cleApi()],
                'json' => $this->charge($lot, $fiches),
                'timeout' => self::DELAI_SECONDES,
                // Le temps total, délai d'établissement compris. Sans lui, un tiers qui
                // répond lentement mais régulièrement tiendrait la requête indéfiniment.
                'max_duration' => self::DELAI_SECONDES,
            ],
        );

        $brut = $reponse->toArray();

        $this->consignerLAppel($fiches, $lot);

        return $this->lire($brut, $lot);
    }

    /**
     * @param list<Lacune>       $lot
     * @param list<FicheEtudiee> $fiches
     *
     * @return array<string, mixed>
     */
    private function charge(array $lot, array $fiches): array
    {
        $aRediger = [];

        foreach ($lot as $rang => $lacune) {
            $aRediger[] = [
                // Un rang local à l'appel, jamais la signature : c'est une empreinte
                // interne, elle n'a aucune raison de sortir et rien dans la réponse ne
                // doit pouvoir être relu comme désignant une ligne.
                'ref' => $rang + 1,
                'personne' => $lacune->sujetLabel,
                'manque' => $lacune->nature->label(),
                'question_par_defaut' => $lacune->texteParDefaut(),
            ];
        }

        return [
            'systemInstruction' => ['parts' => [['text' => self::CONSIGNE]]],
            'contents' => [[
                'role' => 'user',
                'parts' => [['text' => json_encode(
                    ['arbre' => $this->corpus->pour($fiches), 'a_rediger' => $aRediger],
                    \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE,
                )]],
            ]],
            'generationConfig' => [
                'responseMimeType' => 'application/json',
                // Basse, mais pas nulle : la variété entre vingt questions est
                // précisément ce qu'on achète ici. À zéro, on obtiendrait vingt fois la
                // même tournure, c'est-à-dire le gabarit en plus cher.
                'temperature' => 0.4,
            ],
        ];
    }

    /**
     * La consigne. Elle dit au modèle **ce qu'il n'a pas le droit de faire**, parce que
     * c'est là que se jouent les défauts coûteux.
     */
    private const string CONSIGNE = <<<'TEXTE'
        Tu reformules des questions destinées aux membres d'une famille, au sujet de leur
        arbre généalogique. Tu écris en français, en vouvoyant.

        Pour chaque entrée de « a_rediger », rends UNE phrase qui pose la même question
        que « question_par_defaut », en t'appuyant sur « arbre » pour situer la personne
        par rapport à sa parenté quand cela rend la question plus claire (« la sœur de
        votre grand-mère », « le mari d'Alberte »).

        Règles absolues :
        - la phrase NOMME la personne, avec le nom exact donné dans « personne » ;
        - la phrase porte sur EXACTEMENT ce que demande « question_par_defaut » : tu ne
          demandes rien d'autre, et surtout rien de plus ;
        - une seule phrase, sans retour à la ligne, moins de 280 caractères ;
        - tu n'inventes aucun fait, aucune date, aucun lieu, aucun lien de parenté ;
        - si la personne est vivante, tu n'écris pas au passé à son sujet.

        Rends un tableau JSON : [{"ref": <entier>, "texte": "<la phrase>"}]
        TEXTE;

    /**
     * Lit la réponse, et **refuse ce qui ne va pas** plutôt que de le servir.
     *
     * @param array<mixed> $brut
     * @param list<Lacune> $lot
     *
     * @return array<string, string>
     */
    private function lire(array $brut, array $lot): array
    {
        $texte = $brut['candidates'][0]['content']['parts'][0]['text'] ?? null;

        if (!\is_string($texte)) {
            // Le cas d'un `200` sans contenu exploitable : filtre de sécurité du
            // fournisseur, quota atteint d'une autre façon, forme changée. Il ne lève
            // pas de `\Throwable` de transport, et c'est pourquoi il est traité ici.
            throw new \RuntimeException('Réponse du modèle sans texte exploitable.');
        }

        $items = json_decode($texte, true, 8, \JSON_THROW_ON_ERROR);

        if (!\is_array($items)) {
            throw new \RuntimeException('Réponse du modèle qui n\'est pas un tableau.');
        }

        $phrases = [];

        foreach ($items as $item) {
            if (!\is_array($item)) {
                continue;
            }

            $ref = $item['ref'] ?? null;
            $phrase = $item['texte'] ?? null;

            if (!\is_int($ref) || !isset($lot[$ref - 1]) || !\is_string($phrase)) {
                continue;
            }

            $lacune = $lot[$ref - 1];
            $propre = trim($phrase);

            if (!self::phraseAcceptable($propre, $lacune)) {
                // Silencieusement écartée : le repli la remplacera. On ne journalise pas
                // chaque phrase refusée — sur un lot de vingt, cela ferait vingt lignes
                // pour un incident qui se lit déjà dans `redacteur`.
                continue;
            }

            $phrases[$lacune->signature()] = $propre;
        }

        return $phrases;
    }

    /**
     * ═══════════════════════════════════════════════════════════════════════════
     *  LE CONTRÔLE QUI COMPTE : LA PHRASE NOMME-T-ELLE LA BONNE PERSONNE ?
     * ═══════════════════════════════════════════════════════════════════════════.
     *
     * C'est le seul défaut de ce chantier qu'un modèle peut produire et qui **ne se
     * voit pas** : une question parfaitement écrite, parfaitement plausible, qui porte
     * sur quelqu'un d'autre. Le membre répond de bonne foi, la réponse entre dans la
     * file au nom de la mauvaise fiche, et l'administrateur valide un fait juste sur la
     * mauvaise personne.
     *
     * Le contrôle est bête et il tient : **le nom du sujet doit figurer dans la
     * phrase**. La consigne l'exige déjà ; une consigne n'est pas une garantie.
     *
     * *Il ne prouve pas que la phrase pose la bonne question* — rien d'automatique ne
     * le prouverait. Il attrape le cas où elle porte sur la mauvaise personne, qui est
     * celui dont la conséquence est irréversible.
     *
     * Les deux autres contrôles sont de forme : une phrase, pas un paragraphe.
     */
    private static function phraseAcceptable(string $phrase, Lacune $lacune): bool
    {
        if ('' === $phrase || mb_strlen($phrase) > self::LONGUEUR_MAXIMALE) {
            return false;
        }

        if (str_contains($phrase, "\n")) {
            return false;
        }

        return str_contains($phrase, $lacune->sujetLabel);
    }

    /**
     * Le repli comble **tout ce que le modèle n'a pas rendu** — refus de forme compris,
     * et les lacunes au-delà du lot.
     *
     * @param list<Lacune>          $lacunes
     * @param array<string, string> $phrases
     *
     * @return array<string, TexteDeQuestion>
     */
    private function completerParLeRepli(array $lacunes, array $phrases): array
    {
        $manquantes = [];
        $textes = [];

        foreach ($lacunes as $lacune) {
            $signature = $lacune->signature();

            if (isset($phrases[$signature])) {
                $textes[$signature] = new TexteDeQuestion($phrases[$signature], RedacteurDeQuestion::Gemini);

                continue;
            }

            $manquantes[] = $lacune;
        }

        // Le repli est le **dernier maillon** : c'est son appel ici qui garantit une
        // entrée par lacune reçue, quoi qu'ait fait le tiers.
        return $textes + $this->repli->rediger($manquantes);
    }

    /**
     * Le journal d'audit : **des comptes, jamais le corpus, jamais la réponse, jamais
     * la clé**.
     *
     * Sans cette ligne, « combien de vivants sont partis chez le tiers ce mois-ci » n'a
     * pas de réponse — et c'est la première question qu'un membre posera.
     *
     * @param list<FicheEtudiee> $fiches
     * @param list<Lacune>       $lot
     */
    private function consignerLAppel(array $fiches, array $lot): void
    {
        $vivantes = \count(array_filter($fiches, static fn (FicheEtudiee $f): bool => $f->vivante));

        $this->audit->enregistrer(
            AuditAction::AppelModeleDeLangage,
            'Question\\Redaction',
            null,
            $this->reglages->modele(),
            [
                'personnes_transmises' => \count($fiches),
                'dont_vivantes' => $vivantes,
                'questions_demandees' => \count($lot),
            ],
        );
    }
}
