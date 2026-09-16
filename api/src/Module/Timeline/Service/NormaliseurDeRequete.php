<?php

declare(strict_types=1);

namespace App\Module\Timeline\Service;

use App\Module\Core\Enum\Branch;
use App\Module\Core\Exception\ValidationException;
use App\Module\Timeline\Enum\TimelineOrientation;
use Symfony\Component\HttpFoundation\Request;

/**
 * La porte d'entrée des trois routes du § 9 : elle lit, elle valide, elle rend `422`.
 *
 * ## Pourquoi tout est refusé ici plutôt qu'en base
 *
 * `ck_tml_preference_bande` borne déjà `band_years` entre 1 et 500. Le laisser parler
 * remonterait une violation de contrainte en `500` — c'est-à-dire en **panne** — là où
 * c'est une saisie invalide. Le `CHECK` est le filet, pas le contrôle ; c'est la règle que
 * `Media\Http\BlocDate` énonce déjà pour ses propres contraintes, et elle vaut ici mot
 * pour mot.
 *
 * La validation du `GET`, elle, n'a **aucun** filet en base : rien n'écrit. Un `bande=0`
 * non refusé ici ferait tourner la boucle de {@see CalculDesBandes} indéfiniment. Son
 * assertion est le second garde-fou ; celui-ci est le premier, et c'est lui qui donne au
 * client un message utile plutôt qu'un délai d'attente.
 *
 * ## Une clé inconnue est une erreur, pas un silence
 *
 * `PUT` refuse toute clé hors des quatre. Le § 9.2 fait du remplacement complet un choix
 * explicite — « quatre champs, tous obligatoires, aucune ambiguïté sur ce que “absent”
 * veut dire » —, et accepter une cinquième clé rendrait cette promesse fausse : un front
 * qui poste `bandeYears` au lieu de `bandYears` recevrait `200` et verrait son réglage
 * ignoré. Le `422` lui dit où est la faute de frappe. Même arbitrage, et même mot, que
 * `Media\Http\BlocDate`.
 */
final class NormaliseurDeRequete
{
    private const array CHAMPS_DE_PREFERENCE = ['bandYears', 'orientation', 'branches', 'showUncertainty'];

    /**
     * Les paramètres de `GET /api/chronologie`, résolus contre la préférence du compte.
     *
     * Les trois sont facultatifs. **Un paramètre présent mais vide est une erreur**, pas
     * une absence : `?bande=` est une intention exprimée sans valeur, et la traiter comme
     * « non fourni » masquerait un bogue du client. Seule l'absence complète retombe sur
     * la préférence.
     */
    public function parametresDeVue(Request $requete, PreferenceDeLecture $preference): RequeteDeChronologie
    {
        $champs = [];

        $bande = $this->bande($requete->query->get('bande'), $champs);
        $orientation = $this->orientation($requete->query->get('orientation'), $champs);
        $branches = $this->branches($requete->query->get('branches'), $champs);

        if ([] !== $champs) {
            throw new ValidationException($champs);
        }

        return new RequeteDeChronologie(
            $bande ?? $preference->bandYears,
            $orientation ?? $preference->orientation,
            $branches ?? $preference->branches,
            $preference->showUncertainty,
        );
    }

    /**
     * Le corps de `PUT /api/chronologie/preferences` — les quatre champs, tous exigés.
     *
     * Les quatre erreurs sont collectées **d'un coup** plutôt que rendues à la première :
     * un formulaire qui se corrige champ par champ demande quatre allers-retours au
     * lecteur pour la même saisie.
     */
    public function preferenceSoumise(Request $requete): PreferenceDeLecture
    {
        $corps = $this->corpsJson($requete);
        $champs = [];

        $inconnues = array_diff(array_keys($corps), self::CHAMPS_DE_PREFERENCE);

        if ([] !== $inconnues) {
            throw new ValidationException(['corps' => \sprintf('Clés inattendues : %s. Le remplacement est complet et ne porte que %s.', implode(', ', array_map(strval(...), $inconnues)), implode(', ', self::CHAMPS_DE_PREFERENCE))]);
        }

        $bande = $this->bandeObligatoire($corps['bandYears'] ?? null, $champs);
        $orientation = $this->orientationObligatoire($corps['orientation'] ?? null, $champs);
        $branches = $this->branchesObligatoires($corps['branches'] ?? null, $champs);
        $hachures = $this->booleenObligatoire($corps['showUncertainty'] ?? null, $champs);

        if ([] !== $champs) {
            throw new ValidationException($champs);
        }

        \assert(null !== $bande && null !== $orientation && null !== $branches && null !== $hachures);

        return new PreferenceDeLecture($bande, $orientation, $branches, $hachures);
    }

    // ── `GET` : facultatifs ──────────────────────────────────────────────────────

    /** @param array<string, string> $champs */
    private function bande(mixed $brut, array &$champs): ?int
    {
        if (null === $brut) {
            return null;
        }

        $entier = filter_var(\is_scalar($brut) ? (string) $brut : '', \FILTER_VALIDATE_INT);

        if (false === $entier || !ReglagesDeLaChronologie::bandeEstValide($entier)) {
            $champs['bande'] = \sprintf(
                'Entier attendu entre %d et %d.',
                ReglagesDeLaChronologie::BANDE_MIN,
                ReglagesDeLaChronologie::BANDE_MAX,
            );

            return null;
        }

        return $entier;
    }

