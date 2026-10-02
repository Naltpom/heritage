<?php

declare(strict_types=1);

namespace App\Module\Membership\Service;

use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Le **message vocal** qui accompagne — ou remplace — la description de famille (ADR 0013,
 * addendum du 2 octobre 2026).
 *
 * Le candidat qui ne s'est pas trouvé est invité, par une consigne parlée, à raconter qui
 * il est : sa date de naissance, ses parents, ses frères et sœurs, son conjoint, ses
 * enfants, avec leurs dates. Beaucoup le diront plus volontiers qu'ils ne l'écriront —
 * surtout sur un téléphone. Le navigateur enregistre (`MediaRecorder`) et le fichier part
 * **avec** la déclaration, dans le même `POST /api/inscription/compte` en `multipart` :
 * pas de dépôt préalable, donc pas de fichier orphelin d'un parcours abandonné, et pas de
 * route de dépôt anonyme à protéger à part. Le jeton d'invitation et le limiteur par IP de
 * l'inscription sans fiche gardent ce fichier comme ils gardent le reste de la requête.
 *
 * Ce que cette classe vérifie, **avant** que quoi que ce soit ne soit écrit :
 *
 * - **le transfert a réussi** — PHP refuse lui-même au-delà de `upload_max_filesize`, avant
 *   la première ligne d'ici ;
 * - **10 Mo au plus** : à 32 kbit/s, cinq minutes de voix en font à peine 1,2 ; la marge
 *   couvre les navigateurs qui ignorent le débit demandé ;
 * - **le type réel, lu sur les octets d'en-tête** — jamais l'extension, jamais le
 *   `Content-Type` annoncé. Même doctrine que `Media\Service\DetecteurDeType`, recopiée et
 *   non importée : la frontière des modules l'interdit, et ce sont trois signatures. Trois
 *   formats, ceux que produit `MediaRecorder` : WebM (Chromium), Ogg (Firefox), MP4 (Safari,
 *   iOS compris) ;
 * - **la durée annoncée**, si elle l'est : un entier de 1 à 310 secondes. Elle n'est
 *   qu'indicative — c'est le chrono du navigateur — et ne sert qu'à l'affichage côté
 *   administrateur, parce qu'un WebM de `MediaRecorder` n'annonce pas la sienne.
 *
 * Les erreurs ont la forme de celles de {@see DeclarationDeFamille} : une clé
 * `declaration.messageVocal`, fusionnée par l'appelant dans **le seul** `422` de la route.
 */
final readonly class MessageVocalDeDeclaration
{
    /** 10 Mio. */
    public const int TAILLE_MAX = 10 * 1024 * 1024;

    /** Cinq minutes — la borne du navigateur —, et dix secondes de jeu pour le chrono. */
    public const int DUREE_MAX = 310;

    /** Les trois types admis, dans l'ordre où le front les essaie. */
    public const array TYPES = ['audio/webm', 'audio/mp4', 'audio/ogg'];

    /**
     * Les marques `ftyp` d'**images** HEIF/AVIF : un conteneur ISO de photo n'est pas un
     * enregistrement, même s'il en a la structure.
     */
    private const array MARQUES_D_IMAGES = ['heic', 'heix', 'hevc', 'hevx', 'heim', 'heis', 'mif1', 'msf1', 'heif', 'avif', 'avis'];

    private function __construct(
        /** Le fichier temporaire de PHP, que le stockage copiera. */
        public string $chemin,
        public string $mime,
        public int $taille,
        public ?int $secondes,
    ) {
    }

    /**
     * Le message vocal, ou ce qui ne va pas — jamais les deux. `[null, []]` quand il n'y en
     * a pas : il est facultatif.
     *
     * @return array{0: self|null, 1: array<string, string>}
     */
    public static function lire(mixed $fichier, mixed $dureeBrute): array
    {
        if (null === $fichier) {
            return [null, []];
        }

        if (!$fichier instanceof UploadedFile) {
            return [null, ['declaration.messageVocal' => 'Un seul message vocal est attendu.']];
        }

        if (!$fichier->isValid()) {
            return [null, ['declaration.messageVocal' => \in_array($fichier->getError(), [\UPLOAD_ERR_INI_SIZE, \UPLOAD_ERR_FORM_SIZE], true)
                ? 'Le message vocal dépasse 10 Mo : enregistrez-en un plus court.'
                : 'Le message vocal n’a pas pu être transmis. Réessayez.']];
        }

        $taille = (int) $fichier->getSize();

        if ($taille <= 0) {
            return [null, ['declaration.messageVocal' => 'Le message vocal est vide : enregistrez-le de nouveau.']];
        }

        if ($taille > self::TAILLE_MAX) {
            return [null, ['declaration.messageVocal' => 'Le message vocal dépasse 10 Mo : enregistrez-en un plus court.']];
        }

        $entete = @file_get_contents($fichier->getPathname(), false, null, 0, 16);
        $mime = \is_string($entete) ? self::typeRenifle($entete) : null;

        if (null === $mime) {
            return [null, ['declaration.messageVocal' => 'Ce fichier n’est pas un enregistrement audio accepté (WebM, Ogg ou MP4).']];
        }

        [$secondes, $refusDuree] = self::duree($dureeBrute);

        if (null !== $refusDuree) {
            return [null, ['declaration.messageVocal' => $refusDuree]];
        }

        return [new self($fichier->getPathname(), $mime, $taille, $secondes), []];
    }

    /**
     * Le type **reniflé sur les octets**. Exposé pour les tests, qui interrogent une chaîne
     * sans écrire de fichier.
     */
    public static function typeRenifle(string $entete): ?string
    {
        // EBML : WebM et Matroska. `MediaRecorder` de Chromium (Chrome, Edge, Android).
        if (str_starts_with($entete, "\x1A\x45\xDF\xA3")) {
            return 'audio/webm';
        }

        // Ogg : Firefox enregistre de l'Opus dans ce conteneur.
        if (str_starts_with($entete, 'OggS')) {
            return 'audio/ogg';
        }

        // ISO BMFF (MP4) : la boîte `ftyp` à l'octet 4, la marque à l'octet 8. Safari, iOS
        // compris, n'enregistre qu'en MP4/AAC ; les images HEIF/AVIF sont écartées.
        if ('ftyp' === substr($entete, 4, 4)) {
            $marque = strtolower(substr($entete, 8, 4));

            return 4 === \strlen($marque) && !\in_array($marque, self::MARQUES_D_IMAGES, true) ? 'audio/mp4' : null;
        }

        return null;
    }

    /** @return array{0: int|null, 1: string|null} la durée en secondes, et le refus */
    private static function duree(mixed $brute): array
    {
        if (null === $brute || '' === $brute) {
            return [null, null];
        }

        // Le corps multipart ne connaît que des chaînes ; le JSON, des nombres.
        $texte = \is_int($brute) ? (string) $brute : $brute;

        if (!\is_string($texte) || 1 !== preg_match('/^\d{1,4}$/', $texte)) {
            return [null, 'La durée du message vocal est illisible.'];
        }

        $secondes = (int) $texte;

        if ($secondes < 1 || $secondes > self::DUREE_MAX) {
            return [null, 'Le message vocal doit durer cinq minutes au plus.'];
        }

        return [$secondes, null];
    }
}
