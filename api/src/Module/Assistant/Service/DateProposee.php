<?php

declare(strict_types=1);

namespace App\Module\Assistant\Service;

/**
 * Une date telle que le modèle la décrit → le **bloc date à cinq clés** d'une demande
 * (`BlocDateOp` : `raw`, `qualifier`, `min`, `max`, `place`).
 *
 * C'est la traduction de `app/src/components/proposition/dates.ts::composerBlocDate()`,
 * celle du formulaire — et il faut qu'elle le soit, au caractère près : une demande de
 * l'assistant et une demande du formulaire décrivant la même date doivent porter **le
 * même bloc**, sans quoi la détection de doublon (§ 9.5) et la comparaison `JSONB` de la
 * détection de conflit (§ 9.6) les verraient différentes.
 *
 * ── Les règles reprises, et ce qu'elles empêchent ───────────────────────────────
 *
 * - **La précision de la source décide.** « 1921 » donne `annee`, « 1921-03 » donne
 *   `mois`, « 1921-03-03 » donne `exacte`. Si le modèle annonce une précision plus
 *   grossière que ce qu'il écrit (`annee` avec un jour), on retient la plus grossière :
 *   un jour qu'il n'annonce pas est un jour qu'il a pu inventer.
 * - **`ABT` n'est jamais élargi** : « vers 1921 » reste 1921-01-01 → 1921-12-31. C'est
 *   `qualifier` qui porte l'incertitude, jamais `min`/`max` (arbitrage W).
 * - **`BEF` et `AFT` excluent leur borne** : « avant 1920 » s'arrête au 31 décembre 1919.
 * - **Une fourchette exige deux périodes distinctes**, la seconde après la première ;
 *   sinon, pas de date plutôt qu'une date fausse.
 *
 * Une date inexploitable rend `null` : l'appelant la **laisse de côté** plutôt que de
 * poser un bloc approximatif sur une fiche.
 */
final class DateProposee
{
    private const array MOIS_GEDCOM = [1 => 'JAN', 'FEB', 'MAR', 'APR', 'MAY', 'JUN', 'JUL', 'AUG', 'SEP', 'OCT', 'NOV', 'DEC'];

    private const array RANG_DE_PRECISION = ['annee' => 1, 'mois' => 2, 'jour' => 3];

    /**
     * @return array{raw: string|null, qualifier: string, min: string|null, max: string|null, place: string|null}|null
     */
    public static function versBloc(mixed $date): ?array
    {
        if (!\is_array($date)) {
            return null;
        }

        $lieu = \is_string($date['lieu'] ?? null) && '' !== trim($date['lieu']) ? mb_substr(trim($date['lieu']), 0, 250) : null;
        $qualificatif = \is_string($date['qualificatif'] ?? null) ? $date['qualificatif'] : 'exacte';
        $brute = \is_string($date['date'] ?? null) ? trim($date['date']) : '';

        if ('' === $brute) {
            // Un lieu sans date est une information — « inhumé à Brest » — et le modèle
            // de données l'admet (`qualifier: inconnue`, aucune borne).
            return null === $lieu ? null : ['raw' => null, 'qualifier' => 'inconnue', 'min' => null, 'max' => null, 'place' => $lieu];
        }

        $borne = self::borne($brute);

        if (null === $borne) {
            return null;
        }

        // La précision annoncée ne peut qu'être plus grossière que l'écrite.
        $annoncee = match ($qualificatif) {
            'annee' => 'annee',
            'mois' => 'mois',
            default => 'jour',
        };
        if (self::RANG_DE_PRECISION[$annoncee] < self::RANG_DE_PRECISION[$borne['precision']]) {
            $borne = self::tronquer($borne, $annoncee);
        }

        [$debut, $fin] = self::periode($borne);
        $gedcom = self::gedcom($borne);

        return match ($qualificatif) {
            'environ' => ['raw' => 'ABT '.$gedcom, 'qualifier' => 'environ', 'min' => $debut, 'max' => $fin, 'place' => $lieu],
            'avant' => ['raw' => 'BEF '.$gedcom, 'qualifier' => 'avant', 'min' => null, 'max' => self::decaler($debut, -1), 'place' => $lieu],
            'apres' => ['raw' => 'AFT '.$gedcom, 'qualifier' => 'apres', 'min' => self::decaler($fin, 1), 'max' => null, 'place' => $lieu],
            'entre' => self::fourchette($borne, $date['date_fin'] ?? null, $lieu),
            default => [
                'raw' => $gedcom,
                'qualifier' => match ($borne['precision']) {
                    'annee' => 'annee',
                    'mois' => 'mois',
                    default => 'exacte',
                },
                'min' => $debut,
                'max' => $fin,
                'place' => $lieu,
            ],
        };
    }

