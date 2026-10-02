<?php

declare(strict_types=1);

namespace App\Module\Assistant\Service;

use App\Module\Assistant\Exception\AssistantEnErreur;
use Psr\Log\LoggerInterface;

/**
 * ═══════════════════════════════════════════════════════════════════════════════
 *  LA BOUCLE D'AGENT DE « POSER UNE QUESTION » : le modèle cherche, lit, et RÉPOND.
 * ═══════════════════════════════════════════════════════════════════════════════.
 *
 * ── Même mécanique que {@see AgentDeLAssistant}, autre question ─────────────────
 *
 * Même transport, mêmes bornes ({@see ReglagesDeLAssistant} : huit tours, cinquante-cinq
 * secondes, chaque appel recevant le temps qui reste), même mode `ANY`, même relance
 * d'un tour rendu en prose, même contrainte au dernier tour, mêmes lectures bas niveau des
 * réponses (`appelsDeFonction`, `partsRenvoyables`). Changent la consigne, les outils
 * ({@see OutilsDeConsultation}, qui ne montrent au modèle que le cercle du lecteur) et
 * l'outil final — `repondre`, un texte et la liste des fiches citées.
 *
 * Le modèle n'appelle jamais `repondre` → `502 assistant_erreur`. On ne fabrique pas de
 * réponse à sa place : « je ne sais pas » est une réponse que le modèle doit donner
 * lui-même, sur pièces, pas un repli du serveur.
 *
 * ── La conversation suivie ──────────────────────────────────────────────────────
 *
 * Les échanges précédents sont rendus au modèle comme des tours `user` / `model`
 * ordinaires, **avant** la question du jour : c'est ce qui lui fait lire « et son père ? »
 * comme la suite de « que sait-on de Jean Becquelin ? ». Ils arrivent ici déjà bornés et
 * déjà repassés au cercle du lecteur ({@see ConsultationParLAssistant}). Seule la
 * réponse finale de chaque échange revient — pas les appels d'outils qui y ont mené : le
 * modèle revérifie ce qu'il répète, et la consigne le lui demande.
 *
 * ── Ce qui n'est JAMAIS journalisé ──────────────────────────────────────────────
 *
 * Même règle que les deux autres agents : ni la question, ni l'historique, ni la réponse,
 * ni la charge, ni la clé. Seuls partent au journal la classe de l'exception, son message
 * de transport, le numéro du tour et le nombre d'appels par outil.
 */
