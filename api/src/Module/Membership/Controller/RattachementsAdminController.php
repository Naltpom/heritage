<?php

declare(strict_types=1);

namespace App\Module\Membership\Controller;

use App\Module\Core\Exception\NotFoundException;
use App\Module\Core\Security\CercleDeVisibiliteInterface;
use App\Module\Core\Service\BasculeVoirComme;
use App\Module\Membership\Contract\PortraitsDesComptes;
use App\Module\Membership\Entity\Member;
use App\Module\Membership\Repository\MemberRepository;
use App\Module\Membership\Service\StockageDesMessagesVocaux;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * L'onglet « À rattacher » — `GET /api/administration/rattachements` (ADR 0013).
 *
 * Les comptes inscrits **sans fiche**, du plus ancien au plus récent, avec ce que chacun a
 * déclaré : identité, description de sa famille, photo. C'est la matière de la décision de
 * l'administrateur — rattacher à une fiche, en créer une, ou refuser. Les actions vivent
 * ailleurs : l'activation et le refus sur {@see MembresAdminController}, la suggestion et
 * « créer la fiche et rattacher » dans le module `Assistant`.
 *
 * ## Refusée sous la bascule « voir comme un membre »
 *
 * `409 vue_comme_membre_active`, alors que c'est une **lecture**. La règle 2 du § 10.4 ne
 * refuse d'ordinaire que les écritures ; celle-ci est l'exception, et pour une raison
 * mécanique : la description de famille est un texte libre, **qu'aucun cercle ne sait
 * filtrer**. Sous un cercle emprunté, la rendre montrerait au membre simulé des noms de
 * vivants hors de son cercle ; la masquer rendrait un écran faux. On refuse.
 *
 * `ROLE_ADMIN` par `access_control` (`^/api/administration`) : ni voter ni contrôle ici.
 *
 * ## Le message vocal
 *
 * Depuis le 2 octobre 2026, une déclaration peut porter un **message vocal** — à la place
 * du texte, ou en plus. La liste n'en rend que la présence, le type, la taille et la durée
 * annoncée (`messageVocal`) ; le son lui-même sort par
 * `GET /api/administration/rattachements/{memberId}/message-vocal`, **et seulement par
 * là** : même `ROLE_ADMIN`, même refus sous la bascule — une voix se filtre encore moins
 * qu'un texte.
 *
 * ## Sa transcription
 *
 * Depuis le 2 octobre 2026 au soir, le module `Assistant` transcrit ce message par Gemini,
 * après coup, à la demande d'un administrateur (`POST …/{memberId}/transcription`). La
 * liste rend l'état de cette transcription dans `messageVocal.transcription` — `a_faire`,
 * `faite` avec son texte, ou `echec` avec son motif. Depuis le 3 octobre 2026, elle ne
 * part plus d'elle-même : un administrateur clique « Transcrire », peut **corriger** le
 * texte (`PATCH …/{memberId}/transcription`, `corrigeeLe`), puis lance la recherche. Le
 * texte est de la même nature que la description : il ne sort qu'ici, vers un
 * administrateur.
 */
final class RattachementsAdminController extends AbstractController
{
    public function __construct(
        private readonly MemberRepository $membres,
        private readonly PortraitsDesComptes $portraits,
        private readonly CercleDeVisibiliteInterface $cercle,
        private readonly BasculeVoirComme $bascule,
        private readonly StockageDesMessagesVocaux $messagesVocaux,
    ) {
    }

    #[Route('/api/administration/rattachements', name: 'membership_admin_rattachements_lister', methods: ['GET'])]
    public function lister(): JsonResponse
    {
        $this->bascule->refuserSiActive();

        $elements = array_map($this->element(...), $this->membres->aRattacher());

        return new JsonResponse([
            'elements' => $elements,
            'total' => \count($elements),
            'page' => 1,
            'parPage' => \count($elements),
        ]);
    }

