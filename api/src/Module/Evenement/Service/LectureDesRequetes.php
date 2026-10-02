<?php

declare(strict_types=1);

namespace App\Module\Evenement\Service;

use App\Module\Evenement\Enum\Reponse;
use App\Module\Evenement\Exception\ErreurDEvenement;
use Symfony\Component\HttpFoundation\Request;

/**
 * Du corps JSON aux valeurs du module, avec un `422` qui nomme **chaque** champ fautif.
 *
 * Aucune valeur n'est devinée, comme dans `Tirage\Service\LectureDesRequetes` : une date est
 * une chaîne `AAAA-MM-JJ`, un identifiant un entier JSON. Un serveur qui convertit cache les
 * défauts du client au lieu de les signaler.
 */
final class LectureDesRequetes
{
    public const int ANNEE_MIN = 2000;
    public const int ANNEE_MAX = 2200;
    public const int TITRE_MAX = 120;
    public const int LIEU_MAX = 160;

    /**
     * Assez pour un programme de week-end, une liste de choses à apporter et un plan d'accès ;
     * pas assez pour en faire un mur de messages — l'application n'en a pas.
     */
    public const int DESCRIPTION_MAX = 4000;

    /**
     * L'annonce, validée champ par champ.
     *
     * `$aujourdhui` borne la date de fin : on n'annonce pas un événement déjà terminé. Le
     * début, lui, peut être passé — un événement en cours se corrige encore (« on finit
     * finalement lundi »).
     */
    public function annonce(Request $requete, \DateTimeImmutable $aujourdhui): AnnonceDeLEvenement
    {
        $corps = $this->corps($requete);
        $erreurs = [];

        $titre = $corps['titre'] ?? null;
        $titre = \is_string($titre) ? trim($titre) : '';
        if ('' === $titre || mb_strlen($titre) > self::TITRE_MAX) {
            $erreurs['titre'] = \sprintf('Un titre de 1 à %d caractères est attendu.', self::TITRE_MAX);
        }

        $debut = $this->date($corps['debut'] ?? null);
        if (null === $debut) {
            $erreurs['debut'] = 'Une date de début au format AAAA-MM-JJ est attendue.';
        }

        $fin = $this->date($corps['fin'] ?? null);
        if (null === $fin) {
            $erreurs['fin'] = 'Une date de fin au format AAAA-MM-JJ est attendue.';
        } elseif (null !== $debut && $fin < $debut) {
            $erreurs['fin'] = 'La fin ne peut pas précéder le début. Pour un seul jour, mettez la même date.';
        } elseif ($fin < $aujourdhui->setTime(0, 0)) {
            $erreurs['fin'] = 'Cette date est passée : on n’annonce qu’un événement à venir.';
        }

        $lieu = $corps['lieu'] ?? null;
        if (null !== $lieu && (!\is_string($lieu) || mb_strlen(trim($lieu)) > self::LIEU_MAX)) {
            $erreurs['lieu'] = \sprintf('Un lieu de %d caractères au plus est attendu.', self::LIEU_MAX);
        }

        $description = $corps['description'] ?? null;
        if (null !== $description && (!\is_string($description) || mb_strlen(trim($description)) > self::DESCRIPTION_MAX)) {
            $erreurs['description'] = \sprintf('Une description de %d caractères au plus est attendue.', self::DESCRIPTION_MAX);
        }

        if ([] !== $erreurs) {
            throw ErreurDEvenement::donneesInvalides($erreurs);
        }

        \assert(null !== $debut && null !== $fin);
        $lieu = \is_string($lieu) ? trim($lieu) : null;
        // Les retours à la ligne sont gardés — c'est un texte sur plusieurs lignes ; seuls
        // les blancs autour de l'ensemble s'en vont.
        $description = \is_string($description) ? trim($description) : null;

        return new AnnonceDeLEvenement(
            $titre,
            $debut,
            $fin,
            '' === $lieu ? null : $lieu,
            '' === $description ? null : $description,
        );
    }

    /**
     * Une liste d'identifiants de personnes, sous la clé donnée (`invites` à la création,
     * `personIds` à l'ajout). Absente : une liste vide — on peut créer un événement et
     * inviter ensuite.
     *
     * @return list<int>
     */
    public function personnes(Request $requete, string $cle): array
    {
        $brut = $this->corps($requete)[$cle] ?? [];

        if (!\is_array($brut) || !array_is_list($brut) || [] !== array_filter($brut, static fn (mixed $id): bool => !\is_int($id))) {
            throw ErreurDEvenement::donneesInvalides([$cle => 'Une liste d’identifiants de personnes est attendue.']);
        }

        /* @var list<int> $brut */
        return $brut;
    }

    public function reponse(Request $requete): Reponse
    {
        $brut = $this->corps($requete)['reponse'] ?? null;
        $reponse = \is_string($brut) ? Reponse::tryFrom($brut) : null;

        return $reponse ?? throw ErreurDEvenement::donneesInvalides(['reponse' => 'Valeur attendue : '.implode(', ', array_map(static fn (Reponse $r): string => $r->value, Reponse::cases())).'.']);
    }

    /** Une date `AAAA-MM-JJ` qui existe, dans les bornes ; `null` sinon. */
    private function date(mixed $brut): ?\DateTimeImmutable
    {
        if (!\is_string($brut)) {
            return null;
        }

        $lue = \DateTimeImmutable::createFromFormat('!Y-m-d', $brut);

        if (false === $lue || $lue->format('Y-m-d') !== $brut) {
            return null;
        }

        $annee = (int) $lue->format('Y');

        return $annee < self::ANNEE_MIN || $annee > self::ANNEE_MAX ? null : $lue;
    }

    /** @return array<string, mixed> */
    private function corps(Request $requete): array
    {
        $brut = $requete->getContent();

        try {
            $decode = '' === $brut ? [] : json_decode($brut, true, 16, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw ErreurDEvenement::donneesInvalides(['corps' => 'Le corps de la requête n’est pas du JSON valide.']);
        }

        if (!\is_array($decode)) {
            throw ErreurDEvenement::donneesInvalides(['corps' => 'Le corps de la requête est un objet JSON.']);
        }

        /* @var array<string, mixed> $decode */
        return $decode;
    }
}
