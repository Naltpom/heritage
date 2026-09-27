<?php

declare(strict_types=1);

namespace App\Module\Assistant\Service;

use App\Module\Assistant\Exception\AssistantEnErreur;
use Psr\Log\LoggerInterface;

/**
 * ═══════════════════════════════════════════════════════════════════════════════
 *  LA BOUCLE D'AGENT : le modèle lit, cherche, lit une fiche, et finit par PROPOSER.
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * *Function calling* de Gemini, en `generateContent` :
 *
 *     envoyer → le modèle appelle chercher_personnes / lire_personne
 *             → on exécute, on renvoie des `functionResponse`
 *             → … jusqu'à ce qu'il appelle `proposer`, l'outil final.
 *
 * Les arguments de `proposer` SONT le résultat. Ils ne sont pas encore des demandes :
 * {@see TraducteurDePropositions} les met en forme, `Proposal` les complète et les valide.
 *
 * ── Les bornes, et ce qui se passe quand on les touche ───────────────────────────
 *
 * - **Huit tours au plus.** Au dernier, l'appel est **contraint** à `proposer`
 *   (`allowedFunctionNames`) : le modèle rend ce qu'il a, plutôt qu'une neuvième
 *   recherche que personne ne lira.
 * - **Quatre-vingt-dix secondes en tout.** Chaque appel reçoit le temps qui reste, pas
 *   un délai fixe : sans cela, huit appels de trente secondes feraient quatre minutes.
 * - **Mode `ANY`** à chaque tour : le modèle appelle toujours un outil, il ne « répond »
 *   jamais en prose. Une réponse sans appel est relancée une fois par tour, pas servie.
 * - Le modèle n'appelle jamais `proposer` → `502 assistant_erreur`. On ne fabrique pas
 *   de proposition vide à sa place : « l'assistant n'a rien trouvé » et « l'assistant n'a
 *   pas fini » ne disent pas la même chose au membre.
 *
 * ── Ce qui n'est JAMAIS journalisé ──────────────────────────────────────────────
 *
 * La charge (elle contient les documents et des personnes vivantes), la réponse (elle
 * les cite), la clé. Seuls partent au journal : la classe de l'exception, son message de
 * transport, le numéro du tour.
 */
