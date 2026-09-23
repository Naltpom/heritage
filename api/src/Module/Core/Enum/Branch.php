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
 *
 * ── LES TREIZE RÉGIONS, ET CE QUI EST ICI ───────────────────────────────────────
 *
 * La spécification du commanditaire compte **treize** blocs. Cette énumération n'en porte
 * que **neuf**, et ce n'est pas un manque : les quatre autres sont les **enveloppes** `p`
 * (= `pp` ∪ `pm`), `m`, `p'` et `m'`. Une enveloppe n'est pas une région de plus, c'est la
 * réunion de deux régions voisines — elle se dérive du dessin, et rien ne serait gagné à
 * lui donner une valeur que le calcul devrait ensuite tenir cohérente avec ses deux
 * moitiés. **La règle qui tranchera les cas futurs : une valeur ici désigne une partition,
 * jamais une réunion.**
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
    /** Le tronc : la lignée directe, la fratrie **et le conjoint** de la racine. */
    case Tr = 'tr';
    /**
     * La belle-famille : la famille du conjoint d'un membre du tronc **autre que le
     * lecteur lui-même**.
     *
     * **Ce n'est pas un cinquième quart, et ce n'est pas « le reste ».** Les quatre quarts
     * sont des ascendances ; celle-ci est une famille entière que rien ne relie au sang du
     * lecteur, et qui entre dans l'arbre par une alliance.
     *
     * ⚠ **CE QUE CETTE CLÉ COUVRAIT, ET CE QU'ELLE COUVRE DEPUIS LES QUATRE QUARTS DU
     * CONJOINT.** Son docbloc annonçait « une seule clé aujourd'hui, quatre demain », et
     * promettait que le découpage la **remplacerait**. Le découpage est fait — {@see Cpp}
     * et ses trois sœurs — et cette prévision était **fausse sur un point** : `Bf` ne
     * disparaît pas, elle rétrécit.
     *
     * La raison tient à son amorce, et elle se lit dans
     * {@see \App\Module\Tree\Service\CalculAscendance::belleFamille()} : elle part du
     * conjoint de **tout** membre du tronc — donc aussi des conjoints de la fratrie du
     * lecteur et de ceux de ses enfants. Ces familles-là ne sont l'ascendance de personne
     * dans le dessin : elles n'ont pas de quatre quarts à recevoir. `Bf` les garde, et
     * c'est **une région, pas un résidu** : « la belle-famille de ma sœur » est une phrase
     * qui a un sens, et qui n'en a aucun dans les treize blocs.
     *
     * Avant qu'elle existe, ces personnes n'avaient pas de branche du tout, et le
     * sérialiseur leur servait celle **de la racine** par un repli silencieux ;
     * « maternelle du père » désignait alors le côté maternel de quelqu'un d'autre.
     */
    case Bf = 'bf';
    /**
     * `pp'` — la branche paternelle du père **du conjoint du lecteur**.
     *
     * ── LES QUATRE QUARTS DU CONJOINT ───────────────────────────────────────────
     *
     * Le commanditaire lit son arbre en deux moitiés autour d'un tronc central : ses
     * quatre quarts à gauche, ceux de sa conjointe à droite. La spécification les note
     * `pp'`, `pm'`, `mp'`, `mm'` ; l'apostrophe ne passant ni dans une valeur stockée ni
     * dans un nom de classe CSS, elle devient ici le préfixe **`c`**, pour « conjoint ».
     *
     * **Ce sont des quarts d'ascendance au même titre que les quatre premiers, et ils sont
     * calculés par exactement le même geste** : les quatre grands-parents du conjoint, puis
     * la remontée de chacun. `CalculAscendance` avait déjà la primitive — `souches()` et
     * `remonte()` —, il ne lui manquait que de l'appliquer une seconde fois. **Il n'y a donc
     * pas deux définitions de « quart » dans le produit**, et c'est ce qui garantit que les
     * deux moitiés du dessin se ressemblent au lieu de se ressembler par accident.
     *
     * ⚠ **LE SANG DU LECTEUR PASSE D'ABORD, ET CE N'EST PAS UN DÉTAIL DE MISE EN ŒUVRE.**
     * Ces quatre-ci sont posés **après** les quatre du lecteur et après le tronc, et ne
     * réclament que des personnes encore sans branche. Sur un arbre endogame — et celui-ci
     * en porte, l'implexe est nommé dans {@see CalculAscendance::remonte()} —, un même
     * ancêtre appartient aux deux moitiés. Il reste du côté du lecteur. Inverser cet ordre
     * ferait passer des aïeux du lecteur dans le bloc de son conjoint, **sans rien casser
     * ni rien signaler** : le dessin resterait cohérent, simplement faux.
     *
     * **Un seul conjoint est découpé** — le premier dont les parents sont dans le graphe.
     * Un lecteur remarié n'en voit donc qu'un en quatre quarts ; les autres restent dans
     * {@see Bf} avec leur famille. C'est le même choix que fait le moteur de placement
     * (`AffinageDuPlacement::quartsDuLecteur()`), et pour la même raison : le dessin a
     * **une** moitié droite, pas trois.
     *
     * **Vides sur la vue de la racine, et c'est le témoin de tout ce chantier.** La racine
     * de cet arbre n'a pas de conjoint : aucune de ces quatre valeurs n'y est posée, et sa
     * colonne `branch` ne change pas d'une ligne.
     */
    case Cpp = 'cpp';
    /** `pm'` — la branche maternelle du père du conjoint du lecteur. {@see Cpp}. */
    case Cpm = 'cpm';
    /** `mp'` — la branche paternelle de la mère du conjoint du lecteur. {@see Cpp}. */
    case Cmp = 'cmp';
    /** `mm'` — la branche maternelle de la mère du conjoint du lecteur. {@see Cpp}. */
    case Cmm = 'cmm';

    public function label(): string
    {
        return match ($this) {
            self::Pp => 'Branche paternelle du père',
            self::Pm => 'Branche maternelle du père',
            self::Mp => 'Branche paternelle de la mère',
            self::Mm => 'Branche maternelle de la mère',
            self::Tr => 'Tronc',
            self::Bf => 'Belle-famille',
            self::Cpp => 'Branche paternelle du père du conjoint',
            self::Cpm => 'Branche maternelle du père du conjoint',
            self::Cmp => 'Branche paternelle de la mère du conjoint',
            self::Cmm => 'Branche maternelle de la mère du conjoint',
        };
    }
}
