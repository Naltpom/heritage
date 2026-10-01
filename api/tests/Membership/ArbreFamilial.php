<?php

declare(strict_types=1);

namespace App\Tests\Membership;

use App\Tests\Membership\Double\ArbreEnMemoire;

/**
 * Un arbre minuscule, mais **assez riche pour que les tests du cercle prouvent quelque
 * chose**. L'ADR 0006 le demande en toutes lettres : « les fixtures doivent contenir des
 * personnes vivantes réparties sur plusieurs branches, sinon ces tests ne prouvent rien. ».
 *
 * ```
 *  gén. -3               1 Aieul ══ 2 Aieule                    (décédés)
 *                                 │
 *                   ┌─────────────┴──────────────┐
 *  gén. -2   10 GrandPere ══ 11 GrandMere   20 GrandOncle ══ 21 GrandTante
 *                   │                             │
 *          ┌────────┴────────┐                    │
 *  gén. -1 30 Pere ══ 31 Mere   40 Tante     50 Cousin
 *                   │
 *  gén.  0     100 Ego ══ 101 Conjoint
 *                   │
 *  gén. +1      110 Enfant
 * ```
 *
 * Et, hors de tout lien avec l'ego : **900 Etranger**, vivant, et **901 AncetreEtranger**,
 * décédé. Ce sont eux qui font la différence entre « le cercle marche » et « le cercle
 * laisse tout passer » : sans une personne vivante hors cercle, un test de visibilité
 * passe même avec le filtre débranché. Depuis le 2026-10-01, le cercle ne ferme plus,
 * pour un membre, que les fiches **confidentielles** : un test qui veut voir la formule
 * décider pose ses personnes confidentielles par `poser([...])`.
 *
 * ## Les deux valeurs de `N` que les tests exercent
 *
 * `N = 3` : `A = {100, 30, 31, 10, 11, 1, 2}`, la descendance rajoute
 * `{20, 40, 50, 110}`, la passe des conjoints rajoute `{21, 101}`. Soit **l'arbre entier
 * sauf 900 et 901** — la démonstration en petit de l'asymétrie de l'ADR 0003 : on remonte
 * de trois générations, puis on redescend sans limite, donc le cousin issu de germain y
 * est.
 *
 * `N = 1` : `A = {100, 30, 31}`, la descendance rajoute `{110}`, la passe des conjoints
 * rajoute `{101}`. Soit **`{30, 31, 100, 101, 110}`** — et surtout **pas 40** : la tante
 * est fille de 10 et 11, qui ne sont plus dans `A`. C'est ce que le test du réglage
 * vérifie, et c'est ce qui prouve que `cercle.generations` n'est pas une constante.
 *
 * ## Le piège que cet arbre rend visible
 *
 * 101 Conjoint est **dans** le cercle, mais ses parents et ses éventuels frères et sœurs
 * n'y seraient pas : les conjoints sont ajoutés « en une seule passe, en dernier, et ne
 * propagent rien ». Un point fixe, ici, ferait entrer toute la famille Thym.
 */
final class ArbreFamilial
{
    public const int AIEUL = 1;
    public const int AIEULE = 2;
    public const int GRAND_PERE = 10;
    public const int GRAND_MERE = 11;
    public const int GRAND_ONCLE = 20;
    public const int GRAND_TANTE = 21;
    public const int PERE = 30;
    public const int MERE = 31;
    public const int TANTE = 40;
    public const int COUSIN = 50;
    public const int EGO = 100;
    public const int CONJOINT = 101;
    public const int ENFANT = 110;

    /**
     * Vivant, et à l'écart de toute parenté avec l'ego. Depuis la révision du 2026-10-01 de
     * l'ADR 0003, un membre le **voit** ; posé confidentiel (`poser([ETRANGER])`), il
     * redevient la preuve que le filtre filtre.
     */
    public const int ETRANGER = 900;

    /** Décédé, et tout aussi étranger : la preuve que le filtre ne filtre **que** les vivants. */
    public const int ANCETRE_ETRANGER = 901;

    /**
     * @param list<int> $confidentielles les fiches à confidentialité **forcée**
     *                                   (`force_privacy`). Depuis la révision du
     *                                   2026-10-01 de l'ADR 0003, ce sont les seules qu'un
     *                                   membre validé ne voit pas hors de son cercle : un
     *                                   test qui veut prouver que la formule du cercle
     *                                   décide encore de quelque chose les passe ici.
     */
    public static function poser(array $confidentielles = []): void
    {
        ArbreEnMemoire::reinitialiser();
        $confidentielles = array_flip($confidentielles);
        $ajouter = static function (int $id, string $prenoms, string $nom, bool $vivante, int $annee, string $etiquette) use ($confidentielles): void {
            ArbreEnMemoire::ajouterPersonne($id, $prenoms, $nom, $vivante, $annee, $etiquette, confidentielle: isset($confidentielles[$id]));
        };

        $ajouter(self::AIEUL, 'Azurite', 'Romarin', false, 1901, '1901');
        $ajouter(self::AIEULE, 'Silice', 'Cerfeuil', false, 1903, '1903');
        $ajouter(self::GRAND_PERE, 'Jaspe', 'Romarin', false, 1926, '1926');
        $ajouter(self::GRAND_MERE, 'Zircon', 'Tilleul', false, 1929, '1929');
        $ajouter(self::GRAND_ONCLE, 'Pyroxène', 'Romarin', true, 1934, '~1934');
        $ajouter(self::GRAND_TANTE, 'Galène', 'Verveine', true, 1936, '~1936');
        $ajouter(self::PERE, 'Pyrite', 'Romarin', true, 1956, '8.2.1956');
        $ajouter(self::MERE, 'Cobalt', 'Cassis', true, 1958, '1958');
        $ajouter(self::TANTE, 'Marbrée', 'Romarin', true, 1961, '1961');
        $ajouter(self::COUSIN, 'Marbre', 'Romarin', true, 1966, '1966');
        $ajouter(self::EGO, 'Natron', 'Romarin', true, 1980, '27.7.1980');
        $ajouter(self::CONJOINT, 'Spinel', 'Thym', true, 1982, '1982');
        $ajouter(self::ENFANT, 'Jet', 'Romarin', true, 2009, '26.9.2009');

        // Même nom, même prénom initial, **même date de naissance** que l'ego : c'est ce
        // qui rend le test de la recherche floue sérieux. Une recherche qui ne rendrait
        // qu'un seul candidat sur ces critères ne prouverait pas qu'elle sait en rendre
        // plusieurs — ni qu'elle sait les plafonner.
        $ajouter(self::ETRANGER, 'Onyx', 'Lointain', true, 1980, '27.7.1980');
        $ajouter(self::ANCETRE_ETRANGER, 'Jadéite', 'Lointain', false, 1891, '1891');

        ArbreEnMemoire::ajouterFamille([self::AIEUL, self::AIEULE], [self::GRAND_PERE, self::GRAND_ONCLE]);
        ArbreEnMemoire::ajouterFamille([self::GRAND_PERE, self::GRAND_MERE], [self::PERE, self::TANTE]);
        ArbreEnMemoire::ajouterFamille([self::GRAND_ONCLE, self::GRAND_TANTE], [self::COUSIN]);
        ArbreEnMemoire::ajouterFamille([self::PERE, self::MERE], [self::EGO]);
        ArbreEnMemoire::ajouterFamille([self::EGO, self::CONJOINT], [self::ENFANT]);
    }
}
