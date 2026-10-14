<?php

declare(strict_types=1);

namespace App\Module\Almanach\Contract;

/**
 * La nature d'un événement daté de l'arbre — **les onze**, sans exception.
 *
 * ═══════════════════════════════════════════════════════════════════════════════
 *  À LIRE AVANT DE VOULOIR « UNIFIER » CETTE ÉNUMÉRATION AVEC `FactType`
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * `src/Module/Tree/Enum/FactType.php` en compte sept, et son en-tête dit pourquoi il n'en
 * comptera jamais onze :
 *
 * > La nature d'un événement daté **autre que** naissance, décès, mariage et divorce.
 * > Ces quatre-là sont des colonnes de `tree_person` et `tree_family`, pas des
 * > `tree_fact` […] Corollaire à respecter par toute écriture : la naissance et le
 * > décès vont dans les colonnes et **n'en font pas aussi** des faits. Un fait dupliqué
 * > est une divergence qui attend son heure.
 *
 * Les quatre premiers cas d'ici sont **exactement ceux que cet en-tête interdit d'ajouter
 * là-bas**. Ce n'est donc pas un doublon à résorber : ce sont deux vocabulaires qui
 * répondent à deux questions différentes.
 *
 * | | `Tree\Enum\FactType` | `Almanach\Contract\TypeDEvenement` |
 * |---|---|---|
 * | question | « quelle ligne de `tree_fact` ? » | « quoi poser sur une carte ou un calendrier ? » |
 * | portée | 7 natures, table `tree_fact` | 11 natures, **toutes** les dates de l'arbre |
 * | naissance, décès | des **colonnes**, jamais des faits | des événements comme les autres |
 * | où il vit | `Enum/`, interne à `Tree` | `Contract/`, surface publique d'`Almanach` |
 *
 * **Fusionner les deux ferait rentrer `naissance` dans `FactType`**, donc rendrait
 * exprimable en base le fait dupliqué que cette règle interdit. Le gain serait quatre cas
 * économisés ; le prix, une naissance qui existe deux fois et deux barres qui divergent.
 *
 * ## Les sept dernières valeurs sont celles de `FactType`, **verbatim**
 *
 * `profession`, `residence`, `inhumation`, `recensement`, `bapteme_religieux`, `bapteme`,
 * `autre` : mêmes chaînes de *backing*, au caractère près. Ce n'est pas une coïncidence à
 * préserver par discipline — c'est ce qui rend la traduction triviale à lire côté `Tree`,
 * et c'est ce qu'un test vérifie chaîne par chaîne. **Changer une de ces sept valeurs ici
 * casse silencieusement le rendu** : le front reçoit un `type` qu'il ne connaît pas et
 * n'affiche rien.
 *
 * ## La traduction est un `match` exhaustif, jamais un `from()`
 *
 * `TypeDEvenement::from($fait->getType()->value)` marcherait aujourd'hui, et **c'est
 * précisément le problème** : le jour où `FactType` gagne un huitième cas, `from()` lève
 * un `ValueError` en production, dans la sérialisation d'une requête HTTP, sur la donnée
 * d'un membre. Un `match` exhaustif, lui, lève `UnhandledMatchError` **au premier test qui
 * passe par cette ligne** — c'est-à-dire avant la livraison. La traduction vit dans
 * `Tree\Service\VueDeLArbrePourAlmanach`, qui est le seul endroit du produit où les deux
 * vocabulaires se rencontrent.
 *
 * ## Pourquoi dans `Contract/` et non dans un `Core\Enum`
 *
 * L'ADR 0002 distingue les deux seuils : un **vocabulaire** monte dans `Core` dès que
 * **deux** modules l'instancient ; un **contrat** reste chez son déclarant tant qu'il ne
 * relie que deux modules. Celui-ci est déclaré par `Almanach` et lu par `Tree` seul, dans
 * l'adaptateur qu'`Almanach` lui demande d'écrire : il reste ici. Le monter dans `Core`
 * installerait dans le noyau le métier d'un module d'affichage.
 */
enum TypeDEvenement: string
{
    // ── Les quatre que `FactType` ne portera jamais : ce sont des colonnes ──────────

    /** `tree_person.birth_*`. */
    case Naissance = 'naissance';

    /** `tree_person.death_*`. */
    case Deces = 'deces';

    /** `tree_family.marriage_*` — deux sujets quand les deux époux sont connus. */
    case Mariage = 'mariage';

    /** `tree_family.divorce_*` — couvre aussi la rupture sans jugement. */
    case Divorce = 'divorce';

    // ── Les sept de `FactType`, valeurs de *backing* recopiées au caractère près ────

    case Profession = 'profession';
    case Residence = 'residence';
    case Inhumation = 'inhumation';
    case Recensement = 'recensement';
    case BaptemeReligieux = 'bapteme_religieux';
    case Bapteme = 'bapteme';
    case Autre = 'autre';

    /**
     * Le **genre** de cet événement — la fonction totale qui range onze natures dans les
     * cinq cases du vocabulaire des filtres.
     *
     * Totale, donc sans repli : un `default` masquerait l'ajout d'un douzième cas au lieu
     * de le signaler. Voir {@see GenreDEvenement} pour le motif des regroupements.
     */
    public function genre(): GenreDEvenement
    {
        return match ($this) {
            self::Naissance => GenreDEvenement::Naissance,
            self::Deces => GenreDEvenement::Deces,
            self::Mariage, self::Divorce => GenreDEvenement::Union,
            self::Bapteme, self::BaptemeReligieux => GenreDEvenement::Bapteme,
            self::Profession, self::Residence, self::Inhumation, self::Recensement, self::Autre => GenreDEvenement::Autre,
        };
    }

    /** Le libellé français, pour l'infobulle d'une bulle et la ligne d'un jour. */
    public function label(): string
    {
        return match ($this) {
            self::Naissance => 'Naissance',
            self::Deces => 'Décès',
            self::Mariage => 'Mariage',
            self::Divorce => 'Divorce',
            self::Profession => 'Profession',
            self::Residence => 'Résidence',
            self::Inhumation => 'Inhumation',
            self::Recensement => 'Recensement',
            self::BaptemeReligieux => 'Baptême religieux',
            self::Bapteme => 'Baptême',
            self::Autre => 'Événement',
        };
    }

    /**
     * Le préfixe de la clé d'événement — la partie avant les deux-points de
     * {@see EvenementDeLArbre::$cle}.
     *
     * Quatre valeurs seulement pour onze natures : les sept dernières viennent toutes de
     * `tree_fact` et partagent donc l'origine `fait`, dont l'identifiant est celui de la
     * ligne. C'est ce qui rend la clé unique **par construction** plutôt que par
     * convention — voir l'en-tête de {@see EvenementDeLArbre}.
     */
    public function origine(): string
    {
        return match ($this) {
            self::Naissance => 'naissance',
            self::Deces => 'deces',
            self::Mariage => 'mariage',
            self::Divorce => 'divorce',
            self::Profession, self::Residence, self::Inhumation,
            self::Recensement, self::BaptemeReligieux, self::Bapteme, self::Autre => 'fait',
        };
    }
}
