<?php

declare(strict_types=1);

namespace App\Module\Evenement\Service;

/**
 * « du samedi 17 au dimanche 18 juillet 2027 » — la période d'un événement, en toutes
 * lettres, pour le texte des notifications.
 *
 * Écrite à la main plutôt que confiée à `IntlDateFormatter` : `intl` formate une **date**,
 * pas une période, et la mise en commun du mois et de l'année (« du 17 au 18 juillet » et
 * non « du 17 juillet 2027 au 18 juillet 2027 ») serait à refaire par-dessus de toute façon.
 * Douze mois et sept jours ne justifient pas une dépendance de plus.
 *
 * Le texte se compose **à l'écriture** de la notification (§ 16.2 du contrat) : il ne bouge
 * plus ensuite, même si l'organisateur déplace l'événement — la notification dit ce qui
 * était annoncé quand elle est partie. L'écran, lui, relit les dates.
 */
final class PeriodeEnFrancais
{
    private const array JOURS = ['dimanche', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi'];

    private const array MOIS = [
        'janvier', 'février', 'mars', 'avril', 'mai', 'juin',
        'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre',
    ];

    public static function de(\DateTimeImmutable $debut, \DateTimeImmutable $fin): string
    {
        if ($debut->format('Y-m-d') === $fin->format('Y-m-d')) {
            return 'le '.self::jour($debut).' '.self::mois($debut).' '.$debut->format('Y');
        }

        if ($debut->format('Y') !== $fin->format('Y')) {
            return \sprintf(
                'du %s %s %s au %s %s %s',
                self::jour($debut), self::mois($debut), $debut->format('Y'),
                self::jour($fin), self::mois($fin), $fin->format('Y'),
            );
        }

        if ($debut->format('m') !== $fin->format('m')) {
            return \sprintf(
                'du %s %s au %s %s %s',
                self::jour($debut), self::mois($debut),
                self::jour($fin), self::mois($fin), $fin->format('Y'),
            );
        }

        return \sprintf('du %s au %s %s %s', self::jour($debut), self::jour($fin), self::mois($fin), $fin->format('Y'));
    }

    /** « samedi 17 », « mardi 1er ». */
    private static function jour(\DateTimeImmutable $date): string
    {
        $quantieme = (int) $date->format('j');

        return self::JOURS[(int) $date->format('w')].' '.(1 === $quantieme ? '1er' : (string) $quantieme);
    }

    private static function mois(\DateTimeImmutable $date): string
    {
        return self::MOIS[(int) $date->format('n') - 1];
    }
}
