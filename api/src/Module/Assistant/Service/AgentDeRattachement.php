<?php

declare(strict_types=1);

namespace App\Module\Assistant\Service;

use App\Module\Assistant\Exception\AssistantEnErreur;
use App\Module\Membership\Contract\DeclarationDuCompte;
use Psr\Log\LoggerInterface;

/**
 * ═══════════════════════════════════════════════════════════════════════════════
 *  LA BOUCLE D'AGENT DU RATTACHEMENT : « qui est cette personne qui vient de s'inscrire ? »
 * ═══════════════════════════════════════════════════════════════════════════════.
 *
 * Une personne s'est inscrite par le lien d'invitation sans se trouver dans l'arbre. Elle
 * a laissé son nom, ses prénoms, sa date de naissance et un texte libre qui décrit sa
 * famille (« mon père est Jean Dupont, né vers 1960 à Lille… »). Le modèle confronte ce
 * texte à l'arbre et conclut, pour l'administrateur :
 *
 * - **`fiche_existante`** — c'est probablement la fiche n° X (l'arbre la connaissait, mais
 *   sous une date ou une orthographe que la recherche exacte de l'inscription n'a pas
 *   reconnue) ;
 * - **`absente`** — elle n'est pas dans l'arbre, et voici la demande de création qui l'y
 *   ferait entrer, reliée à ses parents reconnus ;
 * - **`indecise`** — le texte ne permet pas de trancher.
 *
 * ── Même mécanique que {@see AgentDeLAssistant}, autre question ─────────────────
 *
 * Mêmes outils de lecture (`chercher_personnes`, `lire_personne`), même transport, mêmes
 * bornes ({@see ReglagesDeLAssistant} : huit tours, quatre-vingt-dix secondes), même mode
 * `ANY`, même relance d'un tour rendu en prose, même contrainte au dernier tour. Seuls
 * changent la consigne, le premier message et l'outil final — `conclure` au lieu de
 * `proposer`. Les deux lectures bas niveau des réponses (`appelsDeFonction`,
 * `partsRenvoyables`) sont celles de l'agent de proposition, rendues publiques pour ne
 * pas les écrire deux fois.
 *
 * ── Ce que le modèle reçoit, et ce qu'il ne reçoit JAMAIS ───────────────────────
 *
 * Il reçoit la déclaration (nom, prénoms, date, texte), la **transcription** du message
 * vocal quand il y en a une (2 octobre 2026, soir ; étiquetée comme telle, parce qu'une
 * transcription automatique écorche les noms propres), et, à titre d'indice, la personne
 * que l'invitation pré-liait. Jamais le son lui-même : il a été transcrit à part
 * ({@see TranscriptionDesMessagesVocaux}). **Jamais le courriel** : il n'aide en rien à reconnaître une
 * fiche, et c'est un identifiant de vivant qui n'a rien à faire chez un tiers.
 *
 * ── Ce qui n'est JAMAIS journalisé ──────────────────────────────────────────────
 *
 * Même règle que l'agent de proposition : ni la charge (la description parle de vivants),
 * ni la réponse, ni la clé. Seuls partent au journal la classe de l'exception, son
 * message de transport, le numéro du tour.
 */
