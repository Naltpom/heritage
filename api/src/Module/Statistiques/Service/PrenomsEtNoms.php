<?php

declare(strict_types=1);

namespace App\Module\Statistiques\Service;

use App\Module\Core\Enum\Sex;
use App\Module\Statistiques\Contract\PersonneDuReleve;

/**
 * Section 3 (suite) — **les prénoms et les noms de famille**.
 *
 * ── Seulement les fiches que le lecteur voit ────────────────────────────────────
 *
 * Un palmarès de prénoms n'est pas une moyenne : chaque ligne **est** un nom. Le nom est
 * précisément ce qu'une fiche confidentielle retient (ADR 0003, `NOM_MASQUE`), et un
 * prénom rare compté une fois le publierait. Ces palmarès se calculent donc sur les seules
 * fiches visibles — pour l'administrateur et presque tous les membres, c'est l'arbre entier.
 *
 * ── Le prénom retenu ────────────────────────────────────────────────────────────
 *
 * Le **premier prénom** inscrit, celui d'usage le plus souvent : « Marie » en second
 * prénom est presque universel dans les registres d'autrefois, et le compter
 * transformerait chaque palmarès en une liste de Marie. Un prénom composé (« Jean-Baptiste »)
 * reste entier. La comparaison ignore la casse ; l'affichage garde la graphie la plus
 * fréquente.
 *
 * ── « Transmis sur plusieurs générations » ──────────────────────────────────────
 *
 * Une **chaîne** : un parent et son enfant qui portent le même premier prénom, puis cet
 * enfant et le sien, et ainsi de suite. On publie les prénoms dont la plus longue chaîne
 * couvre au moins trois générations, avec le premier porteur connu de cette chaîne.
 */
final class PrenomsEtNoms
{
    private const int GENERATIONS_MINIMALES = 3;

    /** @return array<string, mixed> */
    public function calculer(Population $population): array
    {
        return [
            'prenomsParSiecle' => $this->parSiecle($population),
            'prenomsTransmis' => $this->transmis($population),
            'noms' => $this->noms($population),
        ];
    }

    /** Le premier prénom, ou `null`. */
    public static function premierPrenom(PersonneDuReleve $personne): ?string
    {
        $morceaux = preg_split('/[\s,]+/u', trim($personne->prenoms));
        $premier = false === $morceaux ? '' : ($morceaux[0] ?? '');

        // « ? », « X », « NN » : les marques d'un prénom inconnu ne sont pas des prénoms.
        if (mb_strlen($premier) < 2 || 1 !== preg_match('/\p{L}/u', $premier) || \in_array(mb_strtoupper($premier), ['NN', 'XX', 'INCONNU', 'INCONNUE'], true)) {
            return null;
        }

        return $premier;
    }

    /** @return list<array<string, mixed>> */
    private function parSiecle(Population $population): array
    {
        /** @var array<int, array{hommes: Decompte, femmes: Decompte, n: int}> $parSiecle */
        $parSiecle = [];

        foreach ($this->visibles($population) as $personne) {
            $annee = DateRetenue::annee($personne->naissance);
            $prenom = self::premierPrenom($personne);

            if (null === $annee || null === $prenom || !\in_array($personne->sex, [Sex::Masculin, Sex::Feminin], true)) {
                continue;
            }

            $siecle = DateRetenue::siecle($annee);
            $parSiecle[$siecle] ??= ['hommes' => new Decompte(), 'femmes' => new Decompte(), 'n' => 0];
            $parSiecle[$siecle][Sex::Masculin === $personne->sex ? 'hommes' : 'femmes']->ajouter(mb_strtolower($prenom), $prenom, false);
            ++$parSiecle[$siecle]['n'];
        }

        ksort($parSiecle);
        $lignes = [];

        foreach ($parSiecle as $siecle => $case) {
            $lignes[] = [
                'siecle' => $siecle,
                'hommes' => $case['hommes']->palmares(5),
                'femmes' => $case['femmes']->palmares(5),
                'n' => $case['n'],
            ];
        }

        return $lignes;
    }

    /** @return list<array<string, mixed>> */
    private function transmis(Population $population): array
    {
        /** @var array<int, array{int, int}> $memoire personne → [longueur de chaîne, premier porteur] */
        $memoire = [];

        $chaine = function (PersonneDuReleve $personne, string $cle) use (&$chaine, &$memoire, $population): array {
            if (isset($memoire[$personne->id])) {
                return $memoire[$personne->id];
            }

            $memoire[$personne->id] = [1, $personne->id]; // garde contre un cycle de saisie
            $meilleure = [1, $personne->id];

            foreach ([$population->pere($personne->id), $population->mere($personne->id)] as $parent) {
                if (null === $parent || $population->masquee($parent->id)) {
                    continue;
                }

                $prenom = self::premierPrenom($parent);

                if (null === $prenom || mb_strtolower($prenom) !== $cle) {
                    continue;
                }

                [$longueur, $origine] = $chaine($parent, $cle);

                if ($longueur + 1 > $meilleure[0]) {
                    $meilleure = [$longueur + 1, $origine];
                }
            }

            return $memoire[$personne->id] = $meilleure;
        };

        /** @var array<string, array{prenom: string, generations: int, origine: int, porteurs: int}> $parPrenom */
        $parPrenom = [];

        foreach ($this->visibles($population) as $personne) {
            $prenom = self::premierPrenom($personne);

            if (null === $prenom) {
                continue;
            }

            $cle = mb_strtolower($prenom);
            [$longueur, $origine] = $chaine($personne, $cle);
            $actuel = $parPrenom[$cle] ?? ['prenom' => $prenom, 'generations' => 0, 'origine' => $origine, 'porteurs' => 0];
            ++$actuel['porteurs'];

            if ($longueur > $actuel['generations']) {
                $actuel['generations'] = $longueur;
                $actuel['origine'] = $origine;
            }

            $parPrenom[$cle] = $actuel;
        }

        $retenus = array_filter($parPrenom, static fn (array $p): bool => $p['generations'] >= self::GENERATIONS_MINIMALES);
        usort($retenus, static fn (array $a, array $b): int => [$b['generations'], $b['porteurs'], $a['prenom']] <=> [$a['generations'], $a['porteurs'], $b['prenom']]);

        return array_map(function (array $p) use ($population): array {
            $origine = $population->personnes[$p['origine']];

            return [
                'prenom' => $p['prenom'],
                'generations' => $p['generations'],
                'porteurs' => $p['porteurs'],
                'depuis' => $population->reference($origine),
                'annee' => DateRetenue::annee($origine->naissance),
            ];
        }, \array_slice($retenus, 0, 8));
    }

    /** @return array<string, mixed> */
    private function noms(Population $population): array
    {
        $decompte = new Decompte();

        foreach ($this->visibles($population) as $personne) {
            $nom = trim($personne->nom);

            if (mb_strlen($nom) < 2 || 1 !== preg_match('/\p{L}/u', $nom)) {
                continue;
            }

            $decompte->ajouter(mb_strtoupper($nom), $nom, false);
        }

        return [
            'frequents' => $decompte->palmares(10),
            'patronymes' => $decompte->distincts(),
            'n' => $decompte->total(),
        ];
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
}
