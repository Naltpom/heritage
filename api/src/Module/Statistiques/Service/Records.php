<?php

declare(strict_types=1);

namespace App\Module\Statistiques\Service;

use App\Module\Core\ValueObject\UncertainDate;
use App\Module\Statistiques\Contract\FamilleDuReleve;
use App\Module\Statistiques\Contract\PersonneDuReleve;

/**
 * Section 3 — **les records** : doyen·ne, plus grande fratrie, plus longue union, plus grand
 * écart d'âge entre un parent et son enfant, plus vieil ancêtre connu de chaque branche.
 *
 * ═══════════════════════════════════════════════════════════════════════════════
 *  UN RECORD DONT LE TITULAIRE EST INVISIBLE PASSE AU SUIVANT — ET POURQUOI
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * Deux choix étaient possibles pour un titulaire que le lecteur ne voit pas : l'afficher
 * sans nom (« une personne de la branche Chardon ») ou passer au suivant. **On passe au
 * suivant.** Un record anonyme publierait encore sa **valeur** — un âge au décès, une durée
 * d'union, un nombre d'années —, c'est-à-dire un calcul fait sur les dates de la personne
 * qu'on cache, accompagné de sa branche : de quoi la reconnaître dans une famille de
 * quelques centaines de personnes. L'ADR 0003 le dit : un champ dérivé d'une donnée
 * masquée est une donnée masquée. Le record affiché est donc **le record parmi les fiches
 * que le lecteur voit**, et l'écran le dit en une ligne.
 *
 * Pour une union ou une fratrie, il faut voir **les deux époux** (la règle du « ET »
 * d'`Almanach`) ; pour l'écart parent-enfant, le parent **et** l'enfant.
 *
 * Les vivants comptent (décision du 02/10/2026) : la doyenne peut être une arrière-grand-mère
 * de 97 ans bien en vie, et sa fiche — visible d'un membre — le dit déjà.
 */
final class Records
{
    /** @return array<string, mixed> */
    public function calculer(Population $population, ArbreEnChiffres $arbre): array
    {
        return [
            'doyen' => $this->doyen($population),
            'fratrie' => $this->fratrie($population),
            'union' => $this->union($population),
            'ecartParentEnfant' => $this->ecartParentEnfant($population),
            'ancetres' => $this->ancetres($population, $arbre->nomsDesQuarts($population)),
        ];
    }

    /** @return array<string, mixed>|null */
    private function doyen(Population $population): ?array
    {
        $meilleur = null;
        $age = -1;

        foreach ($this->visibles($population) as $personne) {
            $atteint = $population->ageAtteint($personne);

            if (null === $atteint || $atteint > VieDAvant::AGE_MAXIMAL || $atteint <= $age) {
                continue;
            }

            $meilleur = $personne;
            $age = $atteint;
        }

        if (null === $meilleur) {
            return null;
        }

        $enVie = null === DateRetenue::annee($meilleur->deces);

        return [
            'valeur' => $age,
            'personnes' => [$population->reference($meilleur)],
            'debut' => DateRetenue::annee($meilleur->naissance),
            'fin' => DateRetenue::annee($meilleur->deces),
            'enCours' => $enVie,
            'sexe' => $meilleur->sex->value,
        ];
    }

    /** @return array<string, mixed>|null */
    private function fratrie(Population $population): ?array
    {
        $meilleure = null;

        foreach ($population->familles as $famille) {
            if ($population->familleMasquee($famille) || !$this->aDesEpoux($famille)) {
                continue;
            }

            if (null === $meilleure || \count($famille->enfants) > \count($meilleure->enfants)) {
                $meilleure = $famille;
            }
        }

        if (null === $meilleure || [] === $meilleure->enfants) {
            return null;
        }

        return ['valeur' => \count($meilleure->enfants), 'personnes' => $this->epoux($population, $meilleure)];
    }

    /**
     * La plus longue union : du mariage (date qui compte) à la première fin connue — le
     * divorce, ou le décès du premier des deux époux —, ou à aujourd'hui si les deux sont
     * en vie. Une union dont la fin est inconnue (un époux présumé décédé sans date) ne
     * concourt pas : on ne sait pas combien elle a duré.
     *
     * @return array<string, mixed>|null
     */
    private function union(Population $population): ?array
    {
        $meilleure = null;
        $duree = -1;
        $finRetenue = null;

        foreach ($population->familles as $famille) {
            if ($population->familleMasquee($famille) || null === $famille->mariId || null === $famille->epouseId) {
                continue;
            }

            if (null === DateRetenue::annee($famille->mariage)) {
                continue;
            }

            $fin = $this->finDeLUnion($population, $famille);

            if (false === $fin) {
                continue;
            }

            $annees = DateRetenue::ageEnAnnees($famille->mariage, $fin ?? $population->maintenant);

            if (null === $annees || $annees > 90 || $annees <= $duree) {
                continue;
            }

            $meilleure = $famille;
            $duree = $annees;
            $finRetenue = $fin;
        }

        if (null === $meilleure) {
            return null;
        }

        return [
            'valeur' => $duree,
            'personnes' => $this->epoux($population, $meilleure),
            'debut' => DateRetenue::annee($meilleure->mariage),
            'fin' => null === $finRetenue ? null : DateRetenue::annee($finRetenue),
            'enCours' => null === $finRetenue,
        ];
    }

