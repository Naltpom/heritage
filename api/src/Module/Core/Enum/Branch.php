<?php

declare(strict_types=1);

namespace App\Module\Core\Enum;

/**
 * Les quarts d'ascendance, relatifs à la personne racine (§ 2.8 du modèle de données).
 *
 * Dans `Core` parce que `Timeline` l'instancie aussi (`tml_preference.branches`).
 *
 * `Branch` et `generation` sont les **deux coordonnées** de l'ordre du placement
 * partagé (ADR 0005) : c'est pourquoi la branche figure dans la liste blanche servie
 * même pour une personne vivante hors cercle (ADR 0003, amendé — arbitrage M). Un nœud
 * livré sans sa branche n'est ni posable ni colorable par un canevas qui reçoit déjà sa
 * position.
 */
enum Branch: string
{
    /** Branche paternelle du père. */
    case Pp = 'pp';
    /** Branche maternelle du père. */
    case Pm = 'pm';
    /** Branche paternelle de la mère. */
    case Mp = 'mp';
    /** Branche maternelle de la mère. */
    case Mm = 'mm';
    /** Le tronc : la lignée directe et la fratrie de la racine. */
    case Tr = 'tr';
    /**
     * La belle-famille : la famille du conjoint d'un membre du tronc.
     *
     * **Ce n'est pas un cinquième quart, et ce n'est pas « le reste ».** Les quatre quarts
     * sont des ascendances ; celle-ci est une famille entière que rien ne relie au sang du
     * lecteur, et qui entre dans l'arbre par une alliance. Elle existe parce qu'elle est
     * nombreuse : pour un lecteur autre que la racine, elle pèse **176 personnes sur 468**
     * du graphe servi — plus que n'importe lequel de ses quatre quarts.
     *
     * Avant elle, ces personnes n'avaient pas de branche du tout, et le sérialiseur leur
     * servait celle **de la racine** par un repli silencieux ; « maternelle du père »
     * désignait alors le côté maternel de quelqu'un d'autre.
     *
     * ⚠ **UNE SEULE CLÉ AUJOURD'HUI, QUATRE DEMAIN — et ce n'est pas un choix définitif.**
     * La spécification du commanditaire demande que cette population soit découpée comme
     * l'est la sienne : quatre quarts d'ascendance du conjoint, `pp'`, `pm'`, `mp'`, `mm'`.
     * Le découpage sera **additif** — il remplacera cette clé par quatre, sans rien changer
     * à ce qui la peuple ni à la vue de la racine, où elle reste vide.
     *
     * Si elle est unique pour l'instant, c'est que le cas ne se produit encore chez
     * personne : aucun compte n'obtient de point de vue non-racine tant que le rattachement
     * retombe sur la racine. Ne pas lire cette unicité comme une décision de fond.
     */
    case Bf = 'bf';

    public function label(): string
    {
        return match ($this) {
            self::Pp => 'Branche paternelle du père',
            self::Pm => 'Branche maternelle du père',
            self::Mp => 'Branche paternelle de la mère',
            self::Mm => 'Branche maternelle de la mère',
            self::Tr => 'Tronc',
            self::Bf => 'Belle-famille',
        };
    }
}
