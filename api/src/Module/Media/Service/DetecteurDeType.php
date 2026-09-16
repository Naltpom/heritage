<?php

declare(strict_types=1);

namespace App\Module\Media\Service;

/**
 * Le type réel d'un fichier, **déterminé par ses octets d'en-tête**.
 *
 * Jamais par l'extension, jamais par le `Content-Type` annoncé par le client (§ 6.5 du
 * dossier, § 7.1 du contrat). Les deux se falsifient en une seconde : renommer
 * `charge.php` en `acte.jpg` et poser `Content-Type: image/jpeg` est à la portée de
 * n'importe quel client HTTP, et c'est la première chose que tente qui cherche à faire
 * écrire un exécutable dans un dossier servi.
 *
 * **Pourquoi une table de signatures écrite à la main plutôt que `finfo`.** `finfo`
 * reconnaît des centaines de formats à partir d'une base `magic` dont le contenu dépend
 * de la machine et de la version de libmagic ; on obtiendrait ici un type que la liste
 * blanche refuserait de toute façon, avec une surface d'analyse énorme pour quatre
 * formats attendus. Quatre signatures écrites en clair se lisent, se testent, et
 * n'ont pas de dépendance d'environnement. `ext-fileinfo` n'est d'ailleurs pas déclarée
 * dans `composer.json`.
 *
 * Le fichier doit **commencer** par sa signature. Le format PDF tolère jusqu'à 1 024
 * octets de rebut avant `%PDF-` ; on ne le tolère pas, parce que c'est exactement la
 * marge dans laquelle se loge un polyglotte (un fichier qui est à la fois une image
 * valide pour l'un et autre chose pour l'autre).
 */
final class DetecteurDeType
{
    /**
     * Assez pour la plus longue signature (WebP : 12 octets) avec de la marge.
     */
    private const int OCTETS_LUS = 16;

    public function detecter(string $chemin): ?string
    {
        $entete = @file_get_contents($chemin, false, null, 0, self::OCTETS_LUS);

        if (!\is_string($entete) || '' === $entete) {
            return null;
        }

        return $this->depuisLOctets($entete);
    }

    /** Exposée à part pour que les tests puissent interroger une chaîne sans écrire de fichier. */
    public function depuisLOctets(string $entete): ?string
    {
        // JPEG : SOI (`FF D8`) suivi du premier marqueur (`FF`). Couvre JFIF, Exif et
        // les variantes sans segment d'application.
        if (str_starts_with($entete, "\xFF\xD8\xFF")) {
            return 'image/jpeg';
        }

        // PNG : la signature à huit octets, dont le `\r\n` et le `\x1A` qui servent à
        // détecter les transferts en mode texte.
        if (str_starts_with($entete, "\x89PNG\r\n\x1A\n")) {
            return 'image/png';
        }

        // WebP : conteneur RIFF. Les quatre octets 4 à 7 portent la taille, d'où le saut.
        if (str_starts_with($entete, 'RIFF') && 'WEBP' === substr($entete, 8, 4)) {
            return 'image/webp';
        }

        if (str_starts_with($entete, '%PDF-')) {
            return 'application/pdf';
        }

        return null;
    }
}
