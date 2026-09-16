<?php

declare(strict_types=1);

namespace App\Module\Media\Http;

use App\Module\Core\Enum\DateQualifier;
use App\Module\Core\Exception\ValidationException;
use App\Module\Core\ValueObject\UncertainDate;

/**
 * Lit la partie `taken` du `multipart/form-data` : un bloc date à **cinq clés**,
 * sérialisé en JSON (§ 7.1 et § 14.1 du contrat).
 *
 * Cinq clés, toujours les cinq, « pour qu'un champ absent soit toujours une erreur et
 * jamais un non-renseigné implicite ». `texte` n'en fait **pas** partie : il est en
 * lecture seule, et un back qui l'accepterait en entrée accepterait une sixième clé que
 * la comparaison `JSONB` de la détection de conflit ferait diverger (§ 14.1).
 *
 * Les deux contraintes de cohérence sont vérifiées ici et rendent `422` **avant**
 * d'atteindre la base : le `CHECK` de PostgreSQL est le filet, pas le contrôle. Une
 * violation de contrainte remonterait en `500`, c'est-à-dire en panne, là où c'est une
 * saisie invalide.
 */
final class BlocDate
{
    public static function depuisJson(?string $json): ?UncertainDate
    {
        if (null === $json || '' === trim($json)) {
            return null;
        }

        try {
            $brut = json_decode($json, true, 4, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ValidationException(['taken' => 'Bloc date illisible : JSON invalide.']);
        }

        if (!\is_array($brut)) {
            throw new ValidationException(['taken' => 'Bloc date attendu sous forme d\'objet.']);
        }

        $inconnues = array_diff(array_keys($brut), ['raw', 'qualifier', 'min', 'max', 'place']);

        if ([] !== $inconnues) {
            throw new ValidationException(['taken' => \sprintf('Clés inattendues dans le bloc date : %s.', implode(', ', array_map(strval(...), $inconnues)))]);
        }

        $qualifier = DateQualifier::tryFrom(\is_string($brut['qualifier'] ?? null) ? $brut['qualifier'] : '');

        if (null === $qualifier) {
            throw new ValidationException(['taken' => 'Qualificatif de date inconnu.']);
        }

        $min = self::date($brut['min'] ?? null, 'min');
        $max = self::date($brut['max'] ?? null, 'max');

        if (DateQualifier::Inconnue === $qualifier && (null !== $min || null !== $max)) {
            throw new ValidationException(['taken' => 'Une date inconnue ne porte ni borne basse ni borne haute.']);
        }

        if (DateQualifier::Entre === $qualifier && (null === $min || null === $max || $min >= $max)) {
            // `min < max` strictement : une « fourchette » dont les deux bornes sont
            // égales est une date ferme déguisée, et c'est exactement l'aplatissement
            // que tout le modèle interdit.
            throw new ValidationException(['taken' => 'Une fourchette exige deux bornes, la basse strictement avant la haute.']);
        }

        if (null !== $min && null !== $max && $min > $max) {
            throw new ValidationException(['taken' => 'La borne basse suit la borne haute.']);
        }

        return new UncertainDate(
            raw: self::texte($brut['raw'] ?? null, 'raw', 80),
            qualifier: $qualifier,
            min: $min,
            max: $max,
            place: self::texte($brut['place'] ?? null, 'place', 255),
        );
    }

    private static function date(mixed $valeur, string $cle): ?\DateTimeImmutable
    {
        if (null === $valeur) {
            return null;
        }

        if (!\is_string($valeur) || 1 !== preg_match('/^\d{4}-\d{2}-\d{2}$/', $valeur)) {
            throw new ValidationException(['taken' => \sprintf('« %s » doit être une date AAAA-MM-JJ, jamais un horodatage.', $cle)]);
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $valeur);

        if (false === $date || $date->format('Y-m-d') !== $valeur) {
            throw new ValidationException(['taken' => \sprintf('« %s » n\'est pas une date valide.', $cle)]);
        }

        return $date;
    }

    private static function texte(mixed $valeur, string $cle, int $longueurMax): ?string
    {
        if (null === $valeur) {
            return null;
        }

        if (!\is_string($valeur)) {
            throw new ValidationException(['taken' => \sprintf('« %s » doit être une chaîne.', $cle)]);
        }

        if (mb_strlen($valeur) > $longueurMax) {
            throw new ValidationException(['taken' => \sprintf('« %s » dépasse %d caractères.', $cle, $longueurMax)]);
        }

        return '' === $valeur ? null : $valeur;
    }
}
