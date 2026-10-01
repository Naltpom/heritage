<?php

declare(strict_types=1);

namespace App\Module\Demarche\Service;

use App\Module\Demarche\Enum\CanalDEchange;
use App\Module\Demarche\Enum\FormeDActe;
use App\Module\Demarche\Enum\NatureDActe;
use App\Module\Demarche\Enum\SensDEchange;
use App\Module\Demarche\Enum\StatutDeDemarche;
use App\Module\Demarche\Exception\ErreurDeDemarche;
use Symfony\Component\HttpFoundation\Request;

/**
 * Le corps des requêtes, lu et validé en un seul endroit : chaque erreur se rend en `422`
 * sur la clé du champ fautif, toutes à la fois, pour que le formulaire les montre ensemble.
 */
final class LectureDesRequetes
{
    /** Un message de mairie ou une demande détaillée tient largement ; un fichier collé, non. */
    public const int TEXTE_MAX = 20000;

    /** Une démarche vise un acte : au-delà de quelques personnes, c'est une autre recherche. */
    public const int PERSONNES_MAX = 12;

    public function description(Request $requete): DescriptionDeDemarche
    {
        return $this->lireDescription($this->corps($requete));
    }

    public function suivi(Request $requete): SuiviDeDemarche
    {
        $corps = $this->corps($requete);
        $erreurs = [];

        $statut = $this->enumeration($corps, 'statut', StatutDeDemarche::class, $erreurs);
        $resultat = $this->texteFacultatif($corps, 'resultat', self::TEXTE_MAX, $erreurs);

        if ([] !== $erreurs) {
            throw ErreurDeDemarche::donneesInvalides($erreurs);
        }

        \assert($statut instanceof StatutDeDemarche);

        return new SuiviDeDemarche($statut, $resultat);
    }

    /**
     * La création : la description, **et le premier message**, facultatif. Une démarche
     * s'enregistre le plus souvent au moment où l'on envoie la demande, et son texte est
     * alors sous la main — le demander à part ferait un second geste que personne ne fait.
     *
     * `precedenteId`, facultatif : la démarche que celle-ci **reprend autrement** — après
     * un refus, par un autre canal. Sa visibilité se vérifie dans le service.
     *
     * @return array{0: DescriptionDeDemarche, 1: ?MessageSaisi, 2: ?int}
     */
    public function creation(Request $requete): array
    {
        $corps = $this->corps($requete);
        $description = $this->lireDescription($corps);

        $precedenteId = $corps['precedenteId'] ?? null;
        if (null !== $precedenteId && (!\is_int($precedenteId) || $precedenteId < 1)) {
            throw ErreurDeDemarche::donneesInvalides(['precedenteId' => 'Un identifiant de démarche est attendu.']);
        }

        $brut = $corps['premierMessage'] ?? null;

        if (null === $brut) {
            return [$description, null, $precedenteId];
        }

        if (!\is_array($brut)) {
            throw ErreurDeDemarche::donneesInvalides(['premierMessage' => 'Un objet est attendu.']);
        }

        /** @var array<string, mixed> $brut */
        return [$description, $this->lireMessage($brut, 'premierMessage.'), $precedenteId];
    }

    public function message(Request $requete): MessageSaisi
    {
        return $this->lireMessage($this->corps($requete), '');
    }

    /** @param array<string, mixed> $corps */
    private function lireDescription(array $corps): DescriptionDeDemarche
    {
        $erreurs = [];

        $nature = $this->enumeration($corps, 'nature', NatureDActe::class, $erreurs);
        $forme = $this->enumeration($corps, 'forme', FormeDActe::class, $erreurs);
        $intitule = $this->texteObligatoire($corps, 'intitule', 255, $erreurs);
        $commune = $this->texteFacultatif($corps, 'commune', 160, $erreurs);
        $dateDeLActe = $this->texteFacultatif($corps, 'dateDeLActe', 80, $erreurs);
        $destinataire = $this->texteObligatoire($corps, 'destinataire', 160, $erreurs);

        $personIds = $corps['personIds'] ?? null;
        if (!\is_array($personIds) || !array_is_list($personIds) || [] === $personIds
            || [] !== array_filter($personIds, static fn (mixed $id): bool => !\is_int($id) || $id < 1)) {
            $erreurs['personIds'] = 'Au moins une personne de l’arbre est attendue, par son identifiant.';
        } elseif (\count(array_unique($personIds)) > self::PERSONNES_MAX) {
            $erreurs['personIds'] = \sprintf('Une démarche concerne au plus %d personnes.', self::PERSONNES_MAX);
        }

        if ([] !== $erreurs) {
            throw ErreurDeDemarche::donneesInvalides($erreurs);
        }

        \assert($nature instanceof NatureDActe && $forme instanceof FormeDActe && \is_string($intitule) && \is_string($destinataire) && \is_array($personIds));

        /** @var list<int> $personIds */
        return new DescriptionDeDemarche(
            $nature,
            $forme,
            $intitule,
            $commune,
            $dateDeLActe,
            $destinataire,
            array_values(array_unique($personIds)),
        );
    }

