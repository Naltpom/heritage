<?php

declare(strict_types=1);

namespace App\Module\Assistant\Service;

use App\Module\Assistant\Contract\DepotPourLAssistant;
use App\Module\Core\Exception\ConflictException;
use App\Module\Core\Exception\NotFoundException;

/**
 * Déposer, au clic du membre, une demande **gardée côté serveur** par l'analyse.
 *
 * Le front ne reposte pas le corps : il désigne `analyseId` et `cle`, et c'est le corps
 * complet — celui dont les `oldValue` n'ont peut-être jamais été montrés au membre — qui
 * part vers `DepotDeDemande`, soumis (`en_attente`), au nom du lecteur. Rien n'est
 * appliqué : la demande entre dans la file comme toutes les autres.
 *
 * ── Une clé ne se dépose qu'une fois ─────────────────────────────────────────────
 *
 * Un double clic, un « Déposer les 3 demandes » relancé après une coupure réseau : sans
 * ce contrôle, la file recevrait deux fois la même demande, et l'administrateur
 * validerait deux fois le même fait. La clé est **marquée avant** le dépôt et démarquée
 * s'il échoue — la fenêtre où deux requêtes simultanées passeraient toutes deux se réduit
 * à la lecture du cache, faute de verrou dans ce projet.
 */
final readonly class DepotDepuisLAnalyse
{
    /** Le mot au demandeur, borné comme ce que le formulaire laisse écrire. */
    private const int MESSAGE_MAXIMAL = 2000;

    public function __construct(
        private MemoireDesAnalyses $memoire,
        private DepotPourLAssistant $depot,
    ) {
    }

    /**
     * @return array<string, mixed> la demande créée, sous la forme de `POST /api/demandes`
     */
    public function deposer(int $userId, string $analyseId, string $cle, ?string $message): array
    {
        // `404` et non `403` pour l'analyse d'un autre : la clé du cache porte le compte,
        // et une analyse d'autrui est simplement **introuvable** — on ne confirme pas
        // qu'elle existe.
        $analyse = $this->memoire->lire($userId, $analyseId)
            ?? throw new NotFoundException('Cette analyse n’existe pas ou a expiré (elle est gardée deux heures). Relancez l’analyse.');

        $demande = $analyse['demandes'][$cle]
            ?? throw new NotFoundException('Cette demande ne fait pas partie de l’analyse.');

        if (\array_key_exists($cle, $analyse['deposees'])) {
            $deja = $analyse['deposees'][$cle];

            throw new ConflictException('deja_deposee', 'Cette demande a déjà été déposée.', null === $deja ? null : ['demandeId' => $deja]);
        }

        $corps = $demande['corps'];
        $corps['message'] = self::message($message, $demande['justification']);

        $this->memoire->noterLeDepot($userId, $analyseId, $cle, null);

        try {
            $deposee = $this->depot->deposer($corps);
        } catch (\Throwable $e) {
            $this->memoire->noterLeDepot($userId, $analyseId, $cle, false);

            throw $e;
        }

        $this->memoire->noterLeDepot($userId, $analyseId, $cle, \is_int($deposee['id'] ?? null) ? $deposee['id'] : null);

        return $deposee;
    }

    /**
     * Le mot du membre s'il en a écrit un ; sinon, la justification de l'assistant,
     * annoncée comme telle. L'administrateur doit savoir **sur quoi** la demande s'appuie
     * — c'est la question que l'ADR 0004 met au cœur de la validation — et la
     * justification est justement la réponse.
     */
    private static function message(?string $message, string $justification): string
    {
        $message = null === $message ? '' : trim($message);

        if ('' !== $message) {
            return mb_substr($message, 0, self::MESSAGE_MAXIMAL);
        }

        $justification = trim($justification);

        return '' === $justification
            ? 'Proposée avec l’assistant.'
            : mb_substr('Proposée avec l’assistant. '.$justification, 0, self::MESSAGE_MAXIMAL);
    }
}
