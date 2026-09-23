<?php

declare(strict_types=1);

namespace App\Module\Question\Controller;

use App\Module\Core\Entity\User;
use App\Module\Core\Service\BasculeVoirComme;
use App\Module\Question\Entity\Question;
use App\Module\Question\Exception\QuestionIntrouvable;
use App\Module\Question\Exception\ReponseInvalide;
use App\Module\Question\Repository\QuestionRepository;
use App\Module\Question\Service\ReponseAUneQuestion;
use App\Module\Question\Service\SerialiseurDeQuestion;
use App\Module\Question\Service\ServiceDesQuestions;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Les quatre routes de la page « Questions ».
 *
 * | Route | Écrit | `refuserSiActive()` |
 * |---|---|---|
 * | `GET /api/questions` | `qst_question` (les lignes manquantes) | **non** |
 * | `POST /api/questions/{id}/je-ne-sais-pas` | l'état | oui |
 * | `POST /api/questions/{id}/plus-tard` | l'état + la relance | oui |
 * | `POST /api/questions/{id}/reponse` | 1 ou 2 `prp_change_request` | oui |
 *
 * ── Le `GET` écrit, et il n'a pourtant pas le garde-fou de la bascule ───────────
 *
 * Les deux points surprennent et se répondent l'un l'autre.
 *
 * **Le `GET` écrit** parce que la détection ne peut pas tourner en tâche de fond :
 * `CercleDeVisibiliteInterface` résout le lecteur depuis la session, et il n'existe pas —
 * et ne doit pas exister — d'API « le cercle du compte n° 12 » (ADR 0008 § 3). L'écriture
 * est **idempotente**, l'index unique s'en charge, et c'est la seule raison pour laquelle
 * elle est acceptable.
 *
 * **La bascule « voir comme un membre » ne la refuse donc pas**, alors qu'elle refuse
 * toute écriture ailleurs. Le motif est celui de `GET /api/demandes/{id}/refaire` chez
 * `Proposal` : y poser le garde-fou interdirait à l'administrateur de **lire** la page
 * d'un membre — c'est-à-dire exactement ce que la bascule existe pour permettre. Ce que
 * cette lecture crée, ce sont les questions **du membre emprunté**, pas celles de
 * l'administrateur, et elles auraient été créées à sa prochaine visite.
 *
 * Les trois écritures réelles, elles, portent le garde-fou : sans lui, une réponse
 * déposée sous la bascule porterait l'administrateur comme auteur pendant que l'écran
 * montre quelqu'un d'autre.
 *
 * ── Aucune vérification de propriété dans ce fichier ───────────────────────────
 *
 * {@see QuestionRepository::pourCompte()} porte le compte **dans sa clause `WHERE`**
 * plutôt qu'en contrôle après coup : on ne peut pas oublier de le faire. La question
 * d'autrui rend donc `404` et jamais `403` — un `403` apprendrait que la ligne existe, et
 * une question **nomme une personne** (§ 15.3 du contrat d'API).
 *
 * C'est aussi pourquoi il n'y a pas de `Voter` ici : un voter est le bon outil quand
 * l'objet est chargé d'abord et jugé ensuite. Ici l'objet ne se charge pas du tout.
 */
#[Route('/api/questions')]
final class QuestionController extends AbstractController
{
    public function __construct(
        private readonly Security $securite,
        private readonly QuestionRepository $questions,
        private readonly SerialiseurDeQuestion $serialiseur,
        private readonly BasculeVoirComme $bascule,
    ) {
    }

    /**
     * La page : rejoue la détection, crée ce qui manque, sert ce qui est servable.
     *
     * Peut rendre `409 detection_concurrente` — deux ouvertures simultanées du même
     * compte. Rien n'est cassé, rien n'est perdu : `details.rejouable` vaut `true`.
     */
    #[Route('', name: 'question_page', methods: ['GET'])]
    public function page(ServiceDesQuestions $service): JsonResponse
    {
        $questions = $service->pour($this->compteCourant(), new \DateTimeImmutable());

        return $this->charge($this->serialiseur->page($questions));
    }

    /** « Je ne sais pas » : la question se ferme, **et rien n'entre dans l'arbre**. */
    #[Route(
        '/{id}/je-ne-sais-pas',
        name: 'question_je_ne_sais_pas',
        requirements: ['id' => '\d+'],
        methods: ['POST'],
    )]
    public function jeNeSaisPas(int $id, ReponseAUneQuestion $reponses): JsonResponse
    {
        $this->bascule->refuserSiActive();

        return $this->charge($this->serialiseur->question(
            $reponses->jeNeSaisPas($this->trouver($id)),
        ));
    }

    /** « Je vais me renseigner » : elle revient, et de plus en plus tard. */
    #[Route(
        '/{id}/plus-tard',
        name: 'question_plus_tard',
        requirements: ['id' => '\d+'],
        methods: ['POST'],
    )]
    public function plusTard(int $id, ReponseAUneQuestion $reponses): JsonResponse
    {
        $this->bascule->refuserSiActive();

        return $this->charge($this->serialiseur->question(
            $reponses->plusTard($this->trouver($id), new \DateTimeImmutable()),
        ));
    }

    /**
     * Une réponse : `texte`, `mediaId`, ou les deux.
     *
     * **Le média est déjà téléversé** par `POST /api/medias` et en attente : aucun octet
     * ne passe par cette route. Le téléversement, la purge EXIF, la vignette et
     * l'empreinte appartiennent à `Media` et y restent — et faire transiter le fichier
     * ici aurait dupliqué les quatre.
     */
    #[Route(
        '/{id}/reponse',
        name: 'question_repondre',
        requirements: ['id' => '\d+'],
        methods: ['POST'],
    )]
    public function repondre(int $id, Request $requete, ReponseAUneQuestion $reponses): JsonResponse
    {
        $this->bascule->refuserSiActive();

        $corps = $this->corps($requete);

        return $this->charge($this->serialiseur->question($reponses->repondre(
            $this->trouver($id),
            $this->texte($corps),
            $this->mediaId($corps),
        )), 201);
    }

    private function trouver(int $id): Question
    {
        return $this->questions->pourCompte($id, $this->compteCourant())
            ?? throw QuestionIntrouvable::pourLeCompte();
    }

    /**
     * L'identité **effective** : sous la bascule, c'est le membre emprunté.
     *
     * On lit le fait chez `Core` plutôt que de deviner une clé de session — c'est la
     * leçon que `Proposal\Service\FabriqueDeLecteur` a payée : *« ce fichier a lu pendant
     * un temps une clé que personne n'écrit ; le garde-fou compilait, il était testé, et
     * il ne s'est jamais déclenché »*.
     */
    private function compteCourant(): int
    {
        $emprunte = $this->bascule->userIdEmprunte();

        if (null !== $emprunte) {
            return $emprunte;
        }

        $utilisateur = $this->securite->getUser();

        if (!$utilisateur instanceof User || null === $utilisateur->getId()) {
            // `access_control` place déjà tout `/api` derrière `ROLE_USER` ; on ne
            // suppose pas pour autant que la configuration est juste.
            throw $this->createAccessDeniedException();
        }

        return $utilisateur->getId();
    }

    /** @param array<string, mixed> $corps */
    private function texte(array $corps): ?string
    {
        $brut = $corps['texte'] ?? null;

        if (null === $brut) {
            return null;
        }

        if (!\is_string($brut)) {
            throw ReponseInvalide::pourChamp('texte', 'Cette valeur doit être du texte.');
        }

        return $brut;
    }

    /** @param array<string, mixed> $corps */
    private function mediaId(array $corps): ?int
    {
        $brut = $corps['mediaId'] ?? null;

        if (null === $brut) {
            return null;
        }

        if (!\is_int($brut)) {
            throw ReponseInvalide::pourChamp('mediaId', 'Identifiant de média attendu.');
        }

        return $brut;
    }

    /** @return array<string, mixed> */
    private function corps(Request $requete): array
    {
        $brut = $requete->getContent();

        try {
            $decode = '' === $brut ? [] : json_decode($brut, true, 32, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw ReponseInvalide::pourChamp('corps', 'Le corps de la requête n\'est pas du JSON valide.');
        }

        if (!\is_array($decode)) {
            throw ReponseInvalide::pourChamp('corps', 'Le corps de la requête est un objet JSON.');
        }

        return $decode;
    }

    /** @param array<string, mixed> $charge */
    private function charge(array $charge, int $statut = 200): JsonResponse
    {
        $reponse = new JsonResponse($charge, $statut);
        // Une page de questions nomme des personnes et dépend du compte : elle ne se met
        // en cache nulle part, pas même dans un mandataire partagé.
        $reponse->headers->set('Cache-Control', 'no-store');

        return $reponse;
    }
}
