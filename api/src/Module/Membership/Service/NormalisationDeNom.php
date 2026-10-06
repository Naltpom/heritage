<?php

declare(strict_types=1);

namespace App\Module\Membership\Service;

use App\Module\Core\Service\CleDeRecherche;

/**
 * La comparaison des noms pour la recherche de rattachement — **jamais pour l'affichage**.
 *
 * Un arbre familial français est plein d'accents, de particules, de traits d'union et de
 * majuscules d'état civil : `TILLEUL`, `Génévrolle`, `Marne-Jaspine`, `de La Rue`. Un
 * candidat qui tape son propre nom ne reproduit aucune de ces conventions, et une
 * comparaison littérale refuserait la bonne personne à peu près une fois sur deux.
 *
 * ## Le repliement vient de `Core`, et il n'y en a qu'un
 *
 * {@see CleDeRecherche} est **la** définition du repliement d'accents du projet : celle
 * qui écrit `tree_person.search_key` et celle qui la cherche. Ce service
 * n'en pose pas une troisième — il l'appelle.
 *
 * **Ce fichier employait `iconv` avec `//TRANSLIT`, et c'était un bogue de plateforme.**
 * Son résultat dépend de la bibliothèque du système : `é` devient `e` sous la glibc de
 * Linux, `'e` sous la libiconv de Windows, et **`?` sous la musl d'Alpine** — l'image
 * Docker de ce projet. Le développement se fait sous Windows, l'exécution dans Alpine :
 * la même recherche aurait donné deux résultats différents selon la machine, et un
 * `Génévrolle` introuvable en production aurait ressemblé à « cette personne n'est pas
 * dans l'arbre » plutôt qu'à une panne. Sur le principal risque du produit, c'est
 * exactement le genre d'écart qui ne se voit pas.
 *
 * ## Ce que ce service ajoute, et pourquoi il ne disparaît pas
 *
 * `CleDeRecherche` replie ; il ne compare pas. La tolérance ci-dessous — **une** distance
 * d'édition, et seulement au-delà de quatre lettres — est une décision propre à ce module,
 * et elle est une décision de sécurité : chaque cran de tolérance élargit ce qu'un porteur
 * de jeton peut faire correspondre, donc ce qu'il peut apprendre.
 *
 * **Rien de ce qui sort d'ici ne sort de l'API.** Ce qui sort est
 * {@see Port\PersonneDeLArbre::libelleMinimal()}, construit sur les valeurs d'origine ;
 * une clé repliée — sans accent, en majuscules — ne doit jamais être prise pour un
 * affichage acceptable.
 */
final readonly class NormalisationDeNom
{
    /**
     * En deçà de cinq lettres, une distance d'édition de 1 ne corrige pas une faute de
     * frappe : elle change le nom. `Thym` et `Thyn` sont deux familles, `Bon` et `Bou`
     * aussi. Au-delà, `Romarin` et `Romari` sont bien la même, et `Tilleul` et `Tilleule`
     * également — c'est le cas réel qui a fixé le seuil.
     */
    private const int LONGUEUR_MINIMALE_POUR_TOLERANCE = 5;

    public function __construct(private CleDeRecherche $repliement)
    {
    }

    /**
     * La clé de comparaison : `[A-Z0-9 ]`, accents repliés, ponctuation réduite.
     *
     * La casse n'a pas d'importance en soi — ce qui compte est que les **deux** côtés de
     * chaque comparaison passent par ici. C'est la seule propriété qui rende la recherche
     * fiable, et c'est celle que `CleDeRecherche` garantit sur toutes les machines.
     */
    public function normaliser(string $valeur): string
    {
        return $this->repliement->replier($valeur);
    }

    /** @return list<string> les jetons d'une suite de prénoms, repliés, vides retirés */
    public function jetons(string $valeur): array
    {
        $normalise = $this->normaliser($valeur);

        if ('' === $normalise) {
            return [];
        }

        return array_values(array_filter(explode(' ', $normalise), static fn (string $jeton): bool => '' !== $jeton));
    }

    /** Deux valeurs désignent-elles le même nom, à une faute de frappe près ? */
    public function proche(string $a, string $b): bool
    {
        if ('' === $a || '' === $b) {
            return false;
        }

        if ($a === $b) {
            return true;
        }

        if (min(\strlen($a), \strlen($b)) < self::LONGUEUR_MINIMALE_POUR_TOLERANCE) {
            return false;
        }

        return levenshtein($a, $b) <= 1;
    }

    /**
     * Un des prénoms soumis correspond-il à un des prénoms de la fiche ?
     *
     * **Un seul suffit, et c'est voulu.** L'état civil empile les prénoms — « Marie
     * Joséphine Augustine » — dont l'usage n'en retient qu'un, souvent pas le premier. Un
     * candidat qui donne son prénom d'usage doit se reconnaître ; exiger la suite complète
     * refuserait précisément les personnes que ce parcours existe pour accueillir.
     *
     * @param list<string> $soumis
     * @param list<string> $fiche
     */
    public function unPrenomEnCommun(array $soumis, array $fiche): bool
    {
        foreach ($soumis as $unSoumis) {
            foreach ($fiche as $unFiche) {
                if ($this->proche($unSoumis, $unFiche)) {
                    return true;
                }
            }
        }

        return false;
    }
}