final readonly class AgentDeLAssistant
{
    /** En deçà, on n'appelle plus : un appel de deux secondes n'aboutirait pas. */
    private const float DELAI_MINIMAL_PAR_APPEL = 3.0;

    public function __construct(
        private TransportDuModele $transport,
        private OutilsDeLAssistant $outils,
        private ReglagesDeLAssistant $reglages,
        private LoggerInterface $journal,
    ) {
    }

    public function analyser(EntreeDAnalyse $entree): ResultatDeLAgent
    {
        $debut = microtime(true);
        $contenus = [['role' => 'user', 'parts' => $this->premierMessage($entree)]];
        $appelsDOutils = [];

        for ($tour = 1; $tour <= ReglagesDeLAssistant::TOURS_MAXIMAUX; ++$tour) {
            $reste = ReglagesDeLAssistant::DELAI_GLOBAL_SECONDES - (microtime(true) - $debut);

            if ($reste < self::DELAI_MINIMAL_PAR_APPEL) {
                $this->journal->warning('Assistant : délai global atteint avant la proposition.', ['tour' => $tour]);

                throw new AssistantEnErreur('L’analyse a pris trop de temps. Réessayez avec moins de documents, ou utilisez le formulaire.');
            }

            $dernier = ReglagesDeLAssistant::TOURS_MAXIMAUX === $tour;
            $reponse = $this->appeler($this->charge($contenus, $dernier), $reste, $tour);
            $parts = $reponse['candidates'][0]['content']['parts'] ?? null;

            if (!\is_array($parts) || [] === $parts) {
                // Le `200` sans contenu : filtre de sécurité, quota épuisé autrement,
                // forme changée. Le motif est une énumération du fournisseur, sans
                // contenu — on peut le journaliser.
                $this->journal->warning('Assistant : réponse du modèle sans contenu.', [
                    'tour' => $tour,
                    'finishReason' => \is_string($reponse['candidates'][0]['finishReason'] ?? null) ? $reponse['candidates'][0]['finishReason'] : null,
                    'blockReason' => \is_string($reponse['promptFeedback']['blockReason'] ?? null) ? $reponse['promptFeedback']['blockReason'] : null,
                ]);

                throw new AssistantEnErreur();
            }

            $appels = self::appelsDeFonction($parts);

            foreach ($appels as $appel) {
                if (OutilsDeLAssistant::PROPOSER === $appel['name']) {
                    return new ResultatDeLAgent($appel['args'], $tour, $appelsDOutils);
                }
            }

            // Le tour du modèle est rendu **tel quel** à l'historique : les modèles à
            // raisonnement y joignent une signature de pensée (`thoughtSignature`) qui doit
            // revenir intacte, sans quoi le tour suivant repart de zéro.
            $contenus[] = ['role' => 'model', 'parts' => self::partsRenvoyables($parts)];

            if ([] === $appels) {
                $contenus[] = ['role' => 'user', 'parts' => [['text' => self::RELANCE]]];

                continue;
            }

            $reponses = [];
            foreach ($appels as $appel) {
                $appelsDOutils[$appel['name']] = ($appelsDOutils[$appel['name']] ?? 0) + 1;

                $reponses[] = ['functionResponse' => [
                    'name' => $appel['name'],
                    'response' => $this->outils->executer($appel['name'], $appel['args']),
                ]];
            }

            $contenus[] = ['role' => 'user', 'parts' => $reponses];
        }

        $this->journal->warning('Assistant : le modèle n’a pas appelé « proposer » dans le nombre de tours imparti.', [
            'tours' => ReglagesDeLAssistant::TOURS_MAXIMAUX,
            'outils' => $appelsDOutils,
        ]);

        throw new AssistantEnErreur('L’assistant n’est pas parvenu à conclure. Réessayez en précisant votre texte, ou utilisez le formulaire.');
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
            // Le message d'une exception de transport Symfony cite l'URL et le statut,
            // jamais l'en-tête ni le corps : il peut aller au journal.
            $this->journal->warning('Assistant : l’appel au modèle a échoué.', [
                'tour' => $tour,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            throw new AssistantEnErreur(precedente: $e);
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
            $appel['allowedFunctionNames'] = [OutilsDeLAssistant::PROPOSER];
        }

        return [
            'systemInstruction' => ['parts' => [['text' => self::CONSIGNE]]],
            'contents' => $contenus,
            'tools' => [['functionDeclarations' => OutilsDeLAssistant::declarations()]],
            'toolConfig' => ['functionCallingConfig' => $appel],
            'generationConfig' => [
                // Basse : on veut de la lecture fidèle, pas de la variété. Une date lue
                // « avec imagination » est exactement l'aplatissement que le § 9.1 du
                // modèle interdit.
                'temperature' => 0.2,
            ],
        ];
    }

    /**
     * Le premier message : le texte du membre, ses documents, et le contexte qu'on
     * connaît de lui.
     *
     * @return list<array<string, mixed>>
     */
    private function premierMessage(EntreeDAnalyse $entree): array
    {
        $contexte = [];

        if (null !== $entree->personneDuMembre) {
            $contexte[] = \sprintf('Le membre qui écrit est la personne n° %d de l’arbre : « je », « ma mère », « mon grand-père » se lisent depuis elle (lire_personne pour la situer).', $entree->personneDuMembre);
        }

        if (null !== $entree->personneDeContexte) {
            $contexte[] = \sprintf('Il a ouvert l’assistant depuis la fiche de la personne n° %d : c’est probablement d’elle ou de ses proches qu’il parle.', $entree->personneDeContexte);
        }

        $texte = trim($entree->texte);
        $parts = [['text' => implode("\n", [
            'Voici ce qu’un membre de la famille apporte. Analyse-le, retrouve les personnes dans l’arbre avec les outils, puis appelle « proposer ».',
            ...$contexte,
            '',
            'Texte du membre :',
            '' === $texte ? '(aucun texte : seulement des documents)' : '« '.$texte.' »',
            '',
            \sprintf('Documents joints : %d.', \count($entree->documents)),
        ])]];

        foreach ($entree->documents as $rang => $document) {
            // L'enregistrement est dit tel : le modèle sait qu'il écoute un témoignage.
            $parts[] = ['text' => str_starts_with($document->typeMime, 'audio/')
                ? \sprintf('Document %d (enregistrement audio) :', $rang + 1)
                : \sprintf('Document %d :', $rang + 1)];
            $parts[] = ['inlineData' => [
                'mimeType' => $document->typeMime,
                'data' => base64_encode($document->octets),
            ]];
        }

        return $parts;
    }

    /**
     * @param array<mixed> $parts
     *
     * @return list<array{name: string, args: array<string, mixed>}>
     */
    private static function appelsDeFonction(array $parts): array
    {
        $appels = [];

        foreach ($parts as $part) {
            $appel = \is_array($part) ? ($part['functionCall'] ?? null) : null;

            if (!\is_array($appel) || !\is_string($appel['name'] ?? null)) {
                continue;
            }

            $arguments = \is_array($appel['args'] ?? null) ? $appel['args'] : [];

            /** @var array<string, mixed> $arguments */
            $appels[] = ['name' => $appel['name'], 'args' => $arguments];
        }

        return $appels;
    }

    /**
     * Les parts du modèle, prêtes à être renvoyées.
     *
     * Un seul correctif, et il est mécanique : `args: {}` est décodé en tableau PHP vide,
     * que `json_encode` réécrirait `[]` — une liste là où Gemini attend un objet, et la
     * requête suivante serait refusée. On le rend objet.
     *
     * @param array<mixed> $parts
     *
     * @return list<mixed>
     */
    private static function partsRenvoyables(array $parts): array
    {
        $propres = [];

        foreach ($parts as $part) {
            if (\is_array($part) && \is_array($part['functionCall'] ?? null) && [] === ($part['functionCall']['args'] ?? [])) {
                $part['functionCall']['args'] = new \stdClass();
            }

            $propres[] = $part;
        }

        return $propres;
    }

    /** La relance d'un tour rendu en prose : on veut un appel d'outil, pas une réponse. */
    private const string RELANCE = 'Réponds uniquement en appelant un outil : poursuis ta recherche, ou appelle « proposer » avec tes conclusions.';

    /**
     * La consigne. Elle dit surtout **ce que le modèle n'a pas le droit de faire** —
     * c'est là que se jouent les défauts coûteux : un doublon, une date inventée, une
     * donnée confidentielle recopiée dans une phrase que le membre lira.
     */
    private const string CONSIGNE = <<<'TEXTE'
        Tu es l’assistant de saisie d’un arbre généalogique familial. Un membre de la famille
        t’apporte un texte libre et/ou des documents (actes d’état civil, photos, livrets de
        famille, PDF, enregistrements audio). Ton travail : en tirer des DEMANDES de
        modification de l’arbre, qu’un administrateur validera ensuite. Tu n’appliques rien
        toi-même. Tu écris en français.

        Méthode :
        1. Lis les documents (écoute les enregistrements) et le texte. Relève chaque personne
           citée et ce qui est dit d’elle.
        2. Pour CHAQUE personne citée, cherche-la dans l’arbre (chercher_personnes), et lis sa
           fiche (lire_personne) quand il faut vérifier qu’il s’agit bien d’elle (dates, parents,
           conjoint). Une personne déjà présente se MODIFIE ou se RELIE ; elle ne se crée jamais
           une seconde fois. En cas d’homonymie que tu ne peux pas trancher, ne propose rien sur
           elle et dis-le dans « avertissements ».
        3. Appelle « proposer » une seule fois, à la fin.

        Règles absolues :
        - Ne propose QUE ce que les documents ou le texte du membre établissent. Aucune
          déduction présentée comme un fait, aucune date « probable » donnée comme exacte.
        - Dates : garde la précision réelle de la source. Année seule → « annee ». Âge au
          décès, « vers », « environ » → « environ » ou « entre » (fourchette). Jamais de jour
          ou de mois inventé.
        - Une « modification_personne » par fiche existante (avec tous ses champs modifiés),
          un « ajout_lien » pour relier des personnes qui existent déjà.
        - Les personnes nouvelles : une « creation_personne » par GROUPE de personnes
          nouvelles qui se tiennent. Une personne nouvelle et ses proches eux aussi absents
          de l’arbre (ses enfants, son conjoint, ses parents) vont dans LA MÊME demande, une
          entrée de « personnes » chacune, reliées entre elles par des liens
          (« #p2 enfant_de #p1 »). Au moins l’une d’elles est rattachée à une personne de
          l’arbre ; les autres peuvent ne l’être qu’à une personne nouvelle de la même
          demande. N’écarte JAMAIS un enfant, un conjoint ou un parent au motif que la
          personne à laquelle il se rattache n’existe pas encore : décris-les ensemble.
          Deux personnes nouvelles sans rapport entre elles font deux demandes.
        - Une personne nouvelle porte une référence « #p1 », « #p2 »… qui ne vaut QUE dans
          sa propre demande ; dans les liens, une personne existante se désigne par son
          identifiant numérique (« 12 »).
        - Les liens se lisent du sujet vers l’autre : « #p1 enfant_de 12 » (12 est un parent
          de #p1), « 12 parent_de #p1 », « #p1 conjoint_de 12 ». Pour un enfant dont les deux
          parents sont connus, un seul lien enfant_de avec « autre » et « autre2 » — l’un et
          l’autre peuvent être des personnes nouvelles de la demande (« #p3 enfant_de #p1 et
          #p2 ») ou de l’arbre (« #p3 enfant_de #p1 et 12 »).
        - Ne propose pas une valeur déjà présente sur la fiche.
        - « justification » et « resume » ne citent que les documents et le texte du membre
          (« acte de naissance, document 1 : née le 3 mars 1921 à Brest »). N’y recopie JAMAIS
          une information venue de l’arbre qui ne figure pas dans les documents ou le texte.
        - Les personnes marquées « confidentielle » dans les résultats des outils sont des
          vivants que ce membre n’a pas le droit de connaître en détail : tu peux les
          reconnaître, les viser et les nommer, mais tu ne cites jamais leurs dates, lieux ou
          autres données, nulle part.
        - « confiance » : haute si un acte officiel lisible l’établit, moyenne pour un
          témoignage précis ou un document partiellement lisible, faible sinon.
        - Un enregistrement audio est un TÉMOIGNAGE (le membre ou un proche qui raconte) :
          écoute-le en entier et traite ce qui y est dit comme le texte du membre. Il
          justifie au mieux une confiance « moyenne », et « justification » le cite
          (« enregistrement, document 2 : “ma tante Jeanne a eu trois enfants” »). Un
          passage inaudible ou un nom mal compris se signale dans « avertissements » ; il ne
          se devine jamais.
        - S’il n’y a rien à proposer, appelle « proposer » avec une liste vide et explique
          pourquoi dans « resume ».

        Portraits (« portraits » de « proposer », facultatif) :
        - Si une PHOTO jointe (jamais un PDF ni un enregistrement) montre nettement le visage
          d’une personne que tu identifies avec une confiance raisonnable — par une légende,
          le texte du membre, le contexte du document —, propose-le comme portrait de cette personne : une
          personne de l’arbre (son identifiant, « 12 ») ou une personne nouvelle de cet appel
          (« #p1 », avec « demande » = le rang, 1 pour la première, de sa demande
          creation_personne).
        - Seulement un visage net et identifiable : pas de visage flou, minuscule, de dos ou
          caché, pas de devinette parmi plusieurs personnes. Dans le doute, abstiens-toi.
        - Au plus un portrait par personne (le meilleur visage).
        - « cadre » est la boîte du visage au format de tes boîtes de détection
          [ymin, xmin, ymax, xmax], normalisée sur 0–1000 dans le repère de l’image :
          haut = ymin, gauche = xmin, bas = ymax, droite = xmax (0,0 en haut à gauche).
          Englobe toute la tête avec une marge : cheveux, menton et haut des épaules compris.
        - « justification » dit d’où vient l’identification (document, légende, texte du
          membre), jamais une donnée de l’arbre.
        TEXTE;
}
