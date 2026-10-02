<?php

declare(strict_types=1);

namespace App\Module\Assistant\Service;

use App\Module\Core\Enum\AuditAction;
use App\Module\Core\Exception\NotFoundException;
use App\Module\Core\Service\AuditService;
use App\Module\Membership\Contract\ComptesARattacher;
use App\Module\Membership\Contract\DeclarationDuCompte;
use App\Module\Membership\Contract\MessageVocalDuCompte;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TimeoutExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

/**
 * ═══════════════════════════════════════════════════════════════════════════════
 *  LA TRANSCRIPTION DU MESSAGE VOCAL : ce que la personne a dit, écrit mot pour mot.
 * ═══════════════════════════════════════════════════════════════════════════════.
 *
 * Le candidat qui ne s'est pas trouvé dans l'arbre peut **dire** sa famille au lieu de
 * l'écrire (ADR 0013, addendum du 2 octobre 2026). Jusqu'ici, seul l'administrateur
 * l'écoutait : l'analyse de rattachement ne savait que « un message vocal existe ». Le
 * propriétaire a décidé que l'IA s'en serve — le son est **transcrit**, et la
 * transcription nourrit {@see AgentDeRattachement} comme la description écrite.
 *
 * ── Un seul appel, même transport, même clé ───────────────────────────────────
 *
 * Gemini, par {@see TransportDuModele} — le fournisseur de l'analyse, pas un tiers de
 * plus. Le son part **en ligne** (`inlineData`, base64), comme les enregistrements que
 * « Proposer avec l'assistant » envoie déjà : 10 Mio au plus, soit 13,4 Mio une fois
 * encodés, sous la limite de 20 Mo d'une requête. Pas de File API : elle garderait le
 * fichier 48 heures chez le fournisseur, et une voix de vivant n'a pas à y séjourner.
 *
 * ── Pas de conversion ─────────────────────────────────────────────────────────
 *
 * La documentation de Gemini (« Audio understanding », relue le 2 octobre 2026) admet
 * `audio/webm`, `audio/ogg`, `audio/opus`, `audio/aac`, `audio/m4a`, `audio/mp3`,
 * `audio/wav`… : les trois formats de `MediaRecorder` passent tels quels. Le seul
 * ajustement est le **nom** du type MP4 de Safari — un conteneur MPEG-4 qui ne porte que
 * de l'AAC —, annoncé `audio/m4a`, le nom que la liste du fournisseur lui donne. Pas de
 * `ffmpeg` dans l'image de l'API, donc : il aurait coûté une centaine de Mo pour une
 * conversion que le fournisseur ne demande pas.
 *
 * ── Ce qui est demandé au modèle ──────────────────────────────────────────────
 *
 * Du **verbatim**, en français : pas de résumé, pas de reformulation, les noms propres
 * et les dates tels qu'entendus — une orthographe douteuse est marquée `[?]` plutôt que
 * « corrigée » vers un nom de l'arbre que le modèle ne connaît d'ailleurs pas. Le
 * rapprochement avec l'arbre est le travail de l'analyse, pas de la transcription.
 * Température nulle : on veut une copie, pas de la variété.
 *
 * ── L'échec ne bloque rien ─────────────────────────────────────────────────────
 *
 * La transcription a lieu **après** l'inscription, à la demande de l'écran « À
 * rattacher » — comme l'analyse, paresseusement. Une panne (délai, quota du fournisseur,
 * refus, réponse vide) est **rangée sur le compte** avec son motif : la carte affiche
 * « échec — Réessayer », l'audio reste écoutable, et l'analyse se fait sans la
 * transcription. Rien n'est levé vers l'appelant pour une panne du modèle : l'état de la
 * transcription **est** la réponse.
 *
 * ── Ce qui n'est JAMAIS journalisé ─────────────────────────────────────────────
 *
 * Ni le son, ni le texte, ni la clé. Le journal d'audit (`AppelModeleDeLangage`, sujet
 * `Assistant\Transcription`) garde le statut, la durée annoncée, la taille et le nombre de
 * caractères rendus ; le journal applicatif, la classe de l'exception et son message de
 * transport.
 */
