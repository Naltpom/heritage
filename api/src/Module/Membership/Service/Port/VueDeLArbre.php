<?php

declare(strict_types=1);

namespace App\Module\Membership\Service\Port;

/**
 * L'arbre validé complet, en mémoire, sous la forme dont le cercle de visibilité a besoin.
 *
 * ## Pourquoi tout l'arbre, et pourquoi en mémoire
 *
 * Le § 9.3 du modèle de données tranche la question et donne les chiffres : le cercle est
 * **calculé, jamais matérialisé en table**, à partir d'une lecture de l'adjacence
 * (103 familles + 174 filiations, environ 15 Ko) et d'un parcours de graphe de 0,02 ms.
 * L'argument décisif n'est pas la vitesse mais l'invalidation : *une table de cercles
 * périmée ne plante pas, elle montre des données privées à quelqu'un qui ne devrait plus
 * les voir.*
 *
 * ## L'adjacence est reconstruite depuis les familles, jamais lue dénormalisée
 *
 * `parents`, `enfants` et `conjoints` sont dérivés ici, dans le constructeur, des seules
 * listes `epoux` et `enfants` de chaque famille. C'est le même choix que
 * `Tree\Service\JeuDePlacementDoctrine` et pour le même motif, déjà payé une fois : des
 * listes d'adjacence dénormalisées peuvent mentir — elles sont écrites à un moment, et
 * l'arbre change à un autre. Les familles, elles, sont la donnée.
 *
 * ## Un conjoint est un coépoux, pas « quelqu'un de la même famille »
 *
 * Deux personnes sont conjointes si elles figurent **toutes deux dans `epoux`** d'une
 * même famille. Un enfant n'est le conjoint de personne, et deux époux successifs d'une
 * même personne ne sont pas conjoints entre eux. La nuance décide de la dernière passe de
 * la formule de l'ADR 0003, celle qui « avale la moitié de l'arbre au premier remariage »
 * si on la prend de travers.
 */
final readonly class VueDeLArbre
{
    /** @var array<int, PersonneDeLArbre> indexé par `tree_person.id` */
    public array $personnes;

    /** @var array<int, list<int>> personne ⇒ ses parents */
    public array $parents;

    /** @var array<int, list<int>> personne ⇒ ses enfants */
    public array $enfants;

    /** @var array<int, list<int>> personne ⇒ ses conjoints */
    public array $conjoints;

    /**
     * @var array<int, list<int>> personne ⇒ ses conjoints **actuels** — hors unions
     *                            rompues ; seuls les proches de la réciprocité le lisent
     */
    public array $conjointsActuels;

    /**
     * @param list<PersonneDeLArbre> $personnes l'arbre validé complet, sans filtre : le cercle
     *                                          se calcule sur tout l'arbre, et c'est la
     *                                          sérialisation qui masque (§ 6.2 du dossier)
     * @param list<FamilleDeLArbre>  $familles  les unions, avec leurs époux et leurs enfants
     */
    public function __construct(array $personnes, array $familles)
    {
        $parIdentifiant = [];

        foreach ($personnes as $personne) {
            $parIdentifiant[$personne->id] = $personne;
        }

        $this->personnes = $parIdentifiant;

        $parents = [];
        $enfants = [];
        $conjoints = [];
        $actuels = [];

        foreach ($familles as $famille) {
            foreach ($famille->epoux as $epoux) {
                foreach ($famille->enfants as $enfant) {
                    $parents[$enfant][] = $epoux;
                    $enfants[$epoux][] = $enfant;
                }

                foreach ($famille->epoux as $autre) {
                    if ($autre !== $epoux) {
                        $conjoints[$epoux][] = $autre;
                        if (!$famille->rompue) {
                            $actuels[$epoux][] = $autre;
                        }
                    }
                }
            }
        }

        $this->parents = self::deduplique($parents);
        $this->enfants = self::deduplique($enfants);
        $this->conjoints = self::deduplique($conjoints);
        $this->conjointsActuels = self::deduplique($actuels);
    }

    public static function vide(): self
    {
        return new self([], []);
    }

    public function personne(int $id): ?PersonneDeLArbre
    {
        return $this->personnes[$id] ?? null;
    }

    /**
     * « Cette personne est-elle vivante ? », et **l'inconnu vaut vivant**.
     *
     * Une personne absente de la vue rend `true` : *« on ne sait pas si elle est morte »
     * n'est pas une autorisation de publier ses dates* (§ 6.2 du dossier). C'est le cas
     * d'un identifiant pendant — la contrepartie assumée de l'absence de clé étrangère
     * inter-module (ADR 0002) — et d'une vue non branchée. Le défaut y est fermé, comme
     * partout ailleurs sur ce chemin.
     */
    public function estVivante(int $id): bool
    {
        $personne = $this->personnes[$id] ?? null;

        return null === $personne || $personne->vivante;
    }

    /**
     * « Cette fiche est-elle à confidentialité **forcée** ? » (`tree_person.force_privacy`),
     * et, comme pour {@see self::estVivante()}, **l'inconnu vaut oui**.
     *
     * Depuis la révision du 2026-10-01 de l'ADR 0003, c'est la question qui décide si un
     * membre validé voit un vivant hors de son cercle généalogique : oui, sauf fiche
     * confidentielle. Un identifiant pendant ou une vue non branchée ne doit donc pas
     * répondre « non » : ce serait ouvrir par ignorance ce que l'administration a fermé.
     */
    public function estConfidentielle(int $id): bool
    {
        $personne = $this->personnes[$id] ?? null;

        return null === $personne || $personne->confidentielle;
    }

    /** @return list<int> */
    public function parentsDe(int $id): array
    {
        return $this->parents[$id] ?? [];
    }

    /** @return list<int> */
    public function enfantsDe(int $id): array
    {
        return $this->enfants[$id] ?? [];
    }

    /** @return list<int> */
    public function conjointsDe(int $id): array
    {
        return $this->conjoints[$id] ?? [];
    }

    /** @return list<int> */
    public function conjointsActuelsDe(int $id): array
    {
        return $this->conjointsActuels[$id] ?? [];
    }

    /**
     * @param array<int, list<int>> $adjacence
     *
     * @return array<int, list<int>>
     */
    private static function deduplique(array $adjacence): array
    {
        foreach ($adjacence as $cle => $voisins) {
            $adjacence[$cle] = array_values(array_unique($voisins));
        }

        return $adjacence;
    }
}
