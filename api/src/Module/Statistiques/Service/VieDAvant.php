<?php

declare(strict_types=1);

namespace App\Module\Statistiques\Service;

use App\Module\Core\Enum\Sex;
use App\Module\Statistiques\Contract\FamilleDuReleve;

/**
 * Section 2 — **la vie d'avant** : des moyennes sur toute la population (décision du
 * 02/10/2026 : les vivants comptent, les fiches confidentielles aussi, sous le seuil de
 * discrétion d'{@see Echantillon}).
 *
 * Chaque case porte son effectif `n` : l'écran l'affiche (« sur 412 personnes »), pour
 * qu'une moyenne sur trois personnes ne se lise pas comme une loi de l'histoire.
 *
 * ── Les règles de chaque indicateur ─────────────────────────────────────────────
 *
 * - **Âge au décès** (« espérance de vie ») : les personnes dont la naissance et le décès
 *   comptent ({@see DateRetenue}), rangées par siècle de naissance. Les morts en bas âge
 *   y sont — c'est ce qui fait l'espérance de vie d'autrefois, et l'écran l'explique.
 *   Un âge au-delà de {@see AGE_MAXIMAL} ans est une erreur de saisie : il ne compte pas.
 * - **Âge au premier mariage** : la plus ancienne union dont la date compte. Une union
 *   plus ancienne mais non datée existe peut-être : on ne la devine pas.
 * - **Enfants par couple** : les couples qui ont **au moins un enfant connu**, par
 *   génération (lue depuis la racine). Un couple sans enfant inscrit est le plus souvent
 *   une branche qu'on n'a pas descendue, pas un couple sans enfant : le compter tirerait
 *   la moyenne vers le bas pour une raison qui n'a rien à voir avec l'histoire.
 * - **Âge des parents à la naissance** : chaque enfant dont la naissance compte, chaque
 *   parent dont la naissance compte, par siècle de naissance de l'enfant.
 * - **Écart d'âge dans le couple** : à l'année, l'âge du mari moins celui de l'épouse.
 * - **Mois des naissances et des décès** : les seules dates qui ont un mois (exacte ou
 *   « mars 1850 ») — jamais une année seule.
 * - **Mortalité infantile** : parmi les personnes dont la naissance **et** le décès
 *   comptent, celles mortes avant cinq ans, par siècle de naissance. Le dénominateur ne
 *   prend pas les naissances sans décès connu : on ne sait pas ce que ces enfants sont
 *   devenus.
 */
final class VieDAvant
{
    public const int AGE_MAXIMAL = 120;