final readonly class AgentDeRattachement
{
    /** L'outil final : ses arguments SONT le résultat. */
    public const string CONCLURE = 'conclure';

    /** En deçà, on n'appelle plus : un appel de deux secondes n'aboutirait pas. */
    private const float DELAI_MINIMAL_PAR_APPEL = 3.0;

    public function __construct(
        private TransportDuModele $transport,
        private OutilsDeLAssistant $outils,
        private ReglagesDeLAssistant $reglages,
        private LoggerInterface $journal,
    ) {
    }

    public function analyser(DeclarationDuCompte $declaration): ResultatDeLAgent
    {
        $debut = microtime(true);
        $contenus = [['role' => 'user', 'parts' => [['text' => self::premierMessage($declaration)]]]];
        $appelsDOutils = [];

        for ($tour = 1; $tour <= ReglagesDeLAssistant::TOURS_MAXIMAUX; ++$tour) {
            $reste = ReglagesDeLAssistant::DELAI_GLOBAL_SECONDES - (microtime(true) - $debut);

            if ($reste < self::DELAI_MINIMAL_PAR_APPEL) {
                $this->journal->warning('Rattachement assisté : délai global atteint avant la conclusion.', ['tour' => $tour]);

                throw new AssistantEnErreur('L’analyse a pris trop de temps. Relancez-la dans un moment, ou rattachez ce compte à la main.');
            }

            $dernier = ReglagesDeLAssistant::TOURS_MAXIMAUX === $tour;
            $reponse = $this->appeler($this->charge($contenus, $dernier), $reste, $tour);
            $parts = $reponse['candidates'][0]['content']['parts'] ?? null;

            if (!\is_array($parts) || [] === $parts) {
                // Le motif est une énumération du fournisseur, sans contenu.
                $this->journal->warning('Rattachement assisté : réponse du modèle sans contenu.', [
                    'tour' => $tour,
                    'finishReason' => \is_string($reponse['candidates'][0]['finishReason'] ?? null) ? $reponse['candidates'][0]['finishReason'] : null,
                    'blockReason' => \is_string($reponse['promptFeedback']['blockReason'] ?? null) ? $reponse['promptFeedback']['blockReason'] : null,
                ]);

                throw new AssistantEnErreur('L’analyse automatique n’a pas abouti. Relancez-la, ou rattachez ce compte à la main.');
            }

            $appels = AgentDeLAssistant::appelsDeFonction($parts);

            foreach ($appels as $appel) {
                if (self::CONCLURE === $appel['name']) {
                    return new ResultatDeLAgent($appel['args'], $tour, $appelsDOutils);
                }
            }

            // Rendu tel quel : la signature de pensée d'un modèle à raisonnement doit
            // revenir intacte (voir `AgentDeLAssistant`).
            $contenus[] = ['role' => 'model', 'parts' => AgentDeLAssistant::partsRenvoyables($parts)];

            if ([] === $appels) {
                $contenus[] = ['role' => 'user', 'parts' => [['text' => self::RELANCE]]];

                continue;
            }

            $reponses = [];
            foreach ($appels as $appel) {
                $appelsDOutils[$appel['name']] = ($appelsDOutils[$appel['name']] ?? 0) + 1;

                $reponses[] = ['functionResponse' => [
                    'name' => $appel['name'],
                    // `proposer` n'est pas offert ici : s'il est appelé quand même,
                    // `executer()` rend une erreur au modèle, qui se corrige.
                    'response' => $this->outils->executer($appel['name'], $appel['args']),
                ]];
            }

            $contenus[] = ['role' => 'user', 'parts' => $reponses];
        }

        $this->journal->warning('Rattachement assisté : le modèle n’a pas appelé « conclure » dans le nombre de tours imparti.', [
            'tours' => ReglagesDeLAssistant::TOURS_MAXIMAUX,
            'outils' => $appelsDOutils,
        ]);

        throw new AssistantEnErreur('L’analyse automatique n’est pas parvenue à conclure. Relancez-la, ou rattachez ce compte à la main.');
    }

    /**
     * Les outils offerts : les deux lectures de l'agent de proposition, et `conclure`.
     *
     * `creation` reprend **exactement** le schéma d'un élément de `demandes[]` de
     * `proposer` — mêmes personnes, mêmes liens, mêmes dates —, restreint à la nature
     * `creation_personne` : c'est ce qui permet de le traduire par
     * {@see TraducteurDePropositions}, sans second traducteur.
     *
     * @return list<array<string, mixed>>
     */
    public static function declarations(): array
    {
        $lectures = [];
        $demande = null;

        foreach (OutilsDeLAssistant::declarations() as $declaration) {
            if (\in_array($declaration['name'], [OutilsDeLAssistant::CHERCHER, OutilsDeLAssistant::LIRE], true)) {
                $lectures[] = $declaration;
            }

            if (OutilsDeLAssistant::PROPOSER === $declaration['name']) {
                $demande = $declaration['parameters']['properties']['demandes']['items'] ?? null;
            }
        }

        \assert(\is_array($demande));
        $demande['properties']['nature'] = ['type' => 'string', 'enum' => ['creation_personne']];
        unset($demande['properties']['personId'], $demande['properties']['changements']);
        $demande['description'] = 'Uniquement si la personne est absente de l’arbre : la demande d’ajout. « #p1 » est TOUJOURS la personne inscrite elle-même (son identité sera reprise de sa déclaration : donne seulement son sexe et son lieu de naissance s’il est écrit) ; « #p2 », « #p3 »… sont ses proches décrits dans le texte et eux aussi absents de l’arbre. Au moins un lien vers une personne de l’arbre (identifiant numérique), en général « #p1 enfant_de <père> et <mère> ».';

        $conclure = [
            'name' => self::CONCLURE,
            'description' => 'Outil FINAL : rend ta conclusion à l’administrateur. À appeler une seule fois, quand ta recherche est terminée.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'resume' => ['type' => 'string', 'description' => 'Ce que tu as compris de la déclaration et de ta recherche, en 1 à 3 phrases, sans aucune donnée de l’arbre absente de la description.'],
                    'avertissements' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Ce que l’administrateur doit savoir : homonymie, contradiction entre le texte et l’arbre, parent introuvable.'],
                    'verdict' => [
                        'type' => 'string',
                        'enum' => ['fiche_existante', 'absente', 'indecise'],
                        'description' => 'fiche_existante = la personne inscrite EST une fiche de l’arbre ; absente = elle n’y est pas (remplir « creation ») ; indecise = impossible de trancher.',
                    ],
                    'fiche' => [
                        'type' => 'object',
                        'description' => 'Seulement pour fiche_existante : la fiche de la personne inscrite.',
                        'properties' => [
                            'personId' => ['type' => 'integer'],
                            'confiance' => ['type' => 'string', 'enum' => ['haute', 'moyenne', 'faible']],
                            'justification' => ['type' => 'string', 'description' => 'Pourquoi c’est elle : ce qui concorde entre la déclaration et la fiche (parents, date, lieu).'],
                        ],
                        'required' => ['personId', 'confiance', 'justification'],
                    ],
                    'alternatives' => [
                        'type' => 'array',
                        'description' => 'Au plus 3 autres fiches qui pourraient être la personne inscrite. Vide le plus souvent.',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'personId' => ['type' => 'integer'],
                                'justification' => ['type' => 'string'],
                            ],
                            'required' => ['personId', 'justification'],
                        ],
                    ],
                    'creation' => $demande,
                ],
                'required' => ['resume', 'verdict'],
            ],
        ];

        return [...$lectures, $conclure];
    }

    /**
     * @param array<string, mixed> $charge
     *
     * @return array<string, mixed>
     */
    private function appeler(array $charge, float $delai, int $tour): array
    {
        try {
            return $this->transport->generer($this->reglages->modele(), $charge, $delai);
        } catch (\Throwable $e) {
            $this->journal->warning('Rattachement assisté : l’appel au modèle a échoué.', [
                'tour' => $tour,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            throw new AssistantEnErreur('L’analyse automatique n’a pas pu aboutir. Relancez-la dans un moment, ou rattachez ce compte à la main.', $e);
        }
    }

    /**
     * @param list<array<string, mixed>> $contenus
     *
     * @return array<string, mixed>
     */
    private function charge(array $contenus, bool $dernierTour): array
    {
        $appel = ['mode' => 'ANY'];

        if ($dernierTour) {
            $appel['allowedFunctionNames'] = [self::CONCLURE];
        }

        return [
            'systemInstruction' => ['parts' => [['text' => self::CONSIGNE]]],
            'contents' => $contenus,
            'tools' => [['functionDeclarations' => self::declarations()]],
            'toolConfig' => ['functionCallingConfig' => $appel],
            // Basse, comme l'agent de proposition : de la lecture fidèle, pas de la variété.
            'generationConfig' => ['temperature' => 0.2],
        ];
    }

    /**
     * Le premier message : la déclaration, **sans le courriel**.
     */
    public static function premierMessage(DeclarationDuCompte $declaration): string
    {
        $lignes = [
            'Une personne vient de s’inscrire sur l’arbre familial sans trouver sa fiche. Voici ce qu’elle a déclaré.',
            '',
            \sprintf('Nom : %s', $declaration->surname),
            \sprintf('Prénoms : %s', $declaration->givenNames),
            \sprintf('Date de naissance déclarée : %s', $declaration->birthDate->format('Y-m-d')),
        ];

        $description = trim($declaration->description);

        if ('' !== $description) {
            $lignes[] = '';
            $lignes[] = 'Sa description de sa famille :';
            $lignes[] = '« '.$description.' »';
        }

        $transcription = trim($declaration->transcription ?? '');

        if ($declaration->aUnMessageVocal && '' !== $transcription) {
            // La transcription vaut la description — avec sa fragilité propre : un nom
            // entendu n'est pas un nom écrit. Le dire évite qu'un « Dupond » entendu pour
            // « Dupont » fasse conclure à l'absence.
            $lignes[] = '';
            $lignes[] = '' === $description
                ? 'Elle n’a rien écrit : elle a laissé un message vocal. Voici sa transcription automatique (les noms propres et les dates y sont tels qu’entendus — l’orthographe peut différer de l’arbre ; « [?] » marque un nom incertain) :'
                : 'Elle a aussi laissé un message vocal, qui complète ce texte. Voici sa transcription automatique (les noms propres et les dates y sont tels qu’entendus — l’orthographe peut différer de l’arbre ; « [?] » marque un nom incertain) :';
            $lignes[] = '« '.$transcription.' »';
            $lignes[] = 'Sers-t’en exactement comme de la description écrite. Si la transcription et le texte se contredisent, signale-le dans « avertissements ».';
        } elseif ($declaration->aUnMessageVocal) {
            // Pas (encore) de transcription — elle a échoué, ou n'a pas été demandée : le
            // dire évite que l'agent conclue « indécise » faute d'un texte qu'on ne lui a
            // pas donné.
            $lignes[] = '';
            $lignes[] = '' === $description
                ? 'Elle n’a rien écrit : elle a laissé un message vocal, dont la transcription n’est pas disponible. Cherche à partir de son identité, et dis dans ton résumé que le message vocal reste à écouter.'
                : 'Elle a aussi laissé un message vocal, dont la transcription n’est pas disponible : il peut compléter ce texte, et l’administrateur l’écoutera.';
        }

        if (null !== $declaration->invitationPersonId) {
            $lignes[] = '';
            $lignes[] = \sprintf('Indice : le lien d’invitation qu’elle a utilisé désignait la personne n° %d de l’arbre (lire_personne pour la situer). Ce n’est qu’un indice : le lien a pu être partagé à toute une famille.', $declaration->invitationPersonId);
        }

        $lignes[] = '';
        $lignes[] = 'Cherche-la dans l’arbre, cherche ses parents et ses proches, puis appelle « conclure ».';

        return implode("\n", $lignes);
    }

    private const string RELANCE = 'Réponds uniquement en appelant un outil : poursuis ta recherche, ou appelle « conclure » avec ta conclusion.';

    /**
     * La consigne. Comme celle de l'agent de proposition, elle dit surtout **ce que le
     * modèle n'a pas le droit de faire** : inventer une fiche, rattacher sur une
     * ressemblance de nom, recopier une donnée de l'arbre dans une phrase.
     */
    private const string CONSIGNE = <<<'TEXTE'
        Tu aides l’administrateur d’un arbre généalogique familial. Une personne vient de
        créer un compte avec le lien d’invitation de la famille, sans trouver sa fiche dans
        l’arbre. Elle a donné son nom, ses prénoms, sa date de naissance et un texte libre
        qui décrit sa famille — écrit, ou dit dans un message vocal dont tu reçois la
        transcription automatique. Ton travail : dire à l’administrateur si cette personne est
        DÉJÀ dans l’arbre (et laquelle), ou si elle en est absente (et comment l’y ajouter).
        Tu ne rattaches rien toi-même : l’administrateur décide. Tu écris en français.

        Méthode :
        1. Cherche la personne elle-même (chercher_personnes avec son nom, puis avec ses
           prénoms, puis avec un nom de jeune fille ou d’usage s’il est cité). La recherche
           de l’inscription était exacte : une date ou une orthographe légèrement différente
           a pu la faire échouer alors que la fiche existe.
        2. Cherche les proches que le texte cite (parents, grands-parents, fratrie,
           conjoint) et lis leurs fiches (lire_personne) pour vérifier les liens : une
           personne de l’arbre dont les parents sont ceux que le texte nomme est un
           candidat sérieux.
        3. Appelle « conclure » une seule fois, à la fin.

        Règles absolues :
        - « fiche_existante » seulement si une fiche VIVANTE concorde par le nom ET par au
          moins un autre élément (date proche, parents, lieu). Un nom seul ne suffit pas :
          dans le doute, « indecise », et cite les fiches possibles dans « alternatives ».
        - Ne propose jamais une personne décédée comme étant la personne inscrite.
        - « absente » quand tu as cherché sans la trouver. Remplis alors « creation » :
          « #p1 » est la personne inscrite (son nom, ses prénoms et sa date viendront de sa
          déclaration ; donne seulement son sexe, déduit du texte ou des prénoms, et son lieu
          de naissance s’il est écrit). Relie « #p1 » à ses parents REconnus dans l’arbre
          par leur identifiant numérique (« #p1 enfant_de 12 et 57 »). Un parent cité mais
          absent de l’arbre devient « #p2 » dans la même demande, relié à « #p1 », et
          lui-même relié à l’arbre si ses propres parents y sont.
        - Ne crée jamais une personne qui existe déjà dans l’arbre : relie-la par son
          identifiant.
        - Dates des proches : garde la précision réelle du texte (« né vers 1960 » →
          « environ » 1960). Jamais de jour ou de mois inventé.
        - « resume », « justification » et « avertissements » ne citent que la déclaration
          (« elle écrit que son père est Jean Dupont, né vers 1960 ») et des NOMS de fiches.
          N’y recopie jamais une date, un lieu ou une autre donnée venue de l’arbre.
        - « confiance » : haute si le nom, la date et les deux parents concordent ; moyenne
          si le nom et un parent concordent ; faible sinon.
        TEXTE;
}
