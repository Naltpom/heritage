<?php

declare(strict_types=1);

namespace App\Module\Core\Enum;

/**
 * L'incertitude *qualitative* d'une date généalogique (§ 2.8 du modèle de données).
 *
 * Elle vit dans `Core` et non dans `Tree` : `Proposal` et `Media` l'instancient aussi,
 * et un noyau partagé qui s'installerait dans le plus gros des
 * modules ferait de `Tree` un second noyau (ADR 0002, règle 3).
 *
 * Les bornes `min`/`max` portent l'intervalle calendaire que la chaîne GEDCOM nomme ;
 * ce qualificatif porte le doute. `ABT 1934` et `1934` donnent le même intervalle mais
 * ne disent pas la même chose — c'est cette énumération qui les sépare.
 */
enum DateQualifier: string
{
    /** `11 MAR 1930` — jour connu. */
    case Exacte = 'exacte';
    /** `1930` — l'année seule. */
    case Annee = 'annee';
    /** `JAN 1920` — le mois. */
    case Mois = 'mois';
    /** `ABT 1934` — jamais élargi à ±n années : ce serait inventer de la donnée (§ 9.1). */
    case Environ = 'environ';
    /** `BEF 1920` — pas de borne basse. */
    case Avant = 'avant';
    /** `AFT 1920` — pas de borne haute. Absent du fichier actuel, présent dans la norme. */
    case Apres = 'apres';
    /** `BET 1901 AND 1909` — la vraie fourchette, celle qu'on n'aplatit jamais. */
    case Entre = 'entre';
    /** `FROM 15 JUN 1900` — début d'une période ouverte. */
    case Depuis = 'depuis';
    /** `TO 1920` — fin d'une période ouverte. Absent du fichier actuel. */
    case JusquA = 'jusqu_a';
    /** Aucune date. `min` et `max` sont alors nuls, et une contrainte `CHECK` l'impose. */
    case Inconnue = 'inconnue';

    public function label(): string
    {
        return match ($this) {
            self::Exacte => 'Date exacte',
            self::Annee => 'Année',
            self::Mois => 'Mois',
            self::Environ => 'Environ',
            self::Avant => 'Avant',
            self::Apres => 'Après',
            self::Entre => 'Entre deux dates',
            self::Depuis => 'À partir de',
            self::JusquA => "Jusqu'à",
            self::Inconnue => 'Inconnue',
        };
    }
}
