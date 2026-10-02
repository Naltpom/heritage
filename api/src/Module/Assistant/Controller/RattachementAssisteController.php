<?php

declare(strict_types=1);

namespace App\Module\Assistant\Controller;

use App\Module\Assistant\Contract\AccesAuModele;
use App\Module\Assistant\Exception\AssistantIndisponible;
use App\Module\Assistant\Service\CreationEtRattachement;
use App\Module\Assistant\Service\QuotasDeLAssistant;
use App\Module\Assistant\Service\SuggestionsDeRattachement;
use App\Module\Assistant\Service\TranscriptionDesMessagesVocaux;
use App\Module\Core\Contract\QuotasDUsageInterface;
use App\Module\Core\Entity\User;
use App\Module\Core\Exception\ForbiddenException;
use App\Module\Core\Exception\NotFoundException;
use App\Module\Core\Exception\TooManyRequestsException;
use App\Module\Core\Exception\ValidationException;
use App\Module\Core\Service\BasculeVoirComme;
use App\Module\Membership\Contract\ComptesARattacher;
use App\Module\Membership\Contract\DeclarationDuCompte;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Le rattachement assisté d'un compte « à rattacher » — cinq routes d'administration.
 *
 * | Route | Ce qu'elle fait |
 * |---|---|
 * | `GET   …/{memberId}/suggestion` | la suggestion gardée (rafraîchie, `perimee` à jour) ; `204` s'il n'y en a pas — **jamais un calcul** |
 * | `POST  …/{memberId}/suggestion` | « Lancer / Relancer la recherche » : calcule, quoi qu'il y ait en cache |
 * | `POST  …/{memberId}/creer-et-rattacher` | dépose et valide la création de la fiche, rattache et active le compte |
 * | `POST  …/{memberId}/transcription` | transcrit le message vocal (ou recommence), range le résultat sur le compte |
 * | `PATCH …/{memberId}/transcription` | range la transcription **corrigée à la main** (ou écrite) par l'administrateur |
 *
 * ── Rien ne part tout seul (addendum du 3 octobre 2026) ──────────────────────────
 *
 * Jusqu'au 2 octobre, l'écran enchaînait à l'ouverture, carte par carte, la
 * transcription puis le calcul de la suggestion (le `GET` calculait ce qu'il ne trouvait
 * pas en cache). Le propriétaire a demandé l'inverse : **relire, corriger la
 * transcription, puis lancer la recherche soi-même**. Désormais :
 *
 * - le `GET` ne fait que lire — ni modèle, ni quota, ni clé exigée ; il rend `204` quand
 *   rien n'a été calculé, et l'écran affiche « Lancer la recherche » ;
 * - seuls les deux `POST` appellent le modèle, et chacun derrière un clic ;
 * - le `PATCH` corrige le texte : aucun appel, aucun quota. La suggestion gardée n'est
 *   pas effacée, elle devient `perimee` ({@see SuggestionsDeRattachement}) — l'écran dit
 *   « La transcription a changé : relancez la recherche ».
 *
 * Chaque appel garde sa borne de temps — une minute pour la transcription,
 * cinquante-cinq secondes pour la recherche.
 *
 * La transcription répond **`200` même quand elle échoue** : son état (`faite` ou
 * `echec`, avec le motif) est la ressource, et il est rangé sur le compte. Seuls les refus
 * qui ne sont pas des pannes du modèle gardent leur statut : `404`, `409`, `429`, `503`.
 *
 * Le préfixe `/api/administration/rattachements` est partagé avec la liste des comptes
 * (`GET /api/administration/rattachements`, module `Membership`) : c'est le **quatrième**
 * segment qui désigne le propriétaire, et aucune route de ce fichier ne recouvre la liste.
 *
 * ── Les contrôles, dans l'ordre, et pourquoi cet ordre ───────────────────────────
 *
 * 1. **`409 vue_comme_membre_active`** — sous la bascule, l'administrateur regarde avec
 *    les yeux d'un membre ; la description d'une inscription ne se filtre pas par un
 *    cercle, et un calcul lancé d'ici serait une écriture sous identité empruntée.
 * 2. **`403`** si le compte n'est pas administrateur — `access_control` ferme déjà
 *    `^/api/administration`, mais un contrôle qui suppose la configuration juste n'en est
 *    pas un (même remarque qu'`AssistantController`).
 * 3. **`404`** — le compte n'est pas (ou plus) « à rattacher » et en attente.
 * 4. **`503`** — pas de clé (les deux `POST` seulement). Le `GET` et le `PATCH` n'en
 *    ont pas besoin : ils ne coûtent rien.
 * 5. **`429`** — le quota global, consommé juste avant l'appel qui coûte.
 * 6. **La session est relâchée** avant l'appel au modèle : sans cela, chaque requête du
 *    même navigateur attendrait le verrou de session jusqu'à 90 secondes, et le site se
 *    figerait (voir `AssistantController::relacherLaSession()`).
 */
#[Route('/api/administration/rattachements')]
final class RattachementAssisteController extends AbstractController
{
    public function __construct(
        private readonly BasculeVoirComme $bascule,
        private readonly Security $securite,
        private readonly ComptesARattacher $comptes,
        private readonly SuggestionsDeRattachement $suggestions,
        private readonly AccesAuModele $acces,
        /**
         * 40 analyses par 24 heures glissantes par défaut, **tous administrateurs
         * confondus** — limite réglable globalement (`PATCH /api/administration/quotas`).
         * Séparé du quota des membres (les analyses) : une vague d'inscriptions ne doit
         * pas priver la famille de ses analyses du jour, ni l'inverse.
         */
        private readonly QuotasDUsageInterface $quotas,
        private readonly QuotasDeLAssistant $declares,
        private readonly TranscriptionDesMessagesVocaux $transcriptions,
    ) {
    }

    /**
     * Transcrit le message vocal — ou recommence, après un échec (« Réessayer »).
     *
     * Les mêmes contrôles que la suggestion, dans le même ordre ; le quota consommé est le
     * même — le quota **global** des rattachements assistés : une transcription est un
     * appel payant au même fournisseur, pour la même décision.
     */
    #[Route('/{memberId}/transcription', name: 'assistant_rattachement_transcrire', requirements: ['memberId' => '\d{1,9}'], methods: ['POST'])]
    public function transcrire(int $memberId, Request $requete): JsonResponse
    {
        $declaration = $this->declaration($memberId);

        if (!$declaration->aUnMessageVocal) {
            throw new NotFoundException('Ce compte n’a pas de message vocal à transcrire.');
        }

        $this->avantLAppel($requete, 'La transcription automatique n’est pas configurée sur cette installation. Écoutez le message.');

        return $this->charge($this->transcriptions->transcrire($declaration));
    }

    /**
     * Corrige à la main la transcription — ou l'écrit. Corps : `{"texte": "…"}`, non vide,
     * 16 000 caractères au plus (`422` sinon).
     *
     * Les contrôles 1 à 3, puis `404` sans message vocal ; **ni clé, ni quota** : rien ne
     * part chez le fournisseur. La réponse a la forme de celle du `POST`, `corrigeeLe`
     * renseignée.
     */
    #[Route('/{memberId}/transcription', name: 'assistant_rattachement_corriger_transcription', requirements: ['memberId' => '\d{1,9}'], methods: ['PATCH'])]
    public function corrigerLaTranscription(int $memberId, Request $requete): JsonResponse
    {
        $declaration = $this->declaration($memberId);

        if (!$declaration->aUnMessageVocal) {
            throw new NotFoundException('Ce compte n’a pas de message vocal à transcrire.');
        }

        return $this->charge($this->transcriptions->corriger($declaration, $this->corps($requete)['texte'] ?? null));
    }

    /**
     * La suggestion gardée — **lecture seule** (addendum du 3 octobre 2026). `204` quand
     * aucune recherche n'a encore été lancée pour ce compte : c'est au clic de
     * l'administrateur de la lancer (`POST`), pas à l'ouverture de l'écran.
     */
    #[Route('/{memberId}/suggestion', name: 'assistant_rattachement_suggestion', requirements: ['memberId' => '\d{1,9}'], methods: ['GET'])]
    public function lire(int $memberId): Response
    {
        $gardee = $this->suggestions->enCache($this->declaration($memberId));

        if (null === $gardee) {
            $vide = new Response(null, Response::HTTP_NO_CONTENT);
            $vide->headers->set('Cache-Control', 'no-store');

            return $vide;
        }

        return $this->charge($gardee);
    }

    #[Route('/{memberId}/suggestion', name: 'assistant_rattachement_relancer', requirements: ['memberId' => '\d{1,9}'], methods: ['POST'])]
    public function relancer(int $memberId, Request $requete): JsonResponse
    {
        return $this->charge($this->calculer($this->declaration($memberId), $requete));
    }

    #[Route('/{memberId}/creer-et-rattacher', name: 'assistant_rattachement_creer', requirements: ['memberId' => '\d{1,9}'], methods: ['POST'])]
    public function creerEtRattacher(int $memberId, Request $requete, CreationEtRattachement $creation): JsonResponse
    {
        $this->bascule->refuserSiActive();
        $administrateur = $this->administrateur();

        $corps = $this->corps($requete);

        return $this->charge($creation->executer(
            $memberId,
            $this->parents($corps['parents'] ?? null),
            $this->message($corps['message'] ?? null),
            $administrateur,
        ), 201);
    }

    /**
     * Les contrôles 1 à 3, communs à toutes les routes de ce fichier sauf la création.
     */
    private function declaration(int $memberId): DeclarationDuCompte
    {
        $this->bascule->refuserSiActive();
        $this->administrateur();

        return $this->comptes->declaration($memberId)
            ?? throw new NotFoundException('Ce compte n’attend pas de rattachement.');
    }

    /**
     * Les contrôles 4 à 6, puis le calcul.
     *
     * @return array<string, mixed>
     */
    private function calculer(DeclarationDuCompte $declaration, Request $requete): array
    {
        $this->avantLAppel($requete, 'L’analyse automatique n’est pas configurée sur cette installation. Rattachez ce compte à la main.');

        return $this->suggestions->calculer($declaration);
    }

    /**
     * Les contrôles 4 à 6 : une clé, le quota global, la session relâchée.
     */
    private function avantLAppel(Request $requete, string $sansCle): void
    {
        if (!$this->acces->configure()) {
            throw new AssistantIndisponible($sansCle);
        }

        // Une seule clé pour tous les administrateurs : le quota borne une dépense de
        // l'installation, pas l'activité d'une personne.
        $limite = $this->quotas->consommer($this->declares->rattachements(), null);

        if (!$limite->isAccepted()) {
            throw new TooManyRequestsException(max(1, $limite->getRetryAfter()->getTimestamp() - time()), 0 === $limite->getLimit() ? 'L’analyse automatique des rattachements est fermée sur cette installation. Rattachez ce compte à la main.' : \sprintf('Les %d analyses de rattachement de la journée sont atteintes. Rattachez ce compte à la main, ou réessayez plus tard.', $limite->getLimit()));
        }

        if ($requete->hasSession() && $requete->getSession()->isStarted()) {
            $requete->getSession()->save();
        }
    }

    private function administrateur(): User
    {
        $utilisateur = $this->securite->getUser();

        if (!$utilisateur instanceof User || !$this->securite->isGranted('ROLE_ADMIN')) {
            throw new ForbiddenException();
        }

        return $utilisateur;
    }

    /** @return array<string, mixed> */
    private function corps(Request $requete): array
    {
        $brut = $requete->getContent();

        if ('' === trim($brut)) {
            return [];
        }

        try {
            $corps = json_decode($brut, true, 8, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ValidationException(['corps' => 'Le corps de la requête n’est pas du JSON valide.']);
        }

        if (!\is_array($corps)) {
            throw new ValidationException(['corps' => 'Le corps de la requête est un objet.']);
        }

        /* @var array<string, mixed> $corps */
        return $corps;
    }

    /**
     * 0 à 2 identifiants de fiches, distincts. Leur existence est vérifiée par le
     * service, qui lit l'arbre.
     *
     * @return list<int>
     */
    private function parents(mixed $brut): array
    {
        if (null === $brut) {
            return [];
        }

        if (!\is_array($brut) || !array_is_list($brut)) {
            throw new ValidationException(['parents' => 'Les parents forment une liste d’identifiants.']);
        }

        if (\count($brut) > 2) {
            throw new ValidationException(['parents' => 'Deux parents au plus.']);
        }

        $parents = [];
        foreach ($brut as $parent) {
            if (!\is_int($parent) || $parent <= 0) {
                throw new ValidationException(['parents' => 'Chaque parent est l’identifiant d’une fiche.']);
            }

            if (\in_array($parent, $parents, true)) {
                throw new ValidationException(['parents' => 'Les deux parents sont deux personnes distinctes.']);
            }

            $parents[] = $parent;
        }

        return $parents;
    }

    private function message(mixed $brut): ?string
    {
        if (null !== $brut && !\is_string($brut)) {
            throw new ValidationException(['message' => 'Le message est une chaîne.']);
        }

        if (null !== $brut && mb_strlen($brut) > 2000) {
            throw new ValidationException(['message' => 'Le message dépasse 2 000 caractères.']);
        }

        return $brut;
    }

    /** @param array<string, mixed> $charge */
    private function charge(array $charge, int $statut = 200): JsonResponse
    {
        $reponse = new JsonResponse($charge, $statut);
        // La charge cite une description de famille et des personnes vivantes.
        $reponse->headers->set('Cache-Control', 'no-store');

        return $reponse;
    }
}
