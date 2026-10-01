<?php

declare(strict_types=1);

namespace App\Module\Assistant\Controller;

use App\Module\Assistant\Contract\AccesAuModele;
use App\Module\Assistant\Entity\Conversation;
use App\Module\Assistant\Exception\AssistantIndisponible;
use App\Module\Assistant\Repository\ConversationRepository;
use App\Module\Assistant\Service\ConsultationParLAssistant;
use App\Module\Assistant\Service\SerialiseurDeConversation;
use App\Module\Core\Entity\User;
use App\Module\Core\Enum\Regime;
use App\Module\Core\Exception\ForbiddenException;
use App\Module\Core\Exception\NotFoundException;
use App\Module\Core\Exception\TooManyRequestsException;
use App\Module\Core\Exception\ValidationException;
use App\Module\Core\Security\CercleDeVisibiliteInterface;
use App\Module\Core\Service\BasculeVoirComme;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * « Poser une question » à l'assistant — des conversations suivies, **en lecture seule
 * sur l'arbre**.
 *
 * | Route | Ce qu'elle fait |
 * |---|---|
 * | `GET /api/assistant/conversations` | mes conversations, la plus récente d'abord |
 * | `POST /api/assistant/conversations` | une nouvelle conversation, **avec sa première question** |
 * | `GET /api/assistant/conversations/{id}` | une conversation et tous ses échanges |
 * | `POST /api/assistant/conversations/{id}/questions` | une question de suivi |
 * | `DELETE /api/assistant/conversations/{id}` | supprimer une conversation |
 *
 * **Une conversation ne se crée pas vide.** `POST /conversations` porte la première
 * question et ne crée la conversation qu'au retour de la réponse : un modèle en panne ne
 * laisse pas une ligne sans échange dans la liste.
 *
 * **Une conversation n'existe que pour son auteur.** Celle d'un autre compte rend `404`,
 * pas `403` (§ 15.3) : on ne confirme pas qu'elle existe. Pas d'exception pour
 * l'administrateur — il n'a pas plus de raison de lire les questions d'un membre que ses
 * courriels.
 *
 * ── Les contrôles, dans l'ordre, et pourquoi cet ordre ───────────────────────────
 *
 * Le même que l'analyse (`AssistantController`), pour les mêmes raisons :
 *
 * 1. **`409 vue_comme_membre_active`** sur les trois écritures — la bascule « voir comme
 *    un membre » n'écrit rien, et une question écrit une conversation et consomme le
 *    quota. Les lectures restent ouvertes. **Sous une incarnation (ADR 0010), on écrit**,
 *    comme partout : la conversation appartient au compte incarné, et le journal porte
 *    l'administrateur à côté de chaque ligne.
 * 2. **`403 regime_en_attente`** — un compte sans rattachement n'a pas de cercle, donc pas
 *    d'arbre sur lequel interroger qui que ce soit de vivant ; et l'on ne paie pas un
 *    appel au modèle pour une inscription que personne n'a encore regardée.
 * 3. **`404`** — la conversation de suivi, avant tout appel.
 * 4. **`503 assistant_indisponible`** — pas de clé. Avant le quota.
 * 5. **`422`** — la question. Avant le quota.
 * 6. **`429`** — 60 questions par 24 heures glissantes et par compte, administrateur
 *    compris, consommées juste avant l'appel qui coûte.
 */
#[Route('/api/assistant/conversations')]
final class ConversationController extends AbstractController
{
    /** Une question, pas un récit : le récit se confie à « Proposer ». */
    public const int QUESTION_MAXIMALE = 1000;

    /**
     * Au-delà, on ouvre une nouvelle conversation. Le modèle n'en relit de toute façon que
     * les derniers échanges ; une conversation sans fin ne sert qu'à grossir une page.
     */
    public const int ECHANGES_MAXIMAUX = 50;

    /** La liste « mes conversations » en sert au plus autant. */
    private const int CONVERSATIONS_LISTEES = 50;

    public function __construct(
        private readonly BasculeVoirComme $bascule,
        private readonly CercleDeVisibiliteInterface $cercle,
        private readonly Security $securite,
        private readonly ConversationRepository $conversations,
        private readonly SerialiseurDeConversation $serialiseur,
        /** 60 questions par 24 heures glissantes et par compte (`config/services/assistant.yaml`). */
        #[Autowire(service: 'assistant.limiteur_questions')]
        private readonly RateLimiterFactoryInterface $limiteur,
    ) {
    }

    #[Route('', name: 'assistant_conversations', methods: ['GET'])]
    public function lister(): JsonResponse
    {
        $userId = $this->lecteur();
        $liste = $this->conversations->duCompte($userId, self::CONVERSATIONS_LISTEES);
        $nombres = $this->conversations->nombresDEchanges(array_values(array_filter(
            array_map(static fn (Conversation $c): ?int => $c->getId(), $liste),
            \is_int(...),
        )));

        return $this->charge([
            'elements' => array_map(
                fn (Conversation $c): array => $this->serialiseur->resume($c, $nombres[(int) $c->getId()] ?? 0),
                $liste,
            ),
        ]);
    }

    #[Route('', name: 'assistant_conversation_creer', methods: ['POST'])]
    public function creer(Request $requete, AccesAuModele $acces, ConsultationParLAssistant $consultation): JsonResponse
    {
        $userId = $this->ecrivain();

        return $this->poser(null, $userId, $requete, $acces, $consultation);
    }

