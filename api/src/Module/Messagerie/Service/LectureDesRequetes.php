<?php

declare(strict_types=1);

namespace App\Module\Messagerie\Service;

use App\Module\Messagerie\Enum\NatureDuFil;
use App\Module\Messagerie\Enum\StatutDuFil;
use App\Module\Messagerie\Exception\ErreurDeMessagerie;
use Symfony\Component\HttpFoundation\Request;

/**
 * Le corps des requêtes, lu et validé en un seul endroit : chaque erreur se rend en `422`
 * sur la clé du champ fautif, toutes à la fois, pour que le formulaire les montre ensemble.
 */
final class LectureDesRequetes
{
    /** Un message, pas un fichier collé : une longue explication de bogue tient largement. */
    public const int TEXTE_MAX = 5000;

    public const int SUJET_MAX = 160;

    /**
     * L'ouverture d'un fil : la nature, le premier message, et un sujet **facultatif** —
     * à défaut, le début du message en tient lieu. Demander un titre avant de pouvoir
     * écrire « ça ne marche pas », c'est perdre la moitié des signalements.
     *
     * @return array{0: NatureDuFil, 1: string, 2: string}
     */
    public function ouverture(Request $requete): array
    {
        $corps = $this->corps($requete);
        $erreurs = [];

        $nature = \is_string($corps['nature'] ?? null) ? NatureDuFil::tryFrom($corps['nature']) : null;
        if (null === $nature) {
            $erreurs['nature'] = 'Une nature est attendue : question, suggestion ou bug.';
        }

        $texte = $this->texteObligatoire($corps, 'texte', self::TEXTE_MAX, $erreurs);

        $sujet = $corps['sujet'] ?? null;
        if (null !== $sujet && (!\is_string($sujet) || mb_strlen(trim($sujet)) > self::SUJET_MAX)) {
            $erreurs['sujet'] = \sprintf('Un sujet de %d caractères au plus est attendu.', self::SUJET_MAX);
        }

        if ([] !== $erreurs) {
            throw ErreurDeMessagerie::donneesInvalides($erreurs);
        }

        \assert($nature instanceof NatureDuFil && null !== $texte);
        $sujet = \is_string($sujet) ? trim($sujet) : '';

        return [$nature, '' === $sujet ? self::sujetDepuis($texte) : $sujet, $texte];
    }

    public function message(Request $requete): string
    {
        $erreurs = [];
        $texte = $this->texteObligatoire($this->corps($requete), 'texte', self::TEXTE_MAX, $erreurs);

        if (null === $texte) {
            throw ErreurDeMessagerie::donneesInvalides($erreurs);
        }

        return $texte;
    }

    public function statut(Request $requete): StatutDuFil
    {
        $brut = $this->corps($requete)['statut'] ?? null;
        $statut = \is_string($brut) ? StatutDuFil::tryFrom($brut) : null;

        return $statut ?? throw ErreurDeMessagerie::donneesInvalides(['statut' => 'ouvert ou clos est attendu.']);
    }

    /** La première ligne du message, coupée au mot : « La carte ne s'affiche pas… ». */
    public static function sujetDepuis(string $texte): string
    {
        $ligne = trim(strtok($texte, "\n") ?: $texte);
        $ligne = (string) preg_replace('/\s+/u', ' ', $ligne);

        if (mb_strlen($ligne) <= 80) {
            return $ligne;
        }

        $coupe = mb_substr($ligne, 0, 80);
        $espace = mb_strrpos($coupe, ' ');

        return rtrim(false !== $espace && $espace > 40 ? mb_substr($coupe, 0, $espace) : $coupe, " ,.;:").'…';
    }

    /**
     * @param array<string, mixed>  $corps
     * @param array<string, string> $erreurs
     */
    private function texteObligatoire(array $corps, string $cle, int $max, array &$erreurs): ?string
    {
        $valeur = $corps[$cle] ?? null;
        $valeur = \is_string($valeur) ? trim($valeur) : '';

        if ('' === $valeur || mb_strlen($valeur) > $max) {
            $erreurs[$cle] = \sprintf('Un texte de 1 à %d caractères est attendu.', $max);

            return null;
        }

        return $valeur;
    }

    /** @return array<string, mixed> */
    private function corps(Request $requete): array
    {
        try {
            $corps = json_decode($requete->getContent(), true, 32, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw ErreurDeMessagerie::donneesInvalides(['corps' => 'Un objet JSON est attendu.']);
        }

        if (!\is_array($corps) || array_is_list($corps) && [] !== $corps) {
            throw ErreurDeMessagerie::donneesInvalides(['corps' => 'Un objet JSON est attendu.']);
        }

        /** @var array<string, mixed> $corps */
        return $corps;
    }
}
