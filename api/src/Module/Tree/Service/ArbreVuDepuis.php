<?php

declare(strict_types=1);

namespace App\Module\Tree\Service;

use App\Module\Core\Enum\Branch;
use App\Module\Tree\ValueObject\IdentifiantDeNoeud;
use App\Module\Tree\ValueObject\JeuDePlacement;
use App\Module\Tree\ValueObject\PersonnePlacable;
use App\Module\Tree\ValueObject\PlacementLogique;

/**
 * **L'arbre validé, tel qu'il se lit depuis une personne donnée.**.
 *
 * ── Pourquoi cette classe existe ────────────────────────────────────────────────
 *
 * `tree_person` porte deux colonnes **dérivées** — `generation` et `branch` — recalculées
 * par {@see RecalculDesColonnesDerivees} depuis `tree.personne_racine_id`. Elles sont
 * justes, et elles ne sont justes que pour cette personne-là :
 *
 * - `generation` fixe `y = génération × 150`. La rangée zéro est la sienne ; tout le monde
 *   se situe par rapport à elle ;
 * - `branch` est le **quart d'ascendance** — `pp` est la lignée de *son* grand-père
 *   paternel.
 *
 * Servies à tout le monde, elles sont donc justes pour un compte et fausses pour tous les
 * autres, **sans en avoir l'air** : « branche paternelle » est une phrase parfaitement
 * plausible, y compris quand elle désigne le côté paternel de quelqu'un d'autre. C'est le
 * même constat que {@see \App\Module\Tree\Serializer\ParenteDuLecteur} a fait pour les
 * libellés de parenté, sur les deux colonnes qui restaient.
 *
 * On les **recalcule à la lecture** pour le point de vue demandé, avec le même service que
 * la commande de recalcul : {@see CalculAscendance}. Une seule définition de « génération »
 * et de « branche » dans le produit.
 *
 * Le recalcul a lieu **aussi pour la racine**, et ce n'est pas du travail pour rien : la
 * colonne est censée valoir exactement cela, et si elle en diverge — un arbre modifié sans
 * que la commande de recalcul ait été rejouée — la mise en page servie serait celle d'un
 * état périmé, sans que rien ne le dise. Lire ce qu'on sait calculer, c'est refuser d'avoir
 * deux réponses à la même question.
 *
 * ── Ce qui n'est pas recalculé, et pourquoi ─────────────────────────────────────
 *
 * **Rien en base.** Aucune écriture, aucune colonne touchée. `Person` porte un
 * `#[ORM\Version]` : réécrire une ligne de personne incrémenterait sa version et
 * **périmerait les demandes en attente qui la visaient**. Une lecture ne doit jamais faire
 * mourir une proposition.
 *
 * **Ni la largeur des fiches, ni l'ordre des listes.** `PersonnePlacable` est recopié à
 * l'identique à une valeur près. L'ordre des deux listes est de la donnée et non de la
 * présentation — le point fixe en douze passes y départage les égalités.
 *
 * ── Le coût ────────────────────────────────────────────────────────────────────
 *
 * Un parcours de graphe sur 274 personnes, mémorisé pour la durée de la requête, et dont
 * le résultat alimente une entrée de cache par point de vue ({@see PlacementPartage}). Le
 * nombre de points de vue est borné par le nombre de comptes rattachés à une personne :
 * une famille, pas un public.
 */
final class ArbreVuDepuis
{
    /** @var array<string, JeuDePlacement> mémorisé pour la durée de la requête */
    private array $jeux = [];

    /** @var array<string, PlacementLogique> */
    private array $logiques = [];

    /**
     * `$fournisseur` est optionnel **le temps qu'une implémentation existe** — voir
     * {@see PlacementPartage} pour la raison, qui n'a pas changé.
     */
    public function __construct(
        private readonly CalculAscendance $ascendance,
        private readonly ?FournisseurDeJeuDePlacement $fournisseur = null,
    ) {
    }

    /**
     * Le jeu de placement vu depuis cette personne : mêmes fiches, mêmes familles, mêmes
     * largeurs, **générations recalculées**.
     *
     * `null` rend le jeu tel que la base le porte, c'est-à-dire la vue de la racine.
     */
    public function jeu(?int $egoPersonneId): JeuDePlacement
    {
        return $this->jeux[$this->cle($egoPersonneId)] ??= $this->construire($egoPersonneId);
    }

