<?php

declare(strict_types=1);

namespace App\Module\Tree\Serializer;

use App\Module\Core\Service\CleDeRecherche;

/**
 * Normalise un terme de recherche dans la forme de `tree_person.search_key` : sans
 * accents, en majuscules, ponctuation retirée.
 *
 * **La duplication est levée.** La table de repliement vivait ici *et* dans le module
 * `Gedcom` (supprimé depuis, ADR 0017), qui **écrivait** `search_key` — `Tree` n'ayant pas
 * le droit de l'importer (ADR 0002, règle 3 : `Core` seul en liste blanche). Elle est
 * remontée dans {@see CleDeRecherche}, comme l'arbitrage **E** l'a fait pour
 * `UncertainDate`, `DateQualifier`, `Sex` et `Branch`. Il n'existe donc plus qu'une seule
 * définition, appelée des deux côtés — c'est elle qui porte le motif pour lequel `iconv
 * //TRANSLIT` est proscrit.
 *
 * Ce qui reste ici appartient bien à `Tree` : les bornes du § 5.5 — longueur minimale du
 * terme, plafond de résultats.
 */
final class NormaliseurDeRequete
{
    /** Le minimum du § 5.5 : en deçà, `422`. Une lettre seule listerait un quart de l'arbre. */
    public const int LONGUEUR_MINIMALE = 2;

    public const int LIMITE_PAR_DEFAUT = 20;
    public const int LIMITE_MAXIMALE = 50;

    /**
     * Le nombre de mots qu'un terme peut porter — **une borne sur la requête, pas une
     * règle métier**.
     *
     * Le § 5.5 borne la longueur du terme, jamais son nombre de mots. Chaque mot ajoute
     * une paire de `LIKE` à la requête ; sans borne, un terme de 200 caractères en
     * produirait une centaine. Huit est confortablement au-dessus du besoin réel : la
     * clé la plus longue de la base de travail porte **5** mots (distribution mesurée sur
     * 279 fiches : 0→4, 1→3, 2→108, 3→110, 4→42, 5→12).
     *
     * Au-delà, les mots surnuméraires sont **ignorés** plutôt que refusés — un `422` ne
     * servirait personne et apprendrait où est la limite (§ 1.5, « un plafond qui rabote
     * n'apprend rien »). Le sens de la troncature est le seul point à connaître : chaque
     * mot **restreint** le résultat, donc en ignorer élargit. C'est sans portée ici,
     * puisqu'un terme de plus de huit mots ne peut de toute façon pas décrire une des
     * clés de la base.
     */
    public const int MOTS_MAXIMUM = 8;

    /**
     * Défaut instancié : ce service est autowiré en production, mais il est aussi
     * construit par `new NormaliseurDeRequete()` dans les tests. Le défaut évite de
     * réécrire ces appels pour une dépendance sans état.
     */
    public function __construct(private readonly CleDeRecherche $cles = new CleDeRecherche())
    {
    }

    /**
     * Le terme de recherche, dans la forme exacte de `tree_person.search_key`.
     *
     * **Même appel que celui qui écrit la colonne** ({@see \App\Module\Tree\Service\NormalisateurNom}) :
     * c'est toute la garantie. Si cette méthode
     * cessait de passer par `Core`, la colonne et la requête pourraient à nouveau
     * diverger — et la recherche cesserait de trouver les noms accentués sans que rien
     * ne tombe.
     */
    public function cle(string $terme): string
    {
        return $this->cles->replier($terme);
    }

    /**
     * Le terme **découpé en mots**, dans la forme de `search_key`.
     *
     * ── Pourquoi la recherche a besoin des mots et non de la clé entière ────────────
     *
     * `search_key` s'écrit « patronyme, prénoms » ; un membre tape « Prénom Nom ». La
     * clé entière ne correspondait alors à aucun préfixe ni à aucun infixe, et la
     * requête rendait **zéro ligne**. Mesuré sur la base de travail : sur les 272 fiches
     * qui ont à la fois un prénom et un patronyme, interrogées avec le terme « Prénom
     * Nom » de la fiche elle-même, l'ancienne forme en retrouvait **0**. Pas « peu » :
     * zéro. « Deuxième prénom + patronyme » échouait de même, sur 156 fiches.
     *
     * En rendant les mots, {@see LectureDeLArbre::candidatsDeRecherche()}
     * peut exiger **chacun d'eux** séparément, ce qui rend l'ordre indifférent.
     *
     * ── Ce découpage ne peut pas produire de joker ──────────────────────────────────
     *
     * Les mots partent dans des `LIKE`. Ils sont sûrs **par construction et non par
     * échappement** : {@see CleDeRecherche::replier()} réduit tout ce qui n'est pas
     * `[A-Z0-9]` à un espace, donc ni `%` ni `_` ne peuvent survivre au repliement. Un
     * terme « 100%_ » rend « 100 ». C'est une propriété du repliement, et
     * `CleDeRechercheTest` la verrouille sur un corpus.
     *
     * @return list<string> jamais de chaîne vide, au plus {@see self::MOTS_MAXIMUM} entrées
     */
    public function mots(string $terme): array
    {
        $cle = $this->cle($terme);

        if ('' === $cle) {
            return [];
        }

        // `replier()` garantit l'absence d'espaces multiples et de bords : `explode` suffit.
        return \array_slice(explode(' ', $cle), 0, self::MOTS_MAXIMUM);
    }

    /**
     * Le plafond du § 5.5 : défaut 20, maximum 50, **ramené** au maximum plutôt que
     * refusé.
     *
     * « Un plafond qui lève une 422 apprend à l'appelant où est la limite, un plafond qui
     * rabote ne lui apprend rien et sert le même résultat » (§ 1.5).
     */
    public function limite(mixed $demandee): int
    {
        if (null === $demandee || '' === $demandee) {
            return self::LIMITE_PAR_DEFAUT;
        }

        $valeur = filter_var($demandee, \FILTER_VALIDATE_INT);

        if (false === $valeur || $valeur < 1) {
            return self::LIMITE_PAR_DEFAUT;
        }

        return min($valeur, self::LIMITE_MAXIMALE);
    }
}
