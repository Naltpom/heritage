<?php

declare(strict_types=1);

namespace App\Module\Membership\Contract;

/**
 * Le **son** du message vocal d'un compte « à rattacher », tel que
 * {@see ComptesARattacher::messageVocal()} le prête au module `Assistant` pour sa
 * transcription (ADR 0013, addendum du 2 octobre 2026, soir).
 *
 * Un objet valeur, pas un chemin : le volume des téléversements reste l'affaire de
 * `Membership`. **Une voix de vivant** : l'appelant ne la garde pas, ne la journalise pas,
 * et ne l'envoie qu'au modèle de langage.
 */
final readonly class MessageVocalDuCompte
{
    public function __construct(
        /** Les octets du fichier, 10 Mio au plus. */
        public string $octets,
        /** Le type **reniflé sur les octets** au dépôt : `audio/webm`, `audio/ogg` ou `audio/mp4`. */
        public string $mime,
        /** La durée annoncée par le chrono du navigateur — indicative, parfois absente. */
        public ?int $secondes,
    ) {
    }
}
