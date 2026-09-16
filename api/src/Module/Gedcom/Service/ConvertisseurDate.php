<?php

declare(strict_types=1);

namespace App\Module\Gedcom\Service;

use App\Module\Core\Enum\DateQualifier;
use App\Module\Core\ValueObject\UncertainDate;
use App\Module\Gedcom\Dto\DateAnalysee;

/**
 * Une chaîne `DATE` du GEDCOM vers le bloc date du § 0.5 du modèle de données.
 *
 * **C'est la pièce où l'on perd les fourchettes si on se trompe**, et c'est pour elle
 * que le § 9.1 existe. La règle tient en une phrase : une chaîne nomme un **intervalle
 * calendaire**, jamais un point. `1930` couvre l'année entière, `JAN 1920` le mois
 * entier, `BET 1901 AND 1909` neuf années. Le qualificatif, lui, porte le doute :
 * `ABT 1934` et `1934` donnent le même intervalle et ne disent pas la même chose.
 *
 * Trois garde-fous existent ailleurs et convergent ici : {@see UncertainDate} n'expose
 * ni `getDate()` ni `getYear()`, aucune colonne `*_date` n'existe pour accueillir une
 * date ferme, et `ck_<table>_<x>_intervalle` refuse en base `qualifier = 'entre'` avec
 * des bornes égales. Ce convertisseur **respecte** cette dernière contrainte au lieu de
 * la contourner : une fourchette dégénérée en point est ramenée à la forme simple qui
 * la décrit, et signalée.
 *
 * On ne jette **jamais** la chaîne d'origine : ce qu'on n'a pas su lire part en `raw`
 * avec `qualifier = 'inconnue'`, et la provenance reste lisible.
 */
final class ConvertisseurDate
{
    private const array MOIS = [
        'JAN' => 1, 'FEB' => 2, 'MAR' => 3, 'APR' => 4, 'MAY' => 5, 'JUN' => 6,
        'JUL' => 7, 'AUG' => 8, 'SEP' => 9, 'OCT' => 10, 'NOV' => 11, 'DEC' => 12,
    ];

    public function convertir(?string $brut, ?string $lieu = null): DateAnalysee
    {
        $brut = null === $brut ? null : trim($brut);
        $lieu = null === $lieu ? null : (trim($lieu) ?: null);

        if (null === $brut || '' === $brut) {
            return new DateAnalysee(UncertainDate::inconnue($lieu));
        }

        // Le préfixe de calendrier de la norme (`@#DGREGORIAN@ 12 DEC 1920`). Absent du
        // fichier actuel, présent chez d'autres exporteurs, et sa présence ferait
        // échouer toutes les formes qui suivent.
        $texte = trim((string) preg_replace('/^@#D[A-Z_]+@\s*/', '', $brut));
        $majuscules = mb_strtoupper($texte, 'UTF-8');

        $resultat = $this->analyser($brut, $majuscules, $lieu);

        if (null !== $resultat) {
            return $resultat;
        }

        return new DateAnalysee(
            new UncertainDate($brut, DateQualifier::Inconnue, null, null, $lieu),
            \sprintf('Forme de date non couverte par le § 0.5 du modèle : « %s ». Conservée verbatim.', $brut),
        );
    }

    private function analyser(string $brut, string $majuscules, ?string $lieu): ?DateAnalysee
    {
        // `BET … AND …` d'abord : c'est la seule forme à deux bornes, et son « AND »
        // contient des sous-chaînes qui ressemblent aux autres préfixes.
        if (1 === preg_match('/^BET(?:WEEN)? (.+) AND (.+)$/', $majuscules, $trouve)) {
            return $this->fourchette($brut, $trouve[1], $trouve[2], $lieu);
        }

        if (1 === preg_match('/^FROM (.+) TO (.+)$/', $majuscules, $trouve)) {
            // Une période bornée des deux côtés décrit le même intervalle qu'une
            // fourchette. Absente du fichier actuel ; la traiter coûte deux lignes.
            return $this->fourchette($brut, $trouve[1], $trouve[2], $lieu);
        }

        foreach ([
            'ABT ' => DateQualifier::Environ,
            'ABOUT ' => DateQualifier::Environ,
            'CAL ' => DateQualifier::Environ,
            'EST ' => DateQualifier::Environ,
            'BEF ' => DateQualifier::Avant,
            'BEFORE ' => DateQualifier::Avant,
            'AFT ' => DateQualifier::Apres,
            'AFTER ' => DateQualifier::Apres,
            'FROM ' => DateQualifier::Depuis,
            'TO ' => DateQualifier::JusquA,
        ] as $prefixe => $qualificatif) {
            if (!str_starts_with($majuscules, $prefixe)) {
                continue;
            }

            $bornes = $this->bornes(substr($majuscules, \strlen($prefixe)));

            if (null === $bornes) {
                return null;
            }

            return new DateAnalysee(match ($qualificatif) {
                // `BEF 1920` se stocke `max = 1919-12-31` : la borne haute est
                // **exclue** par la chaîne, donc incluse à la veille. C'est ce que
                // `UncertainDate::format()` sait défaire pour rendre « avant 1920 ».
                DateQualifier::Avant => new UncertainDate($brut, $qualificatif, null, $bornes['min']->modify('-1 day'), $lieu),
                DateQualifier::Apres => new UncertainDate($brut, $qualificatif, $bornes['max']->modify('+1 day'), null, $lieu),
                DateQualifier::Depuis => new UncertainDate($brut, $qualificatif, $bornes['min'], null, $lieu),
                DateQualifier::JusquA => new UncertainDate($brut, $qualificatif, null, $bornes['max'], $lieu),
                default => new UncertainDate($brut, $qualificatif, $bornes['min'], $bornes['max'], $lieu),
            });
        }

        $bornes = $this->bornes($majuscules);

        if (null === $bornes) {
            return null;
        }

        return new DateAnalysee(new UncertainDate(
            $brut,
            match ($bornes['grain']) {
                'jour' => DateQualifier::Exacte,
                'mois' => DateQualifier::Mois,
                default => DateQualifier::Annee,
            },
            $bornes['min'],
            $bornes['max'],
            $lieu,
        ));
    }

