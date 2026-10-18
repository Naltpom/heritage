<?php

declare(strict_types=1);

namespace App\Module\Noel\Service;

use App\Module\Core\Exception\ValidationException;
use App\Module\Noel\Entity\TirageNoel;
use App\Module\Noel\Enum\Presence;
use App\Module\Noel\Enum\Reponse;
use App\Module\Noel\Enum\Revelation;
use Symfony\Component\HttpFoundation\Request;

/** La lecture et la validation des corps de requête du site de Noël. */
final class LectureNoel
{
    public const int BUDGET_MAX = 10_000;

    /** @return array<string, mixed> */
    public function corps(Request $requete): array
    {
        try {
            $corps = json_decode($requete->getContent(), true, 16, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ValidationException(['corps' => 'Corps JSON attendu.']);
        }

        return \is_array($corps) ? $corps : [];
    }

    /** @param array<string, mixed> $corps */
    public function reglages(array $corps): ReglagesDuTirageNoel
    {
        $champs = [];

        $titre = $this->texte($corps, 'titre', TirageNoel::TITRE_MAX, $champs, true);
        $dateTirage = $this->instant($corps, 'dateTirage', $champs);
        $dateEvenement = $this->instant($corps, 'dateEvenement', $champs);

        $budget = null;
        if (\array_key_exists('budget', $corps)) {
            $brut = $corps['budget'];
            if (!\is_int($brut) && !(\is_string($brut) && ctype_digit($brut))) {
                $champs['budget'] = 'Le budget est un nombre entier d’euros.';
            } elseif ((int) $brut < 1 || (int) $brut > self::BUDGET_MAX) {
                $champs['budget'] = \sprintf('Le budget va de 1 à %d €.', self::BUDGET_MAX);
            } else {
                $budget = (int) $brut;
            }
        }

        $lieu = $this->texte($corps, 'lieu', TirageNoel::LIEU_MAX, $champs);
        $message = $this->texte($corps, 'message', TirageNoel::MESSAGE_MAX, $champs);
        $presentation = $this->texte($corps, 'presentation', TirageNoel::PRESENTATION_MAX, $champs);

        if ([] !== $champs) {
            throw new ValidationException($champs);
        }

        return new ReglagesDuTirageNoel(
            $titre,
            $dateTirage,
            $dateEvenement,
            $budget,
            $lieu,
            $message,
            \array_key_exists('lieu', $corps),
            \array_key_exists('message', $corps),
            $presentation,
            \array_key_exists('presentation', $corps),
        );
    }

    /** @param array<string, mixed> $corps */
    public function reponse(array $corps): ReponseAuTirage
    {
        $champs = [];

        $reponse = \is_string($corps['reponse'] ?? null) ? Reponse::tryFrom($corps['reponse']) : null;
        if (null === $reponse) {
            $champs['reponse'] = 'Réponse attendue : oui ou non.';
        }

        $presence = null;
        if (null !== ($corps['presence'] ?? null)) {
            $presence = \is_string($corps['presence']) ? Presence::tryFrom($corps['presence']) : null;
            if (null === $presence) {
                $champs['presence'] = 'Présence attendue : present, absent ou ne_sait_pas.';
            }
        }

        $vote = null;
        if (null !== ($corps['vote'] ?? null)) {
            $vote = \is_string($corps['vote']) ? Revelation::tryFrom($corps['vote']) : null;
            if (null === $vote) {
                $champs['vote'] = 'Vote attendu : magie ou apres_ouverture.';
            }
        }

        $conjoint = $corps['conjointUserId'] ?? null;
        if (null !== $conjoint && !\is_int($conjoint)) {
            $champs['conjointUserId'] = 'Identifiant de compte attendu.';
        }

        if ([] !== $champs) {
            throw new ValidationException($champs);
        }
        \assert(null !== $reponse);

        return new ReponseAuTirage($reponse, $presence, $vote, \is_int($conjoint) ? $conjoint : null);
    }

    /**
     * @param array<string, mixed>  $corps
     * @param array<string, string> $champs
     */
    private function texte(array $corps, string $cle, int $max, array &$champs, bool $nonVide = false): ?string
    {
        $valeur = $corps[$cle] ?? null;

        if (null === $valeur) {
            return null;
        }
        if (!\is_string($valeur)) {
            $champs[$cle] = 'Texte attendu.';

            return null;
        }

        $valeur = trim($valeur);
        if ('' === $valeur) {
            if ($nonVide) {
                $champs[$cle] = 'Ce champ ne peut pas être vide.';
            }

            return null;
        }
        if (mb_strlen($valeur) > $max) {
            $champs[$cle] = \sprintf('%d caractères au plus.', $max);

            return null;
        }

        return $valeur;
    }

    /**
     * @param array<string, mixed>  $corps
     * @param array<string, string> $champs
     */
    private function instant(array $corps, string $cle, array &$champs): ?\DateTimeImmutable
    {
        $valeur = $corps[$cle] ?? null;

        if (null === $valeur) {
            return null;
        }

        $instant = \is_string($valeur) ? \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $valeur)
            ?: \DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s.vP', $valeur)
            ?: \DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s.v\Z', $valeur, new \DateTimeZone('UTC'))
            ?: \DateTimeImmutable::createFromFormat('Y-m-d\TH:iP', $valeur) : false;

        if (false === $instant) {
            $champs[$cle] = 'Date ISO 8601 attendue, avec son fuseau (2026-12-24T18:00:00+01:00).';

            return null;
        }

        return $instant;
    }
}