    /** @param array<string, mixed> $corps */
    private function lireMessage(array $corps, string $prefixe): MessageSaisi
    {
        $erreurs = [];

        $sens = $this->enumeration($corps, 'sens', SensDEchange::class, $erreurs);
        $canal = $this->enumeration($corps, 'canal', CanalDEchange::class, $erreurs);

        $date = null;
        $brute = $corps['date'] ?? null;
        if (!\is_string($brute) || 1 !== preg_match('/^\d{4}-\d{2}-\d{2}$/', $brute)
            || false === ($date = \DateTimeImmutable::createFromFormat('!Y-m-d', $brute))
            || $date->format('Y-m-d') !== $brute) {
            $erreurs['date'] = 'Une date réelle au format AAAA-MM-JJ est attendue.';
            $date = null;
        } elseif ($date > new \DateTimeImmutable('tomorrow')) {
            $erreurs['date'] = 'Un message ne peut pas être daté du futur.';
        }

        $de = $this->texteFacultatif($corps, 'de', 255, $erreurs);
        $a = $this->texteFacultatif($corps, 'a', 255, $erreurs);
        $objet = $this->texteFacultatif($corps, 'objet', 255, $erreurs);
        $texte = $this->texteObligatoire($corps, 'texte', self::TEXTE_MAX, $erreurs);
        $reference = $this->texteFacultatif($corps, 'reference', 120, $erreurs);

        if ([] !== $erreurs) {
            $prefixees = [];
            foreach ($erreurs as $cle => $message) {
                $prefixees[$prefixe.$cle] = $message;
            }

            throw ErreurDeDemarche::donneesInvalides($prefixees);
        }

        \assert($sens instanceof SensDEchange && $canal instanceof CanalDEchange && $date instanceof \DateTimeImmutable && \is_string($texte));

        return new MessageSaisi($sens, $canal, $date, $de, $a, $objet, $texte, $reference);
    }

    /**
     * @template T of \BackedEnum
     *
     * @param array<string, mixed>  $corps
     * @param class-string<T>       $classe
     * @param array<string, string> $erreurs
     *
     * @return T|null
     */
    private function enumeration(array $corps, string $cle, string $classe, array &$erreurs): ?\BackedEnum
    {
        $valeur = $corps[$cle] ?? null;
        $cas = \is_string($valeur) ? $classe::tryFrom($valeur) : null;

        if (null === $cas) {
            $erreurs[$cle] = \sprintf(
                'Valeur attendue : %s.',
                implode(', ', array_map(static fn (\BackedEnum $c): string => (string) $c->value, $classe::cases())),
            );
        }

        return $cas;
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

    /**
     * @param array<string, mixed>  $corps
     * @param array<string, string> $erreurs
     */
    private function texteFacultatif(array $corps, string $cle, int $max, array &$erreurs): ?string
    {
        $valeur = $corps[$cle] ?? null;

        if (null === $valeur) {
            return null;
        }

        if (!\is_string($valeur) || mb_strlen(trim($valeur)) > $max) {
            $erreurs[$cle] = \sprintf('Un texte de %d caractères au plus est attendu.', $max);

            return null;
        }

        $valeur = trim($valeur);

        return '' === $valeur ? null : $valeur;
    }

    /** @return array<string, mixed> */
    private function corps(Request $requete): array
    {
        try {
            $corps = json_decode($requete->getContent(), true, 32, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw ErreurDeDemarche::donneesInvalides(['corps' => 'Un objet JSON est attendu.']);
        }

        if (!\is_array($corps) || array_is_list($corps) && [] !== $corps) {
            throw ErreurDeDemarche::donneesInvalides(['corps' => 'Un objet JSON est attendu.']);
        }

        /** @var array<string, mixed> $corps */
        return $corps;
    }
}
