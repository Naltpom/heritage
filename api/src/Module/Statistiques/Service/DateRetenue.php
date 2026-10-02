<?php

declare(strict_types=1);

namespace App\Module\Statistiques\Service;

use App\Module\Core\Enum\DateQualifier;
use App\Module\Core\ValueObject\UncertainDate;

/**
 * ═══════════════════════════════════════════════════════════════════════════════
 *  LA RÈGLE DES DATES DES STATISTIQUES — décision du commanditaire du 02/10/2026.
 * ═══════════════════════════════════════════════════════════════════════════════.
 *
 * > **Une date floue ou inconnue ne compte pas. Une date exacte compte. Une année seule
 * > compte pour les calculs à l'année, pas pour ce qui demande le mois.**
 *
 * Traduite sur les qualificatifs d'`UncertainDate` :
 *
 * | qualificatif | exemple GEDCOM | année | mois | jour |
 * |---|---|---|---|---|
 * | `exacte`   | `14 MAR 1850`        | oui | oui | oui |
 * | `mois`     | `MAR 1850`           | oui | oui | non |
 * | `annee`    | `1850`               | oui | non | non |
 * | `environ`, `avant`, `apres`, `entre`, `depuis`, `jusqu_a` | `ABT 1850`, `BEF 1850`… | **non** | **non** | **non** |
 * | `inconnue` | —                    | non | non | non |
 *
 * Le cas `mois` (« mars 1850 ») n'était pas nommé dans la décision : ce n'est **pas une
 * date floue** — l'année et le mois sont sûrs, seul le jour manque. Elle compte donc à
 * l'année **et** au mois (mois des naissances), jamais au jour. C'est le prolongement
 * direct de la règle, et c'est écrit ici pour qu'on ne le redécide pas ailleurs.
 *
 * Une date `environ` dont `min` et `max` tombent dans la même année n'est **pas** promue :
 * « vers 1850 » dit justement qu'on n'en est pas sûr, et l'aplatir serait l'erreur que
 * l'en-tête d'`UncertainDate` interdit.
 *
 * ── Les âges ──────────────────────────────────────────────────────────────────
 *
 * Deux dates exactes : l'âge révolu, au jour près. Dès qu'une des deux n'a que son année
 * (ou son mois) : la différence des années, corrigée du mois quand les deux en ont un.
 * L'erreur est d'au plus un an, et c'est précisément ce qu'« une année seule compte pour
 * les âges en années » accepte. Un âge négatif est une incohérence de saisie : il ne
 * compte pas (`null`), plutôt que d'être tronqué à zéro.
 *
 * Cette classe est pure et statique : aucune base, aucune horloge — « aujourd'hui » se
 * passe en paramètre, pour que les tests ne dépendent pas du jour où ils tournent.
 */
final class DateRetenue
{
    /** L'année, si la date compte à l'année ; sinon `null`. */
    public static function annee(UncertainDate $date): ?int
    {
        $borne = self::borne($date, [DateQualifier::Exacte, DateQualifier::Mois, DateQualifier::Annee]);

        return null === $borne ? null : (int) $borne->format('Y');
    }

    /** Le mois (1 à 12), si la date compte au mois ; sinon `null`. Jamais pour une année seule. */
    public static function mois(UncertainDate $date): ?int
    {
        $borne = self::borne($date, [DateQualifier::Exacte, DateQualifier::Mois]);

        return null === $borne ? null : (int) $borne->format('n');
    }

    /** Le jour, si la date est exacte ; sinon `null`. */
    public static function jour(UncertainDate $date): ?\DateTimeImmutable
    {
        return self::borne($date, [DateQualifier::Exacte]);
    }

    /** Le siècle d'une année : 1850 → 19, 1900 → 19, 1901 → 20. */
    public static function siecle(int $annee): int
    {
        return intdiv($annee - 1, 100) + 1;
    }

    /**
     * L'âge révolu, en années, entre deux dates qui comptent — `null` sinon.
     *
     * `$fin` peut être un instant (`DateTimeImmutable`) : c'est « aujourd'hui », pour l'âge
     * atteint par une personne vivante, et il vaut une date exacte.
     */
    public static function ageEnAnnees(UncertainDate $debut, UncertainDate|\DateTimeImmutable $fin): ?int
    {
        $anneeDebut = self::annee($debut);

        if (null === $anneeDebut) {
            return null;
        }

        if ($fin instanceof \DateTimeImmutable) {
            $anneeFin = (int) $fin->format('Y');
            $moisFin = (int) $fin->format('n');
            $jourFin = $fin;
        } else {
            $anneeFin = self::annee($fin);

            if (null === $anneeFin) {
                return null;
            }

            $moisFin = self::mois($fin);
            $jourFin = self::jour($fin);
        }

        $jourDebut = self::jour($debut);

        // Deux jours connus : l'âge révolu exact.
        if (null !== $jourDebut && null !== $jourFin) {
            if ($jourFin < $jourDebut) {
                return null;
            }

            return $jourDebut->diff($jourFin)->y;
        }

        $age = $anneeFin - $anneeDebut;
        $moisDebut = self::mois($debut);

        // Deux mois connus : l'anniversaire n'est pas encore passé si le mois de fin
        // précède celui du début. À mois égal, on ne sait pas : on garde la différence.
        if (null !== $moisDebut && null !== $moisFin && $moisFin < $moisDebut) {
            --$age;
        }

        return $age >= 0 ? $age : null;
    }

    /**
     * Compare deux dates qui comptent, pour trouver « la plus ancienne » : par année, puis
     * par mois, puis par jour quand les deux en ont. `null` si l'une ne compte pas.
     */
    public static function comparer(UncertainDate $a, UncertainDate $b): ?int
    {
        $anneeA = self::annee($a);
        $anneeB = self::annee($b);

        if (null === $anneeA || null === $anneeB) {
            return null;
        }

        if ($anneeA !== $anneeB) {
            return $anneeA <=> $anneeB;
        }

        $moisA = self::mois($a);
        $moisB = self::mois($b);

        if (null !== $moisA && null !== $moisB && $moisA !== $moisB) {
            return $moisA <=> $moisB;
        }

        $jourA = self::jour($a);
        $jourB = self::jour($b);

        if (null !== $jourA && null !== $jourB) {
            return $jourA <=> $jourB;
        }

        return 0;
    }

    /**
     * La borne d'une date si son qualificatif est admis. `min` d'abord : pour une date
     * exacte, à l'année ou au mois, `min` porte le premier jour de la période — l'année et
     * le mois y sont justes.
     *
     * @param list<DateQualifier> $admis
     */
    private static function borne(UncertainDate $date, array $admis): ?\DateTimeImmutable
    {
        if (!\in_array($date->getQualifier(), $admis, true)) {
            return null;
        }

        return $date->getMin() ?? $date->getMax();
    }
}