    /**
     * Les branches vues depuis cette personne, indexées par **identifiant de personne**
     * — la forme que le sérialiseur consomme.
     *
     * @return array<int, Branch>
     */
    public function branches(?int $egoPersonneId): array
    {
        // Le calcul vit dans {@see construire()} : c'est le même parcours qui produit les
        // générations et les branches, et les séparer en referait deux.
        $this->jeu($egoPersonneId);

        $logique = $this->logiques[$this->cle($egoPersonneId)] ?? null;

        if (null === $logique) {
            // Aucun recalcul n'a eu lieu — il n'y a pas de racine réglée. `[]` veut dire
            // « garde ce que porte la colonne », pas « aucune branche ».
            return [];
        }

        $branches = [];

        foreach ($logique->branches as $noeudId => $branche) {
            if (!IdentifiantDeNoeud::estPersonne($noeudId)) {
                continue;
            }

            $id = IdentifiantDeNoeud::identifiantBase($noeudId);

            if (null !== $id) {
                $branches[$id] = $branche;
            }
        }

        return $branches;
    }

    private function cle(?int $egoPersonneId): string
    {
        return null === $egoPersonneId ? '' : 'p'.$egoPersonneId;
    }

    private function construire(?int $egoPersonneId): JeuDePlacement
    {
        if (null === $this->fournisseur) {
            throw new \LogicException(\sprintf('Aucun service n\'implémente %s : l\'arbre ne peut pas être chargé depuis la base. Déclarer une implémentation dans config/services/tree.yaml.', FournisseurDeJeuDePlacement::class));
        }

        $jeu = $this->fournisseur->jeuComplet();

        // Le point de vue demandé, ou celui que le jeu porte déjà — la racine.
        //
        // Une personne qui n'est pas dans l'arbre, un rattachement qui pointe vers une
        // fiche supprimée depuis, ne fait pas un point de vue : on retombe sur la racine
        // plutôt que de rendre un placement sans ego, où tout s'empilerait sur la rangée
        // zéro.
        $demande = null === $egoPersonneId ? null : IdentifiantDeNoeud::personne($egoPersonneId);
        $ego = null !== $demande && $this->contient($jeu, $demande) ? $demande : $jeu->egoId;

        if ('' === $ego) {
            // Aucune racine réglée : « branche paternelle du père » ne veut alors rien
            // dire, et {@see CalculAscendance} le refuse déjà. On rend le jeu tel quel.
            return $jeu;
        }

        $vu = new JeuDePlacement($jeu->personnes, $jeu->familles, $ego);
        $logique = $this->ascendance->pourLeJeu($vu);

        $this->logiques[$this->cle($egoPersonneId)] = $logique;

        $personnes = [];

        foreach ($vu->personnes as $personne) {
            $personnes[] = $this->avecGeneration($personne, $logique->generations[$personne->id] ?? $personne->generation);
        }

        return new JeuDePlacement($personnes, $vu->familles, $ego);
    }

    private function contient(JeuDePlacement $jeu, string $ego): bool
    {
        foreach ($jeu->personnes as $personne) {
            if ($personne->id === $ego) {
                return true;
            }
        }

        return false;
    }

    /**
     * Une fiche identique à une valeur près.
     *
     * Recopiée champ par champ plutôt que clonée : `PersonnePlacable` est `readonly`, et
     * un `clone with` limiterait la version de PHP qu'on peut servir sans que rien ne le
     * dise. La liste est courte et le compilateur la tient.
     */
    private function avecGeneration(PersonnePlacable $personne, int $generation): PersonnePlacable
    {
        if ($generation === $personne->generation) {
            return $personne;
        }

        return new PersonnePlacable(
            id: $personne->id,
            prenom: $personne->prenom,
            nom: $personne->nom,
            usage: $personne->usage,
            sexe: $personne->sexe,
            nais: $personne->nais,
            deces: $personne->deces,
            anNais: $personne->anNais,
            vivant: $personne->vivant,
            generation: $generation,
        );
    }
}