    /**
     * La fin d'une union : une date qui compte, `null` si elle dure encore, `false` si on
     * ne sait pas.
     */
    private function finDeLUnion(Population $population, FamilleDuReleve $famille): UncertainDate|false|null
    {
        $fins = [];

        if ($famille->divorce) {
            if (null === DateRetenue::annee($famille->dateDuDivorce)) {
                return false;
            }

            $fins[] = $famille->dateDuDivorce;
        }

        $enVie = 0;
        foreach ([$famille->mariId, $famille->epouseId] as $id) {
            $epoux = null !== $id ? ($population->personnes[$id] ?? null) : null;

            if (null === $epoux) {
                return false;
            }

            if (null !== DateRetenue::annee($epoux->deces)) {
                $fins[] = $epoux->deces;
            } elseif ($epoux->vivant && !$epoux->decede && !$epoux->deces->isKnown()) {
                ++$enVie;
            } else {
                // Décès noté sans date qui compte, ou présumé décédé : fin inconnue.
                return false;
            }
        }

        if ([] === $fins) {
            return 2 === $enVie ? null : false;
        }

        usort($fins, static fn ($a, $b): int => DateRetenue::comparer($a, $b) ?? 0);

        return $fins[0];
    }

    /**
     * Le plus grand écart d'âge entre un parent et son enfant : l'âge du parent à la
     * naissance. C'est l'écart de **deux générations voisines**, la lecture retenue de
     * « plus grand écart de générations » (point à valider avec le commanditaire).
     *
     * @return array<string, mixed>|null
     */
    private function ecartParentEnfant(Population $population): ?array
    {
        $meilleur = null;
        $ecart = -1;

        foreach ($this->visibles($population) as $enfant) {
            foreach ([$population->pere($enfant->id), $population->mere($enfant->id)] as $parent) {
                if (null === $parent || $population->masquee($parent->id)) {
                    continue;
                }

                $age = DateRetenue::ageEnAnnees($parent->naissance, $enfant->naissance);

                // Au-delà de 80 ans, c'est une confusion d'homonymes, pas une naissance.
                if (null === $age || $age > 80 || $age <= $ecart) {
                    continue;
                }

                $meilleur = [$parent, $enfant];
                $ecart = $age;
            }
        }

        if (null === $meilleur) {
            return null;
        }

        [$parent, $enfant] = $meilleur;

        return [
            'valeur' => $ecart,
            'personnes' => [$population->reference($parent), $population->reference($enfant)],
            'debut' => DateRetenue::annee($parent->naissance),
            'fin' => DateRetenue::annee($enfant->naissance),
            'sexe' => $parent->sex->value,
        ];
    }

    /**
     * Le plus vieil ancêtre connu de chaque branche : la naissance qui compte la plus
     * ancienne parmi les fiches visibles de la branche (à égalité, la génération la plus
     * haute). Le tronc n'a pas d'« ancêtre de branche » : il est le point de départ.
     *
     * @param array<string, string> $noms les patronymes de tête des quarts
     *
     * @return list<array<string, mixed>>
     */
    private function ancetres(Population $population, array $noms): array
    {
        /** @var array<string, PersonneDuReleve> $parBranche */
        $parBranche = [];

        foreach ($this->visibles($population) as $personne) {
            $branche = $personne->branch?->value;

            if (null === $branche || 'tr' === $branche || null === DateRetenue::annee($personne->naissance)) {
                continue;
            }

            $actuel = $parBranche[$branche] ?? null;

            if (null === $actuel) {
                $parBranche[$branche] = $personne;
                continue;
            }

            $ordre = DateRetenue::comparer($personne->naissance, $actuel->naissance) ?? 0;

            if ($ordre < 0 || (0 === $ordre && ($personne->generation ?? 0) < ($actuel->generation ?? 0))) {
                $parBranche[$branche] = $personne;
            }
        }

        $lignes = [];
        foreach (['pp', 'pm', 'mp', 'mm', 'cpp', 'cpm', 'cmp', 'cmm'] as $branche) {
            $personne = $parBranche[$branche] ?? null;

            if (null === $personne) {
                continue;
            }

            $lignes[] = [
                'branche' => $branche,
                'nom' => $noms[$branche] ?? null,
                'valeur' => DateRetenue::annee($personne->naissance),
                'personnes' => [$population->reference($personne)],
            ];
        }

        return $lignes;
    }

    /** @return iterable<PersonneDuReleve> */
    private function visibles(Population $population): iterable
    {
        foreach ($population->personnes as $personne) {
            if (!$population->masquee($personne->id)) {
                yield $personne;
            }
        }
    }

    private function aDesEpoux(FamilleDuReleve $famille): bool
    {
        return null !== $famille->mariId || null !== $famille->epouseId;
    }

    /** @return list<array{id: int, nom: string}> */
    private function epoux(Population $population, FamilleDuReleve $famille): array
    {
        $epoux = [];

        foreach ([$famille->mariId, $famille->epouseId] as $id) {
            $personne = null !== $id ? ($population->personnes[$id] ?? null) : null;

            if (null !== $personne) {
                $epoux[] = $population->reference($personne);
            }
        }

        return $epoux;
    }
}