    /** @return array<string, mixed> */
    public function calculer(Population $population): array
    {
        return [
            'ageAuDeces' => $this->ageAuDeces($population),
            'premierMariage' => $this->premierMariage($population),
            'enfantsParCouple' => $this->enfantsParCouple($population),
            'ageDesParents' => $this->ageDesParents($population),
            'ecartDansLeCouple' => $this->ecartDansLeCouple($population),
            'moisDesNaissances' => $this->mois($population, 'naissance'),
            'moisDesDeces' => $this->mois($population, 'deces'),
            'mortaliteInfantile' => $this->mortaliteInfantile($population),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function ageAuDeces(Population $population): array
    {
        /** @var array<int, array{hommes: Echantillon, femmes: Echantillon, ensemble: Echantillon}> $parSiecle */
        $parSiecle = [];

        foreach ($population->personnes as $personne) {
            $annee = DateRetenue::annee($personne->naissance);

            if (null === $annee || null === DateRetenue::annee($personne->deces)) {
                continue;
            }

            $age = DateRetenue::ageEnAnnees($personne->naissance, $personne->deces);

            if (null === $age || $age > self::AGE_MAXIMAL) {
                continue;
            }

            $this->ranger($parSiecle, DateRetenue::siecle($annee), $personne->sex, (float) $age, $population->masquee($personne->id));
        }

        return $this->parSiecleHommesFemmes($parSiecle);
    }

    /** @return list<array<string, mixed>> */
    private function premierMariage(Population $population): array
    {
        $parSiecle = [];

        foreach ($population->personnes as $personne) {
            $annee = DateRetenue::annee($personne->naissance);

            if (null === $annee) {
                continue;
            }

            $premiere = null;
            foreach ($population->unionsDe($personne->id) as $union) {
                if (null === DateRetenue::annee($union->mariage)) {
                    continue;
                }

                if (null === $premiere || (DateRetenue::comparer($union->mariage, $premiere->mariage) ?? 0) < 0) {
                    $premiere = $union;
                }
            }

            if (null === $premiere) {
                continue;
            }

            $age = DateRetenue::ageEnAnnees($personne->naissance, $premiere->mariage);

            // Moins de douze ans ou plus de cent : une date mal saisie, pas une noce.
            if (null === $age || $age < 12 || $age > 100) {
                continue;
            }

            $this->ranger($parSiecle, DateRetenue::siecle($annee), $personne->sex, (float) $age, $population->masquee($personne->id));
        }

        return $this->parSiecleHommesFemmes($parSiecle);
    }

    /**
     * @return list<array{generation: int, moyenne: float|null, n: int, annee: int|null}>
     */
    private function enfantsParCouple(Population $population): array
    {
        /** @var array<int, array{echantillon: Echantillon, annees: list<int>}> $parGeneration */
        $parGeneration = [];

        foreach ($population->familles as $famille) {
            if (null === $famille->generation || [] === $famille->enfants) {
                continue;
            }

            $case = $parGeneration[$famille->generation] ?? ['echantillon' => new Echantillon(), 'annees' => []];
            $case['echantillon']->ajouter((float) \count($famille->enfants), $population->familleMasquee($famille));

            // L'époque de la génération : les naissances des enfants qui comptent, pour
            // écrire « vers 1850 » à côté d'un rang que le lecteur ne sait pas situer.
            foreach ($famille->enfants as $enfant) {
                $personne = $population->personnes[$enfant] ?? null;
                $annee = null !== $personne && !$population->masquee($personne->id) ? DateRetenue::annee($personne->naissance) : null;

                if (null !== $annee) {
                    $case['annees'][] = $annee;
                }
            }

            $parGeneration[$famille->generation] = $case;
        }

        ksort($parGeneration);
        $lignes = [];

        foreach ($parGeneration as $generation => $case) {
            $resume = $case['echantillon']->resume();

            if (0 === $resume['n']) {
                continue;
            }

            $annees = $case['annees'];
            $lignes[] = [
                'generation' => $generation,
                ...$resume,
                // Arrondie à la décennie, comme la gouttière des générations de l'arbre.
                'annee' => [] === $annees ? null : (int) (round(array_sum($annees) / \count($annees) / 10) * 10),
            ];
        }

        return $lignes;
    }

    /** @return list<array<string, mixed>> */
    private function ageDesParents(Population $population): array
    {
        /** @var array<int, array{peres: Echantillon, meres: Echantillon}> $parSiecle */
        $parSiecle = [];

        foreach ($population->personnes as $enfant) {
            $annee = DateRetenue::annee($enfant->naissance);

            if (null === $annee) {
                continue;
            }

            $siecle = DateRetenue::siecle($annee);
            $parSiecle[$siecle] ??= ['peres' => new Echantillon(), 'meres' => new Echantillon()];

            foreach (['peres' => $population->pere($enfant->id), 'meres' => $population->mere($enfant->id)] as $cle => $parent) {
                if (null === $parent) {
                    continue;
                }

                $age = DateRetenue::ageEnAnnees($parent->naissance, $enfant->naissance);

                if (null === $age || $age < 12 || $age > 80) {
                    continue;
                }

                $parSiecle[$siecle][$cle]->ajouter((float) $age, $population->masquee($enfant->id) || $population->masquee($parent->id));
            }
        }

        ksort($parSiecle);
        $lignes = [];

        foreach ($parSiecle as $siecle => $case) {
            if (0 === $case['peres']->n() && 0 === $case['meres']->n()) {
                continue;
            }

            $lignes[] = ['siecle' => $siecle, 'peres' => $case['peres']->resume(), 'meres' => $case['meres']->resume()];
        }

        return $lignes;
    }

    /** @return array<string, mixed> */
    private function ecartDansLeCouple(Population $population): array
    {
        $ensemble = new Echantillon();
        /** @var array<int, Echantillon> $parSiecle */
        $parSiecle = [];

        foreach ($population->familles as $famille) {
            $ecart = $this->ecart($population, $famille);

            if (null === $ecart) {
                continue;
            }

            [$valeur, $siecle] = $ecart;
            $masque = $population->familleMasquee($famille);
            $ensemble->ajouter((float) $valeur, $masque);
            ($parSiecle[$siecle] ??= new Echantillon())->ajouter((float) $valeur, $masque);
        }

        $valeurs = $ensemble->valeurs();
        ksort($parSiecle);

        return [
            ...$ensemble->resume(),
            'mariPlusAge' => \count(array_filter($valeurs, static fn (float $v): bool => $v > 0)),
            'epousePlusAgee' => \count(array_filter($valeurs, static fn (float $v): bool => $v < 0)),
            'memeAnnee' => \count(array_filter($valeurs, static fn (float $v): bool => 0.0 === $v)),
            'parSiecle' => array_values(array_map(
                static fn (int $siecle, Echantillon $e): array => ['siecle' => $siecle, ...$e->resume()],
                array_keys($parSiecle),
                $parSiecle,
            )),
        ];
    }

    /**
     * L'écart d'âge d'un couple, en années (positif : le mari est l'aîné), et le siècle de
     * naissance du mari — `null` si l'un des deux n'a pas de naissance qui compte.
     *
     * @return array{int, int}|null
     */
    private function ecart(Population $population, FamilleDuReleve $famille): ?array
    {
        $mari = null !== $famille->mariId ? ($population->personnes[$famille->mariId] ?? null) : null;
        $epouse = null !== $famille->epouseId ? ($population->personnes[$famille->epouseId] ?? null) : null;

        $anneeMari = null !== $mari ? DateRetenue::annee($mari->naissance) : null;
        $anneeEpouse = null !== $epouse ? DateRetenue::annee($epouse->naissance) : null;

        if (null === $anneeMari || null === $anneeEpouse) {
            return null;
        }

        $ecart = $anneeEpouse - $anneeMari;

        // Plus de quarante ans d'écart : une confusion de génération à la saisie.
        return abs($ecart) > 40 ? null : [$ecart, DateRetenue::siecle($anneeMari)];
    }

    /**
     * @return array{mois: list<int>, n: int}
     */
    private function mois(Population $population, string $evenement): array
    {
        /** @var array<int, array{int, int}> $parMois total, masquées */
        $parMois = array_fill(1, 12, [0, 0]);

        foreach ($population->personnes as $personne) {
            $mois = DateRetenue::mois('naissance' === $evenement ? $personne->naissance : $personne->deces);

            if (null === $mois) {
                continue;
            }

            ++$parMois[$mois][0];
            $parMois[$mois][1] += $population->masquee($personne->id) ? 1 : 0;
        }

        $publies = array_values(array_map(static fn (array $c): int => Echantillon::comptePublie($c[0], $c[1]), $parMois));

        return ['mois' => $publies, 'n' => array_sum($publies)];
    }

    /** @return list<array{siecle: int, avantCinqAns: int, n: int}> */
    private function mortaliteInfantile(Population $population): array
    {
        /** @var array<int, array{Echantillon}> $parSiecle */
        $parSiecle = [];

        foreach ($population->personnes as $personne) {
            $annee = DateRetenue::annee($personne->naissance);

            if (null === $annee || null === DateRetenue::annee($personne->deces)) {
                continue;
            }

            $age = DateRetenue::ageEnAnnees($personne->naissance, $personne->deces);

            if (null === $age || $age > self::AGE_MAXIMAL) {
                continue;
            }

            // 1 pour un enfant mort avant cinq ans, 0 sinon : la moyenne est la proportion,
            // et le seuil de discrétion s'applique comme à toute case.
            ($parSiecle[DateRetenue::siecle($annee)] ??= [new Echantillon()])[0]->ajouter($age < 5 ? 1.0 : 0.0, $population->masquee($personne->id));
        }

        ksort($parSiecle);
        $lignes = [];

        foreach ($parSiecle as $siecle => [$echantillon]) {
            $valeurs = $echantillon->valeurs();

            if ([] === $valeurs) {
                continue;
            }

            $lignes[] = ['siecle' => $siecle, 'avantCinqAns' => (int) array_sum($valeurs), 'n' => \count($valeurs)];
        }

        return $lignes;
    }

    /**
     * @param array<int, array{hommes: Echantillon, femmes: Echantillon, ensemble: Echantillon}> $parSiecle
     */
    private function ranger(array &$parSiecle, int $siecle, Sex $sexe, float $valeur, bool $masque): void
    {
        $parSiecle[$siecle] ??= ['hommes' => new Echantillon(), 'femmes' => new Echantillon(), 'ensemble' => new Echantillon()];
        $parSiecle[$siecle]['ensemble']->ajouter($valeur, $masque);

        if (Sex::Masculin === $sexe) {
            $parSiecle[$siecle]['hommes']->ajouter($valeur, $masque);
        } elseif (Sex::Feminin === $sexe) {
            $parSiecle[$siecle]['femmes']->ajouter($valeur, $masque);
        }
    }

    /**
     * @param array<int, array{hommes: Echantillon, femmes: Echantillon, ensemble: Echantillon}> $parSiecle
     *
     * @return list<array<string, mixed>>
     */
    private function parSiecleHommesFemmes(array $parSiecle): array
    {
        ksort($parSiecle);
        $lignes = [];

        foreach ($parSiecle as $siecle => $case) {
            if (0 === $case['ensemble']->n()) {
                continue;
            }

            $lignes[] = [
                'siecle' => $siecle,
                'hommes' => $case['hommes']->resume(),
                'femmes' => $case['femmes']->resume(),
                'ensemble' => $case['ensemble']->resume(),
            ];
        }

        return $lignes;
    }
}
