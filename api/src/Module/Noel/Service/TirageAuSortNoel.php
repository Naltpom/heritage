<?php

declare(strict_types=1);

namespace App\Module\Noel\Service;

use Random\Randomizer;

/**
 * Qui offre à qui, sans base ni horloge : personne ne se tire soi-même, personne ne tire
 * son conjoint, chacun reçoit exactement un cadeau.
 *
 * La même recherche que `Tirage\Service\TirageAuSort` (retour sur trace, les plus
 * contraints d'abord, candidats mélangés par le générateur fourni), sans la mémoire des
 * années passées : un tirage de Noël n'a lieu qu'une fois. Le module ne peut pas appeler
 * celui de `Tirage` (ADR 0002 : on ne franchit une frontière que par un `Contract`).
 */
final class TirageAuSortNoel
{
    /** Trois, pas deux : à deux, chacun sait qui l'a tiré. */
    public const int PARTICIPANTS_MIN = 3;

    private const int ETAPES_MAX = 200_000;

    private int $etapes = 0;

    /**
     * @param list<int>                   $comptes les participants
     * @param list<array{0: int, 1: int}> $couples ceux qui ne se tirent pas l'un l'autre
     *
     * @return array<int, int>|null donneur ⇒ receveur, ou `null` si c'est impossible
     */
    public function tirer(array $comptes, array $couples, Randomizer $hasard): ?array
    {
        $comptes = array_values(array_unique($comptes));

        if (\count($comptes) < self::PARTICIPANTS_MIN) {
            return null;
        }

        $interdits = [];
        foreach ($couples as [$un, $autre]) {
            $interdits[$un][$autre] = true;
            $interdits[$autre][$un] = true;
        }

        $candidats = [];
        foreach ($comptes as $donneur) {
            $possibles = array_values(array_filter(
                $comptes,
                static fn (int $receveur): bool => $receveur !== $donneur && !isset($interdits[$donneur][$receveur]),
            ));

            if ([] === $possibles) {
                return null;
            }

            $candidats[$donneur] = $hasard->shuffleArray($possibles);
        }

        $donneurs = $hasard->shuffleArray($comptes);
        usort($donneurs, static fn (int $a, int $b): int => \count($candidats[$a]) <=> \count($candidats[$b]));

        $this->etapes = 0;
        $attributions = [];
        $pris = [];

        return $this->placer($donneurs, 0, $candidats, $attributions, $pris) ? $attributions : null;
    }

    /**
     * @param list<int>             $donneurs
     * @param array<int, list<int>> $candidats
     * @param array<int, int>       $attributions
     * @param array<int, true>      $pris
     */
    private function placer(array $donneurs, int $rang, array $candidats, array &$attributions, array &$pris): bool
    {
        if ($rang === \count($donneurs)) {
            return true;
        }

        $donneur = $donneurs[$rang];

        foreach ($candidats[$donneur] as $receveur) {
            if (isset($pris[$receveur])) {
                continue;
            }

            if (++$this->etapes > self::ETAPES_MAX) {
                return false;
            }

            $attributions[$donneur] = $receveur;
            $pris[$receveur] = true;

            if ($this->placer($donneurs, $rang + 1, $candidats, $attributions, $pris)) {
                return true;
            }

            unset($attributions[$donneur], $pris[$receveur]);
        }

        return false;
    }
}