final readonly class AgentDeConsultation
{
    /** En deçà, on n'appelle plus : un appel de deux secondes n'aboutirait pas. */
    private const float DELAI_MINIMAL_PAR_APPEL = 3.0;

    public function __construct(
        private TransportDuModele $transport,
        private OutilsDeConsultation $outils,
        private ReglagesDeLAssistant $reglages,
        private LoggerInterface $journal,
    ) {
    }

    public function repondre(EntreeDeQuestion $entree): ResultatDeLAgent
    {
        ReglagesDeLAssistant::accorderLeTemps();
        $debut = microtime(true);
        $contenus = [...self::historique($entree->historique), ['role' => 'user', 'parts' => [['text' => self::message($entree)]]]];
        $appelsDOutils = [];

        for ($tour = 1; $tour <= ReglagesDeLAssistant::TOURS_MAXIMAUX; ++$tour) {
            $reste = ReglagesDeLAssistant::DELAI_GLOBAL_SECONDES - (microtime(true) - $debut);

            if ($reste < self::DELAI_MINIMAL_PAR_APPEL) {
                $this->journal->warning('Question à l’assistant : délai global atteint avant la réponse.', ['tour' => $tour]);

                throw new AssistantEnErreur('La recherche a pris trop de temps. Reposez la question, plus précisément si possible.');
            }

            $dernier = ReglagesDeLAssistant::TOURS_MAXIMAUX === $tour;
            $reponse = $this->appeler($this->charge($contenus, $dernier), $reste, $tour);
            $parts = $reponse['candidates'][0]['content']['parts'] ?? null;

            if (!\is_array($parts) || [] === $parts) {
                // Le motif est une énumération du fournisseur, sans contenu.
                $this->journal->warning('Question à l’assistant : réponse du modèle sans contenu.', [
                    'tour' => $tour,
                    'finishReason' => \is_string($reponse['candidates'][0]['finishReason'] ?? null) ? $reponse['candidates'][0]['finishReason'] : null,
                    'blockReason' => \is_string($reponse['promptFeedback']['blockReason'] ?? null) ? $reponse['promptFeedback']['blockReason'] : null,
                ]);

                throw new AssistantEnErreur('L’assistant n’a pas pu répondre. Reposez la question dans un moment.');
            }

            $appels = AgentDeLAssistant::appelsDeFonction($parts);

            foreach ($appels as $appel) {
                if (OutilsDeConsultation::REPONDRE === $appel['name']) {
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
                    'response' => $this->outils->executer($appel['name'], $appel['args'], $entree->personneDuLecteur),
                ]];
            }

            $contenus[] = ['role' => 'user', 'parts' => $reponses];
        }

        $this->journal->warning('Question à l’assistant : le modèle n’a pas appelé « repondre » dans le nombre de tours imparti.', [
            'tours' => ReglagesDeLAssistant::TOURS_MAXIMAUX,
            'outils' => $appelsDOutils,
        ]);

        throw new AssistantEnErreur('L’assistant n’est pas parvenu à conclure. Reposez la question, plus précisément si possible.');
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
            $this->journal->warning('Question à l’assistant : l’appel au modèle a échoué.', [
                'tour' => $tour,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            throw new AssistantEnErreur('L’assistant n’a pas pu répondre. Reposez la question dans un moment.', $e);
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
            $appel['allowedFunctionNames'] = [OutilsDeConsultation::REPONDRE];
        }

        return [
            'systemInstruction' => ['parts' => [['text' => self::CONSIGNE]]],
            'contents' => $contenus,
            'tools' => [['functionDeclarations' => OutilsDeConsultation::declarations()]],
            'toolConfig' => ['functionCallingConfig' => $appel],
            // Basse, comme les deux autres agents : on veut ce que disent les fiches, pas
            // une généalogie « vraisemblable ».
            'generationConfig' => ['temperature' => 0.2],
        ];
    }

    /**
     * Les échanges précédents, en tours `user` / `model` alternés.
     *
     * @param list<EchangePasse> $historique
     *
     * @return list<array<string, mixed>>
     */
    public static function historique(array $historique): array
    {
        $contenus = [];

        foreach ($historique as $echange) {
            $contenus[] = ['role' => 'user', 'parts' => [['text' => $echange->question]]];
            $contenus[] = ['role' => 'model', 'parts' => [['text' => [] === $echange->personIds
                ? $echange->reponse
                : $echange->reponse."\n\n(Fiches citées : ".implode(', ', $echange->personIds).'.)']]];
        }

        return $contenus;
    }

    /** La question du jour, et qui la pose. */
    public static function message(EntreeDeQuestion $entree): string
    {
        $lignes = [];

        if (null !== $entree->personneDuLecteur) {
            $lignes[] = \sprintf('Le membre qui pose la question est la personne n° %d de l’arbre : « je », « moi », « ma mère », « mon grand-père » se lisent depuis elle (lire_personne pour la situer).', $entree->personneDuLecteur);
        } else {
            $lignes[] = 'Le membre qui pose la question n’a pas de fiche dans l’arbre : une question sur « moi » ou « ma famille » ne peut pas être située, dis-le.';
        }

        $lignes[] = '';
        $lignes[] = 'Question :';
        $lignes[] = '« '.trim($entree->question).' »';

        return implode("\n", $lignes);
    }

    private const string RELANCE = 'Réponds uniquement en appelant un outil : poursuis ta recherche, ou appelle « repondre » avec ta réponse.';

    /**
     * La consigne. Comme celle des deux autres agents, elle dit surtout **ce que le modèle
     * n'a pas le droit de faire** : affirmer ce que les fiches n'établissent pas, faire
     * passer un arbre en ligne pour un acte, deviner qui est une personne confidentielle.
     */
    private const string CONSIGNE = <<<'TEXTE'
        Tu es l’assistant d’un arbre généalogique familial. Un membre de la famille te pose
        des questions sur l’arbre. Tu lui réponds en français, à partir des fiches que tes
        outils te montrent et de rien d’autre. Tu ne modifies rien et tu ne proposes aucune
        modification.

        Méthode :
        1. Retrouve les personnes dont parle la question : chercher_personnes par le nom,
           chercher_par_lieu pour un lieu ou une période. Puis lis leurs fiches
           (lire_personne) : parents, unions et enfants, fratrie, faits, sources, notes.
           Pour une parenté (« le grand-père de Juliette »), lis les fiches de proche en
           proche.
        2. « je », « moi », « mon père » désignent le membre : la personne dont le numéro
           t’est donné. lire_personne rend « parenteAvecLeLecteur », le lien de la fiche
           avec lui, calculé par l’arbre : sers-t’en pour « comment suis-je lié à… » plutôt
           que de le recalculer, et dis-le tel quel.
        3. Appelle « repondre » une seule fois, à la fin.

        Règles absolues :
        - Ne dis que ce que les fiches établissent. Aucune date, aucun lieu, aucun lien de
          parenté deviné ou « probable » présenté comme un fait. Si les fiches ne permettent
          pas de répondre, dis-le simplement — « Je ne sais pas : l’arbre ne le dit pas » —
          et, si c’est utile, ce qui manquerait pour le savoir. Mieux vaut « je ne sais pas »
          qu’une réponse inventée.
        - Distingue l’origine de ce que tu affirmes, d’après le champ « appui » des sources
          de la fiche : « acte » → appuyé par un acte (« d’après son acte de naissance ») ;
          « index » → un relevé ou un index ; « arbre » → repris d’un arbre en ligne, non
          vérifié : dis-le explicitement. Une fiche sans aucune source se dit « sans source
          citée dans l’arbre ».
        - Les dates gardent leur précision : « vers 1850 », « entre 1901 et 1909 »,
          « en 1921 ». Jamais un jour ou un mois qui n’est pas sur la fiche.
        - Une personne « confidentielle », nommée « Personne vivante », est une personne
          vivante que ce membre n’a pas le droit de connaître : tu ne sais d’elle que son
          existence. Ne cherche pas à deviner qui elle est, ne lui attribue rien, écris
          « une personne vivante ».
        - Cite chaque personne de l’arbre dont tu parles sous la forme
          [Prénom Nom](personne:12) — son nom tel que les outils le donnent et son numéro —
          et reporte ces numéros dans « personIds ». Ne cite que des numéros rendus par les
          outils.
        - Sois concis : quelques phrases, ou une courte liste à tirets pour une
          énumération. Pas de titre, pas de tableau, pas de gras.
        - Les échanges précédents de la conversation servent à comprendre les questions de
          suivi (« et son père ? ») ; ce qu’ils affirment se revérifie avec les outils avant
          d’être répété.
        TEXTE;
}