    #[Route('/{id}', name: 'assistant_conversation', requirements: ['id' => '\d{1,9}'], methods: ['GET'])]
    public function lire(int $id): JsonResponse
    {
        return $this->charge(['conversation' => $this->serialiseur->detail($this->sienne($this->lecteur(), $id))]);
    }

    #[Route('/{id}/questions', name: 'assistant_conversation_question', requirements: ['id' => '\d{1,9}'], methods: ['POST'])]
    public function questionner(int $id, Request $requete, AccesAuModele $acces, ConsultationParLAssistant $consultation): JsonResponse
    {
        $userId = $this->ecrivain();
        $conversation = $this->sienne($userId, $id);

        if (\count($conversation->getEchanges()) >= self::ECHANGES_MAXIMAUX) {
            throw new ValidationException(['question' => \sprintf('Cette conversation compte déjà %d questions : ouvrez-en une nouvelle.', self::ECHANGES_MAXIMAUX)]);
        }

        return $this->poser($conversation, $userId, $requete, $acces, $consultation);
    }

    #[Route('/{id}', name: 'assistant_conversation_supprimer', requirements: ['id' => '\d{1,9}'], methods: ['DELETE'])]
    public function supprimer(int $id, EntityManagerInterface $em): Response
    {
        $conversation = $this->sienne($this->ecrivain(), $id);

        // Les échanges partent avec elle (`ON DELETE CASCADE` et `orphanRemoval`). Le
        // journal n'en garde que le type et l'identifiant : rien du contenu.
        $em->remove($conversation);
        $em->flush();

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    private function poser(?Conversation $conversation, int $userId, Request $requete, AccesAuModele $acces, ConsultationParLAssistant $consultation): JsonResponse
    {
        if (!$acces->configure()) {
            throw new AssistantIndisponible('L’assistant n’est pas disponible sur cette installation : aucune question ne peut lui être posée pour l’instant.');
        }

        $question = $this->question($requete);
        $limite = $this->limiteur->create((string) $userId)->consume();

        if (!$limite->isAccepted()) {
            throw new TooManyRequestsException(
                max(1, $limite->getRetryAfter()->getTimestamp() - time()),
                'Vous avez posé les 60 questions de la journée. L’assistant vous répondra de nouveau demain.',
            );
        }

        $this->relacherLaSession($requete);

        $conversation = $consultation->poser($conversation, $userId, $question);

        return $this->charge([
            'conversation' => $this->serialiseur->detail($conversation),
            'restant' => $limite->getRemainingTokens(),
        ], Response::HTTP_CREATED);
    }

    /**
     * **Libère le fichier de session avant l'appel au modèle** — jusqu'à 90 secondes.
     * Même motif, même geste que `AssistantController::relacherLaSession()` : sans lui,
     * toute autre requête du même navigateur attend le verrou de session, et quelques
     * requêtes de la SPA suffisent à figer le site pour tout le monde.
     *
     * Le cercle du lecteur est déjà résolu (contrôle 2) et mémorisé pour la requête : les
     * outils du modèle n'ont plus à relire la session.
     */
    private function relacherLaSession(Request $requete): void
    {
        if ($requete->hasSession() && $requete->getSession()->isStarted()) {
            $requete->getSession()->save();
        }
    }

    /** Le compte qui lit. Les lectures sont ouvertes sous la bascule. */
    private function lecteur(): int
    {
        $utilisateur = $this->securite->getUser();
        $userId = $utilisateur instanceof User ? $utilisateur->getId() : null;

        if (null === $userId) {
            // Hors d'atteinte en pratique — `access_control` ferme `^/api`.
            throw new ForbiddenException();
        }

        return $userId;
    }

    /** Le compte qui écrit : pas sous la bascule, pas en attente de rattachement. */
    private function ecrivain(): int
    {
        $this->bascule->refuserSiActive();
        $userId = $this->lecteur();

        if (Regime::Aucun === $this->cercle->regime()) {
            throw ForbiddenException::regimeEnAttente();
        }

        return $userId;
    }

    private function sienne(int $userId, int $id): Conversation
    {
        return $this->conversations->duCompteParId($userId, $id) ?? throw new NotFoundException();
    }

    private function question(Request $requete): string
    {
        try {
            $corps = json_decode($requete->getContent(), true, 8, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ValidationException(['corps' => 'Le corps de la requête n’est pas du JSON valide.']);
        }

        $question = \is_array($corps) ? ($corps['question'] ?? null) : null;

        if (!\is_string($question) || '' === trim($question)) {
            throw new ValidationException(['question' => 'Écrivez votre question.']);
        }

        $question = trim($question);

        if (mb_strlen($question) > self::QUESTION_MAXIMALE) {
            throw new ValidationException(['question' => \sprintf('La question dépasse %d caractères.', self::QUESTION_MAXIMALE)]);
        }

        return $question;
    }

    /** @param array<string, mixed> $charge */
    private function charge(array $charge, int $statut = Response::HTTP_OK): JsonResponse
    {
        $reponse = new JsonResponse($charge, $statut);
        // Une conversation cite des personnes et dépend du cercle du lecteur : aucun cache
        // intermédiaire ne la garde, et aucun ne la sert à un autre.
        $reponse->headers->set('Cache-Control', 'private, no-store');
        $reponse->headers->set('Vary', 'Cookie');

        return $reponse;
    }
}
