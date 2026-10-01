<?php

declare(strict_types=1);

namespace App\Module\Assistant\Service;

use App\Module\Assistant\Entity\Conversation;
use App\Module\Assistant\Entity\Echange;
use App\Module\Assistant\Exception\AssistantEnErreur;
use App\Module\Core\Contract\AnnuaireDesMembresInterface;
use App\Module\Core\Enum\AuditAction;
use App\Module\Core\Service\AuditService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * ═══════════════════════════════════════════════════════════════════════════════
 *  UNE QUESTION, DE BOUT EN BOUT : l'historique borné, l'agent, la réponse vérifiée,
 *  l'échange gardé.
 * ═══════════════════════════════════════════════════════════════════════════════.
 *
 * **Lecture seule sur l'arbre.** Ce service n'écrit que les deux tables du module
 * (`ast_conversation`, `ast_echange`) et une ligne de journal. Il ne dépose aucune
 * demande : l'outil final est `repondre`, et aucun outil offert au modèle n'écrit.
 *
 * ── L'historique transmis, et ses bornes ────────────────────────────────────────
 *
 * Les {@see self::ECHANGES_TRANSMIS} derniers échanges, chacun coupé, et le tout borné à
 * {@see self::SIGNES_TRANSMIS} signes en partant du plus récent : une conversation de
 * quarante questions ne fait pas partir quarante réponses à chaque tour, et la question de
 * suivi n'a besoin que de ce qui précède. Chaque réponse rendue au modèle repasse d'abord
 * par le cercle **du moment** ({@see MentionsDeFiches}) : une personne sortie du cercle
 * depuis n'est pas renvoyée par son nom au tiers.
 *
 * ── Rien n'est gardé d'une question sans réponse ────────────────────────────────
 *
 * La conversation et l'échange ne sont écrits qu'au retour de `repondre`. Un modèle en
 * panne au premier message ne laisse pas de conversation vide dans la liste du membre.
 */
final readonly class ConsultationParLAssistant
{
    /** Les derniers échanges rendus au modèle pour comprendre une question de suivi. */
    public const int ECHANGES_TRANSMIS = 6;

    /** La borne de tout l'historique transmis, en signes. */
    public const int SIGNES_TRANSMIS = 9000;

    /** Une question rendue au modèle est coupée à cette longueur. */
    private const int QUESTION_TRANSMISE = 1000;

    /** Une réponse rendue au modèle est coupée à cette longueur. */
    private const int REPONSE_TRANSMISE = 1500;

    public function __construct(
        private AgentDeConsultation $agent,
        private MentionsDeFiches $mentions,
        private AnnuaireDesMembresInterface $annuaire,
        private EntityManagerInterface $em,
        private ReglagesDeLAssistant $reglages,
        private AuditService $audit,
    ) {
    }

    /**
     * Pose la question, dans la conversation donnée ou dans une nouvelle, et rend la
     * conversation avec son nouvel échange.
     *
     * @throws AssistantEnErreur si le modèle échoue ou ne rend rien de lisible
     */
    public function poser(?Conversation $conversation, int $userId, string $question): Conversation
    {
        $historique = null === $conversation ? [] : $this->historique($conversation);

        $resultat = $this->agent->repondre(new EntreeDeQuestion(
            $question,
            $historique,
            $this->annuaire->personneDuCompte($userId),
        ));

        $texte = $resultat->arguments['texte'] ?? null;
        $declares = $resultat->arguments['personIds'] ?? [];

        if (!\is_string($texte) || '' === trim($texte)) {
            // `repondre` sans texte : le schéma l'exige, un modèle peut l'omettre quand
            // même. On ne sert pas une bulle vide, on dit que ça n'a pas marché.
            throw new AssistantEnErreur('L’assistant n’a pas rédigé de réponse. Reposez la question.');
        }

        $reponse = $this->mentions->nettoyer($texte, \is_array($declares) ? $declares : []);

        $nouvelle = null === $conversation;
        $conversation ??= new Conversation($userId, $question);
        $conversation->ajouter(new Echange($conversation, $question, $reponse['texte'], $reponse['personIds']));

        $this->em->persist($conversation);
        $this->em->flush();

        $this->consignerLAppel($conversation, $resultat, \count($historique), \count($reponse['personIds']), $nouvelle);

        return $conversation;
    }

    /**
     * Les derniers échanges, bornés, du plus ancien au plus récent.
     *
     * @return list<EchangePasse>
     */
    public function historique(Conversation $conversation): array
    {
        $retenus = [];
        $signes = 0;

        foreach (array_reverse(\array_slice($conversation->getEchanges(), -self::ECHANGES_TRANSMIS)) as $echange) {
            $relue = $this->mentions->nettoyer($echange->getReponse(), $echange->getPersonnesCitees());
            $question = self::couper($echange->getQuestion(), self::QUESTION_TRANSMISE);
            $reponse = self::couper($relue['texte'], self::REPONSE_TRANSMISE);
            $taille = mb_strlen($question) + mb_strlen($reponse);

            if ([] !== $retenus && $signes + $taille > self::SIGNES_TRANSMIS) {
                break;
            }

            $signes += $taille;
            $retenus[] = new EchangePasse($question, $reponse, $relue['personIds']);
        }

        return array_reverse($retenus);
    }

    private static function couper(string $texte, int $longueur): string
    {
        return mb_strlen($texte) <= $longueur ? $texte : rtrim(mb_substr($texte, 0, $longueur - 1)).'…';
    }

    /**
     * Le journal : **des comptes, jamais le contenu** — ni la question, ni la réponse, ni
     * les fiches citées. Même règle que l'analyse (`AnalyseParLAssistant::consignerLAppel()`).
     */
    private function consignerLAppel(Conversation $conversation, ResultatDeLAgent $resultat, int $echangesTransmis, int $personnesCitees, bool $nouvelle): void
    {
        $this->audit->enregistrer(
            AuditAction::AppelModeleDeLangage,
            'Assistant\\Question',
            null === $conversation->getId() ? null : (string) $conversation->getId(),
            $this->reglages->modele(),
            [
                'nouvelle_conversation' => $nouvelle,
                'echanges_transmis' => $echangesTransmis,
                'tours' => $resultat->tours,
                'appels_d_outils' => $resultat->appelsDOutils,
                'personnes_citees' => $personnesCitees,
            ],
        );
    }
}
