<?php

declare(strict_types=1);

namespace App\Module\Tirage\Service;

use App\Module\Tirage\Entity\Tirage;
use App\Module\Tirage\Enum\Presence;
use App\Module\Tirage\Exception\ErreurDeTirage;
use Symfony\Component\HttpFoundation\Request;

/**
 * Du corps JSON aux valeurs du module, avec un `422` qui nomme **chaque** champ fautif.
 *
 * Aucune valeur n'est devinée : un booléen est un booléen JSON, un entier un entier. Un
 * `"2"` n'est pas `2` — le front sait envoyer les bons types, et un serveur qui convertit
 * cache les défauts du client au lieu de les signaler.
 */
final class LectureDesRequetes
{
    public const int ANNEE_MIN = 2000;
    public const int ANNEE_MAX = 2200;

    public function regles(Request $requete): ReglesDuTirage
    {
        $corps = $this->corps($requete);
        $erreurs = [];

        $nom = $corps['nom'] ?? null;
        $nom = \is_string($nom) ? trim($nom) : '';
        if ('' === $nom || mb_strlen($nom) > 120) {
            $erreurs['nom'] = 'Un nom de 1 à 120 caractères est attendu.';
        }

        $jour = $corps['jour'] ?? null;
        $mois = $corps['mois'] ?? null;
        if (!\is_int($mois) || $mois < 1 || $mois > 12) {
            $erreurs['mois'] = 'Un mois de 1 à 12 est attendu.';
        } elseif (!\is_int($jour) || $jour < 1 || $jour > $this->joursAuPlus($mois)) {
            $erreurs['jour'] = 'Ce jour n’existe pas dans ce mois.';
        }

        $lieu = $corps['lieu'] ?? null;
        if (null !== $lieu && (!\is_string($lieu) || mb_strlen(trim($lieu)) > 160)) {
            $erreurs['lieu'] = 'Un lieu de 160 caractères au plus est attendu.';
        }

        $memoire = $corps['memoire'] ?? 2;
        if (!\is_int($memoire) || $memoire < 0 || $memoire > Tirage::MEMOIRE_MAX) {
            $erreurs['memoire'] = \sprintf('Un nombre de tirages de 0 à %d est attendu.', Tirage::MEMOIRE_MAX);
        }

        $ouvert = $corps['ouvert'] ?? false;
        if (!\is_bool($ouvert)) {
            $erreurs['ouvert'] = 'Un booléen est attendu.';
        }

        $exclureConjoints = $corps['exclureConjoints'] ?? true;
        if (!\is_bool($exclureConjoints)) {
            $erreurs['exclureConjoints'] = 'Un booléen est attendu.';
        }

        if ([] !== $erreurs) {
            throw ErreurDeTirage::donneesInvalides($erreurs);
        }

        \assert(\is_int($jour) && \is_int($mois) && \is_int($memoire) && \is_bool($ouvert) && \is_bool($exclureConjoints));
        $lieu = \is_string($lieu) ? trim($lieu) : null;

        return new ReglesDuTirage($nom, $jour, $mois, '' === $lieu ? null : $lieu, $ouvert, $memoire, $exclureConjoints);
    }

    /** @return list<int> */
    public function participantsInitiaux(Request $requete): array
    {
        $brut = $this->corps($requete)['participants'] ?? [];

        if (!\is_array($brut) || !array_is_list($brut) || [] !== array_filter($brut, static fn (mixed $id): bool => !\is_int($id))) {
            throw ErreurDeTirage::donneesInvalides(['participants' => 'Une liste d’identifiants de personnes est attendue.']);
        }

        /* @var list<int> $brut */
        return $brut;
    }

    /** @return array{personId: int, referentPersonId: int|null} */
    public function participant(Request $requete): array
    {
        $corps = $this->corps($requete);
        $personId = $corps['personId'] ?? null;
        $referent = $corps['referentPersonId'] ?? null;
        $erreurs = [];

        if (!\is_int($personId)) {
            $erreurs['personId'] = 'Un identifiant de personne est attendu.';
        }

        if (null !== $referent && !\is_int($referent)) {
            $erreurs['referentPersonId'] = 'Un identifiant de personne est attendu.';
        }

        if ([] !== $erreurs) {
            throw ErreurDeTirage::donneesInvalides($erreurs);
        }

        \assert(\is_int($personId) && (null === $referent || \is_int($referent)));

        return ['personId' => $personId, 'referentPersonId' => $referent];
    }

    /** @return array{annee: int, date: \DateTimeImmutable|null} */
    public function edition(Request $requete): array
    {
        $corps = $this->corps($requete);
        $annee = $corps['annee'] ?? null;
        $date = $corps['date'] ?? null;

        if (!\is_int($annee) || $annee < self::ANNEE_MIN || $annee > self::ANNEE_MAX) {
            throw ErreurDeTirage::donneesInvalides(['annee' => \sprintf('Une année de %d à %d est attendue.', self::ANNEE_MIN, self::ANNEE_MAX)]);
        }

        if (null === $date) {
            return ['annee' => $annee, 'date' => null];
        }

        $lue = \is_string($date) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $date) : false;

        if (false === $lue || $lue->format('Y-m-d') !== $date) {
            throw ErreurDeTirage::donneesInvalides(['date' => 'Une date au format AAAA-MM-JJ est attendue.']);
        }

        return ['annee' => $annee, 'date' => $lue];
    }

    public function presence(Request $requete): Presence
    {
        $brut = $this->corps($requete)['presence'] ?? null;
        $presence = \is_string($brut) ? Presence::tryFrom($brut) : null;

        return $presence ?? throw ErreurDeTirage::donneesInvalides(['presence' => 'Valeur attendue : '.implode(', ', array_map(static fn (Presence $p): string => $p->value, Presence::cases())).'.']);
    }

    /** Le 29 février est accepté : l'année n'est pas connue ici, voir `dateDeLAnnee()`. */
    private function joursAuPlus(int $mois): int
    {
        return (int) (new \DateTimeImmutable(\sprintf('2024-%02d-01', $mois)))->format('t');
    }

    /** @return array<string, mixed> */
    private function corps(Request $requete): array
    {
        $brut = $requete->getContent();

        try {
            $decode = '' === $brut ? [] : json_decode($brut, true, 16, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw ErreurDeTirage::donneesInvalides(['corps' => 'Le corps de la requête n’est pas du JSON valide.']);
        }

        if (!\is_array($decode)) {
            throw ErreurDeTirage::donneesInvalides(['corps' => 'Le corps de la requête est un objet JSON.']);
        }

        /* @var array<string, mixed> $decode */
        return $decode;
    }
}