    /**
     * Le bloc porte-t-il une date, au sens de `ck_tree_person_deces_coherent` ?
     *
     * @param array{raw: string|null, qualifier: string, min: string|null, max: string|null, place: string|null}|null $bloc
     */
    public static function porteUneDate(?array $bloc): bool
    {
        return null !== $bloc && (null !== $bloc['raw'] || null !== $bloc['min'] || null !== $bloc['max']);
    }

    /**
     * @param array{annee: int, mois: int|null, jour: int|null, precision: string} $premiere
     *
     * @return array{raw: string, qualifier: string, min: string, max: string, place: string|null}|null
     */
    private static function fourchette(array $premiere, mixed $fin, ?string $lieu): ?array
    {
        $seconde = \is_string($fin) ? self::borne(trim($fin)) : null;

        if (null === $seconde) {
            return null;
        }

        [$min] = self::periode($premiere);
        [$debutSeconde, $max] = self::periode($seconde);

        // Même sévérité que le formulaire : `BET 1901 AND 1901` n'est pas une fourchette,
        // c'est une date écrite deux fois.
        if ($debutSeconde <= $min) {
            return null;
        }

        return [
            'raw' => \sprintf('BET %s AND %s', self::gedcom($premiere), self::gedcom($seconde)),
            'qualifier' => 'entre',
            'min' => $min,
            'max' => $max,
            'place' => $lieu,
        ];
    }

    /**
     * @return array{annee: int, mois: int|null, jour: int|null, precision: string}|null
     */
    private static function borne(string $texte): ?array
    {
        if (1 !== preg_match('/^(\d{4})(?:-(\d{1,2})(?:-(\d{1,2}))?)?$/', $texte, $m)) {
            return null;
        }

        $annee = (int) $m[1];
        // PHP omet les groupes non capturés **en fin de motif** : un mois absent n'a
        // donc pas de clé, et un jour absent non plus.
        $mois = isset($m[2]) ? (int) $m[2] : null;
        $jour = isset($m[3]) ? (int) $m[3] : null;

        // Un arbre généalogique remonte loin, mais pas avant l'an 1000, et ne documente
        // pas l'avenir.
        if ($annee < 1000 || $annee > (int) date('Y') + 1) {
            return null;
        }

        if (null !== $mois && ($mois < 1 || $mois > 12)) {
            return null;
        }

        if (null !== $jour && (null === $mois || !checkdate($mois, $jour, $annee))) {
            return null;
        }

        return [
            'annee' => $annee,
            'mois' => $mois,
            'jour' => $jour,
            'precision' => null !== $jour ? 'jour' : (null !== $mois ? 'mois' : 'annee'),
        ];
    }

    /**
     * @param array{annee: int, mois: int|null, jour: int|null, precision: string} $borne
     *
     * @return array{annee: int, mois: int|null, jour: int|null, precision: string}
     */
    private static function tronquer(array $borne, string $precision): array
    {
        return [
            'annee' => $borne['annee'],
            'mois' => 'annee' === $precision ? null : $borne['mois'],
            'jour' => null,
            'precision' => $precision,
        ];
    }

    /**
     * @param array{annee: int, mois: int|null, jour: int|null, precision: string} $borne
     *
     * @return array{string, string} le premier et le dernier jour de la période, inclus
     */
    private static function periode(array $borne): array
    {
        $a = $borne['annee'];

        return match ($borne['precision']) {
            'annee' => [\sprintf('%04d-01-01', $a), \sprintf('%04d-12-31', $a)],
            'mois' => [
                \sprintf('%04d-%02d-01', $a, $borne['mois']),
                // `t` et non `cal_days_in_month()` : l'extension `calendar` n'est pas
                // dans l'image, et un 29 février se calcule très bien sans elle.
                (new \DateTimeImmutable(\sprintf('%04d-%02d-01', $a, $borne['mois'])))->format('Y-m-t'),
            ],
            default => [
                \sprintf('%04d-%02d-%02d', $a, $borne['mois'], $borne['jour']),
                \sprintf('%04d-%02d-%02d', $a, $borne['mois'], $borne['jour']),
            ],
        };
    }

    /** @param array{annee: int, mois: int|null, jour: int|null, precision: string} $borne */
    private static function gedcom(array $borne): string
    {
        return match ($borne['precision']) {
            'annee' => (string) $borne['annee'],
            'mois' => self::MOIS_GEDCOM[(int) $borne['mois']].' '.$borne['annee'],
            default => $borne['jour'].' '.self::MOIS_GEDCOM[(int) $borne['mois']].' '.$borne['annee'],
        };
    }

    private static function decaler(string $jour, int $jours): string
    {
        return (new \DateTimeImmutable($jour))->modify(\sprintf('%+d day', $jours))->format('Y-m-d');
    }
}