    /** @param array<string, string> $champs */
    private function orientation(mixed $brut, array &$champs): ?TimelineOrientation
    {
        if (null === $brut) {
            return null;
        }

        $valeur = \is_string($brut) ? TimelineOrientation::tryFrom($brut) : null;

        if (null === $valeur) {
            $champs['orientation'] = self::attenduParmi(array_column(TimelineOrientation::cases(), 'value'));
        }

        return $valeur;
    }

    /**
     * @param array<string, string> $champs
     *
     * @return list<Branch>|null
     */
    private function branches(mixed $brut, array &$champs): ?array
    {
        if (null === $brut) {
            return null;
        }

        if (!\is_string($brut)) {
            $champs['branches'] = 'Liste de branches séparées par des virgules attendue.';

            return null;
        }

        // `array_filter` sur la chaîne vide : `explode(',', '')` rend `['']`, qui n'est ni
        // une branche ni une absence. `?branches=` tombe donc dans « aucune branche
        // valide » ci-dessous, et non dans un silence.
        $morceaux = array_values(array_filter(array_map(trim(...), explode(',', $brut)), static fn (string $m): bool => '' !== $m));

        return $this->versBranches($morceaux, 'branches', $champs);
    }

    // ── `PUT` : obligatoires ─────────────────────────────────────────────────────

    /** @param array<string, string> $champs */
    private function bandeObligatoire(mixed $brut, array &$champs): ?int
    {
        // Strictement un entier JSON : `"25"` est refusé. Le § 9.2 fixe un corps typé, et
        // accepter la chaîne ici obligerait à décider un jour si `"25ans"` vaut 25.
        if (!\is_int($brut) || !ReglagesDeLaChronologie::bandeEstValide($brut)) {
            $champs['bandYears'] = \sprintf(
                'Entier attendu entre %d et %d.',
                ReglagesDeLaChronologie::BANDE_MIN,
                ReglagesDeLaChronologie::BANDE_MAX,
            );

            return null;
        }

        return $brut;
    }

    /** @param array<string, string> $champs */
    private function orientationObligatoire(mixed $brut, array &$champs): ?TimelineOrientation
    {
        $valeur = \is_string($brut) ? TimelineOrientation::tryFrom($brut) : null;

        if (null === $valeur) {
            $champs['orientation'] = self::attenduParmi(array_column(TimelineOrientation::cases(), 'value'));
        }

        return $valeur;
    }

    /**
     * @param array<string, string> $champs
     *
     * @return list<Branch>|null
     */
    private function branchesObligatoires(mixed $brut, array &$champs): ?array
    {
        if (!\is_array($brut) || array_values($brut) !== $brut) {
            $champs['branches'] = 'Tableau de branches attendu.';

            return null;
        }

        /** @var list<mixed> $brut */
        $morceaux = [];

        foreach ($brut as $element) {
            if (!\is_string($element)) {
                $champs['branches'] = self::attenduParmi(array_column(Branch::cases(), 'value'));

                return null;
            }

            $morceaux[] = $element;
        }

        return $this->versBranches($morceaux, 'branches', $champs);
    }

    /** @param array<string, string> $champs */
    private function booleenObligatoire(mixed $brut, array &$champs): ?bool
    {
        if (!\is_bool($brut)) {
            $champs['showUncertainty'] = 'Booléen attendu.';

            return null;
        }

        return $brut;
    }

    // ── Communs ──────────────────────────────────────────────────────────────────

    /**
     * @param list<string>          $morceaux
     * @param array<string, string> $champs
     *
     * @return list<Branch>|null
     */
    private function versBranches(array $morceaux, string $cle, array &$champs): ?array
    {
        $branches = [];

        foreach ($morceaux as $morceau) {
            $branche = Branch::tryFrom($morceau);

            if (null === $branche) {
                $champs[$cle] = self::attenduParmi(array_column(Branch::cases(), 'value'));

                return null;
            }

            // Dédoublonnage : `pm,pm` désigne une branche, pas deux. Le filtre est un test
            // d'appartenance, un doublon n'y changerait rien — mais il ressortirait tel
            // quel dans la réponse, où il aurait l'air d'un bogue.
            if (!\in_array($branche, $branches, true)) {
                $branches[] = $branche;
            }
        }

        if ([] === $branches) {
            // Une liste vide n'affiche aucune barre. Ce n'est presque jamais une intention
            // — c'est un `?branches=` mal construit, ou un formulaire posté sans case
            // cochée — et le `422` le dit, là où un écran blanc laisserait chercher.
            $champs[$cle] = 'Au moins une branche est attendue.';

            return null;
        }

        return $branches;
    }

    /** @return array<string, mixed> */
    private function corpsJson(Request $requete): array
    {
        $contenu = $requete->getContent();

        if ('' === trim($contenu)) {
            throw new ValidationException(['corps' => 'Corps JSON attendu.']);
        }

        try {
            $decode = json_decode($contenu, true, 8, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ValidationException(['corps' => 'Corps illisible : JSON invalide.']);
        }

        if (!\is_array($decode) || array_is_list($decode)) {
            throw new ValidationException(['corps' => 'Objet JSON attendu.']);
        }

        /* @var array<string, mixed> $decode */
        return $decode;
    }

    /** @param list<string> $valeurs */
    private static function attenduParmi(array $valeurs): string
    {
        return \sprintf('Valeur attendue parmi : %s.', implode(', ', $valeurs));
    }
}
