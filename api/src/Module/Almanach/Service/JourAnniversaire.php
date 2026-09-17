<?php

declare(strict_types=1);

namespace App\Module\Almanach\Service;

use App\Module\Core\Enum\DateQualifier;
use App\Module\Core\ValueObject\UncertainDate;

/**
 * ═══════════════════════════════════════════════════════════════════════════════
 *  LA PORTE DU CALENDRIER : quelles dates méritent un jour, et lesquelles n'en
 *  ont pas — le fichier le plus important de ce module.
 * ═══════════════════════════════════════════════════════════════════════════════.
 *
 * > **Seule une date `DateQualifier::Exacte` dont `isRange()` est faux produit un jour.**
 * > Tout le reste rend `null` et va grossir `resume.sansJour`.
 *
 * ── POURQUOI, ET CE QUE COÛTERAIT L'INVERSE ──────────────────────────────────────
 *
 * Une `UncertainDate` de qualificatif `annee` encode `1887` par **`min = 1887-01-01`** et
 * `max = 1887-12-31` (§ 0.5 du modèle de données ; la table de conversion est reprise au
 * § 14.3 du contrat d'API). Lire `min->format('n-j')` sur une telle date ne rend donc pas
 * « on ne sait pas » : il rend **le 1<sup>er</sup> janvier**.
 *
 * Mesuré sur l'arbre de travail : **119 des 620 dates** sont de qualificatif `annee`.
 * Les poser sur leur `min`, c'est écrire 119 anniversaires au 1<sup>er</sup> janvier —
 * et le Nouvel An deviendrait, de très loin, le jour le plus chargé de l'histoire de la
 * famille. Le défaut a les trois propriétés qui le rendent redoutable :
 *
 * - **il est plausible** — les familles ont des naissances le 1<sup>er</sup> janvier, et le
 *   chiffre n'a rien d'absurde en soi ;
 * - **il est silencieux** — aucune exception, aucun test rouge, une charge JSON bien formée ;
 * - **il est indétectable à l'usage** — personne ne connaît par cœur les 620 dates de son
 *   arbre, et le seul lecteur capable de dire « mon arrière-grand-mère n'est pas née le
 *   1<sup>er</sup> janvier » est celui qui possède l'acte.
 *
 * Le même défaut existe au **mois** : `JAN 1920` est encodé `min = 1920-01-01`, et les
 * dates de qualificatif `mois` — **5 lignes** — se poseraient toutes sur le 1<sup>er</sup>
 * de leur mois. Cinq, ce n'est rien ; c'est justement pourquoi il faut l'écrire : à cinq
 * lignes, personne ne le verra jamais, et la règle qui l'autorise resterait.
 *
 * Les autres qualificatifs se rejettent d'eux-mêmes : `environ` (`ABT 1934`) porte un
 * intervalle d'un an, `entre` une fourchette, `avant` / `après` / `depuis` / `jusqu_a` une
 * borne ouverte, `inconnue` rien du tout.
 *
 * ── LA DOUBLE GARDE, ET POURQUOI `isRange()` NE SUFFIT PAS SEUL ─────────────────
 *
 * On vérifie **les deux** : le qualificatif *et* `isRange()`. Ce n'est pas une ceinture
 * avec bretelles, les deux ferment des portes différentes :
 *
 * - `isRange()` seul laisserait passer une date `annee` dont `min === max` — une ligne
 *   dégénérée qu'un import approximatif produit, et qui vaudrait alors 1<sup>er</sup>
 *   janvier ;
 * - le qualificatif seul laisserait passer une date marquée `exacte` dont les bornes ont
 *   été élargies par une écriture ultérieure — l'incohérence existe en base tant qu'aucune
 *   contrainte ne l'interdit, et `ck_tree_person_birth_intervalle` ne couvre que `entre`.
 *
 * Dans les deux cas le repli est le même : **pas de jour**. Une date douteuse ne se pose
 * pas « au mieux » ; elle se compte.
 *
 * ── LE 29 FÉVRIER SORT TEL QUEL ──────────────────────────────────────────────────
 *
 * `{mois: 2, jour: 29}`, et **le serveur ne le déplace pas**. Ni au 28 février, ni au
 * 1<sup>er</sup> mars. C'est une date réelle, qui revient tous les quatre ans, et la
 * décision de ce qu'on en fait les trois autres années est un **choix d'affichage** : le
 * client sait s'il montre un mois, une année, ou « les anniversaires d'aujourd'hui », et
 * lui seul peut arbitrer. Un serveur qui déplacerait la date mentirait sur la donnée pour
 * simplifier un rendu qu'il ne voit pas — et le mensonge se propagerait à tout client
 * futur, y compris à celui qui voulait faire autrement.
 *
 * ── CE FICHIER NE MONTE PAS DANS `Core` ──────────────────────────────────────────
 *
 * Il ressemble à un utilitaire de date, et c'en est un. Il reste ici pour la raison que
 * l'en-tête d'`UncertainDate` donne : **il n'existe ni `getDate()`, ni `getYear()`, ni
 * `__toString()`** qui rendrait une date, parce que « si la méthode existait, quelqu'un
 * l'appellerait ». Un `Core\Service\JourDUneDate::depuis()` serait ce `getDay()` sous un
 * autre nom, à un import de distance de n'importe quel module. La règle appartient au
 * calendrier ; elle reste dans le calendrier.
 */
final class JourAnniversaire
{
    /**
     * Le jour du calendrier que porte cette date, ou `null` si elle n'en porte aucun.
     *
     * Statique et sans état : la règle ne dépend d'aucun réglage, d'aucune horloge et
     * d'aucun lecteur. C'est ce qui permet de l'éprouver sans noyau et de la relire d'un
     * seul écran.
     *
     * @return array{mois: int, jour: int}|null `mois` de 1 à 12, `jour` de 1 à 31
     */
    public static function depuis(UncertainDate $date): ?array
    {
        if (DateQualifier::Exacte !== $date->getQualifier()) {
            return null;
        }

        // Une date exacte dont les bornes divergent n'est pas un jour, quoi qu'en dise son
        // qualificatif. On ne « répare » pas : on s'abstient.
        if ($date->isRange()) {
            return null;
        }

        $borne = $date->getMin() ?? $date->getMax();

        if (null === $borne) {
            return null;
        }

        return [
            'mois' => (int) $borne->format('n'),
            // `j` et non `d` : l'entier 3, pas la chaîne « 03 ». La clé est comparée à des
            // entiers par le front et regroupée par le serveur ; une chaîne zéro-préfixée
            // se trierait alphabétiquement et casserait l'ordre au dixième jour du mois.
            'jour' => (int) $borne->format('j'),
        ];
    }
}
