<?php

declare(strict_types=1);

namespace App\Module\Core\Service;

use App\Module\Core\Enum\DateQualifier;
use App\Module\Core\Exception\ValidationException;
use App\Module\Core\ValueObject\UncertainDate;

/**
 * Lit un **bloc date à cinq clés** venu d'une requête (§ 14.1 du contrat) et rend la
 * date, ou `422` — avant d'atteindre la base.
 *
 * ## Pourquoi elle est dans `Core`
 *
 * Elle est née dans `Media\Http\BlocDate`, pour la date de prise de vue d'un média. La
 * ressemblance (`POST /api/arbre/ressemblances`, `POST /api/demandes/brouillons/
 * ressemblances`) reçoit à son tour des blocs `birth` et `death`, dans deux modules qui
 * n'ont pas le droit d'importer `Media` (ADR 0002, règle 3). Une troisième copie de ces
 * contrôles aurait été la troisième façon de refuser — ou d'accepter — une fourchette
 * plate. `BlocDate` délègue désormais ici, à l'identique.
 *
 * ## Ce qu'elle accepte, et ce qu'elle refuse
 *
 * - **Les cinq clés au plus** — `raw`, `qualifier`, `min`, `max`, `place`. Une clé absente
 *   vaut `null`, sauf `qualifier`, sans lequel il n'y a pas de date. **`texte` est
 *   refusé** : il est en lecture seule, et un back qui l'accepterait en entrée accepterait
 *   une sixième clé que la comparaison `JSONB` de la détection de conflit ferait diverger
 *   (§ 14.1). C'est au front de la retirer du bloc qu'il a reçu d'une fiche.
 * - **Des bornes `AAAA-MM-JJ`**, jamais un horodatage, et qui existent au calendrier.
 * - **Les deux `CHECK` du modèle**, dits ici en `422` : une date `inconnue` ne porte pas de
 *   borne ; une fourchette (`entre`) exige deux bornes, la basse **strictement** avant la
 *   haute. Le `CHECK` de PostgreSQL est le filet, pas le contrôle : une violation de
 *   contrainte remonterait en `500`, c'est-à-dire en panne, là où c'est une saisie.
 *
 * Toutes les erreurs portent sur **un seul champ**, celui que l'appelant nomme
 * (`taken`, `fiche.birth`…), pour que le `422` se pose sur la bonne ligne du formulaire.
 */
final readonly class LectureDeBlocDate
{
    /** Les cinq clés d'un bloc date, toujours les cinq — `texte` n'en fait pas partie. */
    public const array CLES = ['raw', 'qualifier', 'min', 'max', 'place'];

    /**
     * Un bloc **sérialisé en JSON** — la partie d'un `multipart/form-data`. Une chaîne
     * absente ou blanche est l'absence de date.
     */
    public function depuisJson(?string $json, string $champ): ?UncertainDate
    {
        if (null === $json || '' === trim($json)) {
            return null;
        }

        try {
            $brut = json_decode($json, true, 4, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ValidationException([$champ => 'Bloc date illisible : JSON invalide.']);
        }

        return $this->depuisBloc($brut, $champ);
    }

    /**
     * Un bloc **déjà décodé**, tel qu'il arrive dans un corps JSON. `null` est l'absence de
     * date ; tout ce qui n'est pas un objet est refusé.
     */
    public function depuisValeur(mixed $brut, string $champ): ?UncertainDate
    {
        return null === $brut ? null : $this->depuisBloc($brut, $champ);
    }

    private function depuisBloc(mixed $brut, string $champ): UncertainDate
    {
        if (!\is_array($brut)) {
            throw new ValidationException([$champ => 'Bloc date attendu sous forme d\'objet.']);
        }

        $inconnues = array_diff(array_keys($brut), self::CLES);

        if ([] !== $inconnues) {
            throw new ValidationException([$champ => \sprintf('Clés inattendues dans le bloc date : %s.', implode(', ', array_map(strval(...), $inconnues)))]);
        }

        $qualifier = DateQualifier::tryFrom(\is_string($brut['qualifier'] ?? null) ? $brut['qualifier'] : '');

        if (null === $qualifier) {
            throw new ValidationException([$champ => 'Qualificatif de date inconnu.']);
        }

        $min = $this->date($brut['min'] ?? null, 'min', $champ);
        $max = $this->date($brut['max'] ?? null, 'max', $champ);

        if (DateQualifier::Inconnue === $qualifier && (null !== $min || null !== $max)) {
            throw new ValidationException([$champ => 'Une date inconnue ne porte ni borne basse ni borne haute.']);
        }

        if (DateQualifier::Entre === $qualifier && (null === $min || null === $max || $min >= $max)) {
            // `min < max` strictement : une « fourchette » dont les deux bornes sont
            // égales est une date ferme déguisée, et c'est exactement l'aplatissement
            // que tout le modèle interdit.
            throw new ValidationException([$champ => 'Une fourchette exige deux bornes, la basse strictement avant la haute.']);
        }

        if (null !== $min && null !== $max && $min > $max) {
            throw new ValidationException([$champ => 'La borne basse suit la borne haute.']);
        }

        return new UncertainDate(
            raw: $this->texte($brut['raw'] ?? null, 'raw', 80, $champ),
            qualifier: $qualifier,
            min: $min,
            max: $max,
            place: $this->texte($brut['place'] ?? null, 'place', 255, $champ),
        );
    }

    private function date(mixed $valeur, string $cle, string $champ): ?\DateTimeImmutable
    {
        if (null === $valeur) {
            return null;
        }

        if (!\is_string($valeur) || 1 !== preg_match('/^\d{4}-\d{2}-\d{2}$/', $valeur)) {
            throw new ValidationException([$champ => \sprintf('« %s » doit être une date AAAA-MM-JJ, jamais un horodatage.', $cle)]);
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $valeur);

        if (false === $date || $date->format('Y-m-d') !== $valeur) {
            throw new ValidationException([$champ => \sprintf('« %s » n\'est pas une date valide.', $cle)]);
        }

        return $date;
    }

    private function texte(mixed $valeur, string $cle, int $longueurMax, string $champ): ?string
    {
        if (null === $valeur) {
            return null;
        }

        if (!\is_string($valeur)) {
            throw new ValidationException([$champ => \sprintf('« %s » doit être une chaîne.', $cle)]);
        }

        if (mb_strlen($valeur) > $longueurMax) {
            throw new ValidationException([$champ => \sprintf('« %s » dépasse %d caractères.', $cle, $longueurMax)]);
        }

        return '' === $valeur ? null : $valeur;
    }
}