final readonly class TranscriptionDesMessagesVocaux
{
    /**
     * Le plafond du texte gardé. Cinq minutes de parole font 700 à 900 mots, soit 4 000 à
     * 6 000 caractères : le triple suffit, et borne un modèle qui bégaierait en boucle.
     */
    public const int TEXTE_MAX = 16000;

    /** Le nom que la liste de Gemini donne à chacun des trois types de `MediaRecorder`. */
    private const array TYPES_POUR_GEMINI = [
        'audio/webm' => 'audio/webm',
        'audio/ogg' => 'audio/ogg',
        'audio/mp4' => 'audio/m4a',
    ];

    public function __construct(
        private TransportDuModele $transport,
        private ComptesARattacher $comptes,
        private ReglagesDeLAssistant $reglages,
        private AuditService $audit,
        private LoggerInterface $journal,
    ) {
    }

    /**
     * Transcrit le message vocal du compte, **range le résultat sur le compte** — texte ou
     * motif d'échec —, et rend l'état tel que la carte le lit.
     *
     * @return array{memberId: int, statut: string, texte: string|null, erreur: string|null, le: string}
     *
     * @throws NotFoundException le compte n'attend plus de rattachement, ou n'a pas de vocal
     */
    public function transcrire(DeclarationDuCompte $declaration): array
    {
        $vocal = $this->comptes->messageVocal($declaration->memberId)
            ?? throw new NotFoundException('Ce compte n’a pas de message vocal à transcrire.');

        [$texte, $erreur] = $this->ecouter($vocal);
        $le = new \DateTimeImmutable();

        $gardee = $this->comptes->enregistrerLaTranscription($declaration->memberId, $texte, $erreur, $le);

        // L'appel a eu lieu (et, réussi, il a coûté) : il se journalise, gardé ou non.
        $this->audit->enregistrer(
            AuditAction::AppelModeleDeLangage,
            'Assistant\\Transcription',
            (string) $declaration->memberId,
            $this->reglages->modeleDeTranscription(),
            [
                'statut' => null !== $texte ? 'faite' : 'echec',
                'secondes_annoncees' => $vocal->secondes,
                'octets' => \strlen($vocal->octets),
                'caracteres' => null !== $texte ? mb_strlen($texte) : 0,
            ],
        );

        if (!$gardee) {
            // Refusé ou rattaché **pendant** l'écoute : la voix est purgée, et son texte
            // ne doit ni rester ni sortir. Le même `404` que pour un compte déjà décidé.
            throw new NotFoundException('Ce compte n’attend plus de rattachement.');
        }

        return [
            'memberId' => $declaration->memberId,
            'statut' => null !== $texte ? 'faite' : 'echec',
            'texte' => $texte,
            'erreur' => $erreur,
            'le' => $le->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * La charge de `generateContent` : la consigne, puis le son. Publique pour les tests.
     *
     * @return array<string, mixed>
     */
    public static function charge(MessageVocalDuCompte $vocal): array
    {
        return [
            'systemInstruction' => ['parts' => [['text' => self::CONSIGNE]]],
            'contents' => [[
                'role' => 'user',
                'parts' => [
                    ['text' => 'Voici le message vocal à transcrire.'],
                    ['inlineData' => [
                        'mimeType' => self::TYPES_POUR_GEMINI[$vocal->mime] ?? $vocal->mime,
                        'data' => base64_encode($vocal->octets),
                    ]],
                ],
            ]],
            'generationConfig' => ['temperature' => 0.0],
        ];
    }

    /**
     * Le texte, ou le motif de l'échec — jamais les deux.
     *
     * @return array{0: string|null, 1: string|null}
     */
    private function ecouter(MessageVocalDuCompte $vocal): array
    {
        try {
            $reponse = $this->transport->generer(
                $this->reglages->modeleDeTranscription(),
                self::charge($vocal),
                ReglagesDeLAssistant::DELAI_TRANSCRIPTION_SECONDES,
            );
        } catch (\Throwable $e) {
            $this->journal->warning('Transcription du message vocal : l’appel au modèle a échoué.', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return [null, self::motifDeLaPanne($e)];
        }

        $texte = self::texteDe($reponse);

        if (null === $texte) {
            $motif = \is_string($reponse['promptFeedback']['blockReason'] ?? null)
                ? $reponse['promptFeedback']['blockReason']
                : (\is_string($reponse['candidates'][0]['finishReason'] ?? null) ? $reponse['candidates'][0]['finishReason'] : null);

            // Le motif est une énumération du fournisseur, sans contenu.
            $this->journal->warning('Transcription du message vocal : réponse du modèle sans texte.', ['motif' => $motif]);

            return [null, null === $motif
                ? 'Le modèle n’a rendu aucun texte. Réessayez, ou écoutez le message.'
                : \sprintf('Le modèle a refusé de transcrire ce message (%s). Écoutez-le.', $motif)];
        }

        return [$texte, null];
    }

    /**
     * Les parts textuelles de la réponse, mises bout à bout — sans les « pensées » d'un
     * modèle à raisonnement, qui ne sont pas la transcription. `null` si rien n'en reste.
     *
     * @param array<string, mixed> $reponse
     */
    public static function texteDe(array $reponse): ?string
    {
        $parts = $reponse['candidates'][0]['content']['parts'] ?? null;

        if (!\is_array($parts)) {
            return null;
        }

        $morceaux = [];

        foreach ($parts as $part) {
            if (\is_array($part) && \is_string($part['text'] ?? null) && true !== ($part['thought'] ?? false)) {
                $morceaux[] = $part['text'];
            }
        }

        $texte = trim(implode('', $morceaux));

        return '' === $texte ? null : mb_substr($texte, 0, self::TEXTE_MAX);
    }

    /**
     * Ce que l'administrateur lira sous « échec ». Écrit ici, jamais recopié de la
     * réponse du fournisseur : elle pourrait citer la requête.
     */
    private static function motifDeLaPanne(\Throwable $e): string
    {
        if ($e instanceof TimeoutExceptionInterface) {
            return 'Le modèle n’a pas répondu à temps. Réessayez dans un moment.';
        }

        if ($e instanceof HttpExceptionInterface) {
            try {
                $statut = $e->getResponse()->getStatusCode();
            } catch (\Throwable) {
                $statut = 0;
            }

            return match (true) {
                429 === $statut => 'Le quota du fournisseur (Gemini) est atteint. Réessayez plus tard.',
                400 === $statut => 'Le fournisseur (Gemini) a refusé cet enregistrement. Écoutez-le.',
                $statut >= 500 => 'Le fournisseur (Gemini) est en panne. Réessayez dans un moment.',
                default => \sprintf('Le fournisseur (Gemini) a refusé l’appel (%d). Réessayez dans un moment.', $statut),
            };
        }

        if ($e instanceof TransportExceptionInterface) {
            return 'Le fournisseur (Gemini) est injoignable ou trop lent. Réessayez dans un moment.';
        }

        return 'La transcription a échoué. Réessayez dans un moment, ou écoutez le message.';
    }

    /**
     * La consigne. Comme celles des agents, elle dit surtout ce que le modèle ne doit pas
     * faire : résumer, corriger, compléter.
     */
    private const string CONSIGNE = <<<'TEXTE'
        Tu transcris le message vocal qu’une personne a laissé en s’inscrivant sur l’arbre
        généalogique de sa famille. Elle y dit en général qui elle est, sa date de naissance,
        ses parents, ses grands-parents, ses frères et sœurs, son conjoint, ses enfants,
        avec des dates et des lieux.

        Règles :
        - Écris en français, MOT POUR MOT, ce qui est dit : ne résume pas, ne reformule pas,
          ne corrige pas, ne complète pas, n’ajoute rien.
        - Écris les noms de personnes et de lieux, et les dates, exactement tels que tu les
          entends. Si l’orthographe d’un nom est incertaine, écris-le comme il se prononce,
          suivi de « [?] ».
        - Un passage incompréhensible s’écrit « [inaudible] ».
        - Garde les hésitations qui changent le sens (« non, pardon, en 1962 »), pas les
          « euh ».
        - Si l’enregistrement ne contient aucune parole, réponds seulement « [aucune parole] ».
        - Réponds par la transcription seule, en texte simple : pas de titre, pas de
          guillemets, pas de mise en forme, pas de commentaire.
        TEXTE;
}