    private function fourchette(string $brut, string $gauche, string $droite, ?string $lieu): ?DateAnalysee
    {
        $debut = $this->bornes(trim($gauche));
        $fin = $this->bornes(trim($droite));

        if (null === $debut || null === $fin) {
            return null;
        }

        $min = $debut['min'];
        $max = $fin['max'];

        if ($min < $max) {
            return new DateAnalysee(new UncertainDate($brut, DateQualifier::Entre, $min, $max, $lieu));
        }

        // Une fourchette dont les deux bornes tombent au même endroit n'est pas une
        // fourchette : `ck_<table>_<x>_intervalle` la refuserait en base, et elle aurait
        // raison — « entre le 3 mai et le 3 mai » ne veut rien dire. On la ramène à la
        // forme simple qu'elle décrit vraiment, et on le dit. Se battre contre la
        // contrainte serait ici le vrai bogue (§ 9.1).
        $grain = $debut['grain'];

        return new DateAnalysee(
            new UncertainDate(
                $brut,
                match ($grain) {
                    'jour' => DateQualifier::Exacte,
                    'mois' => DateQualifier::Mois,
                    default => DateQualifier::Annee,
                },
                $min,
                max($min, $max),
                $lieu,
            ),
            \sprintf('Fourchette « %s » dont les deux bornes se recouvrent : ramenée à une date simple.', $brut),
        );
    }

    /**
     * Les deux bornes **inclusives** de l'intervalle que nomme un jeton simple, et sa
     * granularité.
     *
     * @return array{min: \DateTimeImmutable, max: \DateTimeImmutable, grain: 'jour'|'mois'|'annee'}|null
     */
    private function bornes(string $jeton): ?array
    {
        $jeton = trim($jeton);

        if (1 === preg_match('/^(\d{1,2}) ([A-Z]{3,4}) (\d{1,4})$/', $jeton, $trouve)) {
            $mois = self::MOIS[$trouve[2]] ?? null;

            if (null === $mois) {
                return null;
            }

            $jour = $this->jour((int) $trouve[3], $mois, (int) $trouve[1]);

            return null === $jour ? null : ['min' => $jour, 'max' => $jour, 'grain' => 'jour'];
        }

        if (1 === preg_match('/^([A-Z]{3,4}) (\d{1,4})$/', $jeton, $trouve)) {
            $mois = self::MOIS[$trouve[1]] ?? null;

            if (null === $mois) {
                return null;
            }

            $premier = $this->jour((int) $trouve[2], $mois, 1);

            return null === $premier
                ? null
                : ['min' => $premier, 'max' => $premier->modify('last day of this month'), 'grain' => 'mois'];
        }

        if (1 === preg_match('/^(\d{1,4})$/', $jeton)) {
            $premier = $this->jour((int) $jeton, 1, 1);

            return null === $premier
                ? null
                : ['min' => $premier, 'max' => $premier->setDate((int) $jeton, 12, 31), 'grain' => 'annee'];
        }

        return null;
    }

    /**
     * Une date calendaire, ou `null` si elle n'existe pas.
     *
     * Le contrôle d'aller-retour n'est pas de la superstition : `createFromFormat`
     * accepte `31 FEB 1900` et rend le 3 mars. Un 31 février recopié d'un acte mal lu
     * deviendrait une date parfaitement plausible et fausse.
     */
    private function jour(int $annee, int $mois, int $jour): ?\DateTimeImmutable
    {
        if ($annee < 1 || $mois < 1 || $mois > 12 || $jour < 1 || $jour > 31) {
            return null;
        }

        $rendu = \sprintf('%04d-%02d-%02d', $annee, $mois, $jour);
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $rendu);

        if (false === $date || $date->format('Y-m-d') !== $rendu) {
            return null;
        }

        return $date;
    }
}