    /**
     * Le message vocal d'une déclaration, **en flux**.
     *
     * `BinaryFileResponse` sert les requêtes partielles (`Range` → `206`) sans rien
     * demander : c'est ce dont un `<audio controls>` a besoin pour se déplacer dans
     * l'enregistrement, et Safari refuse de lire un son servi sans elles.
     *
     * `404` pour tout ce qui ne peut pas être servi — compte inconnu, déjà rattaché ou
     * refusé (la purge a effacé le vocal), pas de vocal, fichier absent du volume. Aucun
     * cache (`private, no-store`) : c'est une voix de vivant, que le navigateur de
     * l'administrateur n'a pas à garder.
     */
    #[Route(
        '/api/administration/rattachements/{memberId}/message-vocal',
        name: 'membership_admin_rattachements_message_vocal',
        requirements: ['memberId' => '\d+'],
        methods: ['GET'],
    )]
    public function messageVocal(int $memberId): BinaryFileResponse
    {
        $this->bascule->refuserSiActive();

        $membre = $this->membres->find($memberId);
        $nom = null !== $membre && $membre->estARattacher() ? $membre->getVoiceMessageName() : null;
        $mime = $membre?->getVoiceMessageMime();

        if (null === $nom || null === $mime || !$this->messagesVocaux->existe($nom)) {
            throw new NotFoundException();
        }

        $reponse = new BinaryFileResponse($this->messagesVocaux->chemin($nom));
        $reponse->headers->set('Content-Type', $mime);
        $reponse->headers->set('X-Content-Type-Options', 'nosniff');
        $reponse->headers->set('Cache-Control', 'private, no-store');
        $reponse->setContentDisposition(
            HeaderUtils::DISPOSITION_INLINE,
            // Un nom neutre : ni l'identité ni le courriel n'ont à voyager dans un en-tête.
            'message-vocal.'.pathinfo($nom, \PATHINFO_EXTENSION),
        );

        return $reponse;
    }

    /** @return array<string, mixed> */
    private function element(Member $membre): array
    {
        $invitation = $membre->getInvitation();
        $invitationPersonId = $invitation?->getPersonId();

        // Le pré-lien de l'invitation suit la règle de la porte de sortie n° 8 : l'identifiant
        // et le libellé ensemble, ou ni l'un ni l'autre. L'administrateur a un cercle
        // complet et la bascule est refusée plus haut ; le filtre reste, parce qu'il ne coûte
        // rien et que c'est lui qui tiendra le jour où l'une des deux prémisses changera.
        $preLienVisible = null !== $invitationPersonId && $this->cercle->voitPersonne($invitationPersonId);

        return [
            'memberId' => $membre->getId(),
            'userId' => $membre->getUserId(),
            'email' => $membre->getUserEmail(),
            'inscritLe' => $membre->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'invitationId' => $invitation?->getId(),
            'invitationPersonId' => $preLienVisible ? $invitationPersonId : null,
            'invitationPersonLabel' => $preLienVisible ? $invitation?->getPersonLabel() : null,
            'declaration' => [
                'surname' => $membre->getDeclaredSurname(),
                'givenNames' => $membre->getDeclaredGivenNames(),
                'birthDate' => $membre->getDeclaredBirthDate()?->format('Y-m-d'),
                'description' => $membre->getFamilyDescription(),
            ],
            'messageVocal' => $this->messageVocalDe($membre),
            'photo' => $this->portraits->portraitEnAttente($membre->getUserId()),
            'suggestionCalculeeLe' => $membre->getAttachmentSuggestionAt()?->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * Le message vocal tel que la liste le décrit — jamais le son, qui a sa route.
     *
     * @return array{url: string, type: string, taille: int, duree: int|null, transcription: array{statut: string, texte: string|null, erreur: string|null, le: string|null, corrigeeLe: string|null}}|null
     */
    private function messageVocalDe(Member $membre): ?array
    {
        $id = $membre->getId();
        $mime = $membre->getVoiceMessageMime();
        $taille = $membre->getVoiceMessageSize();

        if (null === $id || null === $membre->getVoiceMessageName() || null === $mime || null === $taille) {
            return null;
        }

        return [
            // Écrite par le serveur, comme les URL de la photo : le front ne la reconstruit pas.
            'url' => $this->generateUrl('membership_admin_rattachements_message_vocal', ['memberId' => $id]),
            'type' => $mime,
            'taille' => $taille,
            'duree' => $membre->getVoiceMessageSeconds(),
            'transcription' => self::transcriptionDe($membre),
        ];
    }

    /**
     * L'état de la transcription, en liste blanche : cinq clés, toujours les mêmes.
     *
     * `a_faire` — jamais tentée (un clic sur « Transcrire » la lancera ; rien ne part à
     * l'ouverture de l'écran depuis le 3 octobre 2026) ; `faite` — le texte est là ;
     * `echec` — la dernière tentative a échoué, et le motif dit pourquoi (« Réessayer »).
     *
     * @return array{statut: string, texte: string|null, erreur: string|null, le: string|null, corrigeeLe: string|null}
     */
    private static function transcriptionDe(Member $membre): array
    {
        $texte = $membre->getVoiceMessageTranscript();
        $erreur = $membre->getVoiceMessageTranscriptError();

        return [
            'statut' => match (true) {
                null !== $texte => 'faite',
                null !== $erreur => 'echec',
                default => 'a_faire',
            },
            'texte' => $texte,
            'erreur' => null === $texte ? $erreur : null,
            'le' => $membre->getVoiceMessageTranscribedAt()?->format(\DateTimeInterface::ATOM),
            // Corrigée à la main par un administrateur (3 octobre 2026) ; `null` : le texte
            // est celui du modèle.
            'corrigeeLe' => $membre->getVoiceMessageTranscriptEditedAt()?->format(\DateTimeInterface::ATOM),
        ];
    }
}
