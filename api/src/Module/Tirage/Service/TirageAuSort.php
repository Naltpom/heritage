<?php

declare(strict_types=1);

namespace App\Module\Tirage\Service;

use App\Module\Tirage\Exception\ErreurDeTirage;
use Random\Randomizer;

/**
 * Le tirage lui-même : qui offre à qui, sans base, sans session, sans horloge.
 *
 * ## Les règles, de la plus dure à la plus souple
 *
 * 1. **Personne ne se tire soi-même**, et chacun reçoit exactement un cadeau. C'est ce qui
 *    fait un tirage ; cette règle ne se relâche jamais.
 * 2. **On ne tire pas son conjoint**, si le tirage le demande. Elle ne se relâche pas non
 *    plus : c'est une règle que l'organisateur a choisie, et la contourner en silence lui
 *    rendrait un tirage qu'il a exclu.
 * 3. **On ne retombe pas sur la même personne pendant N éditions.** C'est la seule qui se
 *    relâche : N, puis N − 1, … jusqu'à 0. Sur un petit groupe elle devient vite
 *    impossible — avec six participants en trois couples, chacun n'a que quatre
 *    personnes possibles, et trois ans suffisent à les épuiser. Plutôt que de refuser de
 *    tirer, on oublie d'abord l'année la plus ancienne, et le résultat dit combien d'années
 *    ont été respectées.
 *
 * ## La recherche
 *
 * Un retour sur trace, les donneurs les plus contraints d'abord, chaque liste de
 * candidats mélangée par le générateur fourni. Le hasard vient de l'appelant : en
 * production c'est `Randomizer` sur le générateur sûr par défaut, en test un générateur à
 * graine fixe — un tirage qu'on ne sait pas rejouer est un tirage qu'on ne sait pas
 * tester.
 *
 * Le nombre d'étapes est borné. Au-delà, la mémoire en cours est tenue pour impossible et
 * l'on relâche : sur une famille, la borne n'est jamais atteinte, mais une boucle sans
 * borne dans une requête HTTP est un défaut qu'on ne laisse pas au hasard.
 */
final class TirageAuSort
{
    /**
     * Trois, pas deux : à deux, chacun sait qui l'a tiré, et le secret est perdu d'avance.
     */
    public const int PARTICIPANTS_MIN = 3;

    private const int ETAPES_MAX = 200_000;

    private int $etapes = 0;

    /**
     * @param list<int>                   $personnes  les participants présents cette année
     * @param list<array{0: int, 1: int}> $couples    ceux qui ne se tirent pas l'un l'autre
     * @param list<array<int, int>>       $historique les éditions passées, **la plus récente
     *                                                d'abord**, donneur ⇒ receveur
     *
     * @throws ErreurDeTirage
     */
    public function tirer(array $personnes, array $couples, array $historique, int $memoire, Randomizer $hasard): ResultatDuTirage
    {
        $personnes = array_values(array_unique($personnes));

        if (\count($personnes) < self::PARTICIPANTS_MIN) {
            throw ErreurDeTirage::tropPeuDeParticipants(self::PARTICIPANTS_MIN);
        }

        $interditsFixes = [];
        foreach ($couples as [$un, $autre]) {
            $interditsFixes[$un][$autre] = true;
            $interditsFixes[$autre][$un] = true;
        }

        for ($respectees = max(0, $memoire); $respectees >= 0; --$respectees) {
            $interdits = $interditsFixes;

            foreach (\array_slice($historique, 0, $respectees) as $edition) {
                foreach ($edition as $donneur => $receveur) {
                    $interdits[$donneur][$receveur] = true;
                }
            }

            $attributions = $this->chercher($personnes, $interdits, $hasard);

            if (null !== $attributions) {
                return new ResultatDuTirage($attributions, $respectees);
            }
        }

        throw ErreurDeTirage::impossible();
    }

    /**
     * La mémoire la plus longue qui laisse à chacun au moins une personne à tirer.
     *
     * Ce n'est pas une garantie — deux participants peuvent n'avoir que le même candidat —,
     * c'est l'avertissement que l'écran de réglage affiche : au-delà, le tirage oubliera
     * des années à coup sûr.
     */
    public static function memoireConseillee(int $participants, bool $exclureConjoints): int
    {
        return max(0, $participants - 2 - ($exclureConjoints ? 1 : 0));
    }

    /**
     * @param list<int>                    $personnes
     * @param array<int, array<int, true>> $interdits donneur ⇒ receveurs interdits
     *
     * @return array<int, int>|null
     */
    private function chercher(array $personnes, array $interdits, Randomizer $hasard): ?array
    {
        $candidats = [];
        foreach ($personnes as $donneur) {
            $possibles = array_values(array_filter(
                $personnes,
                static fn (int $receveur): bool => $receveur !== $donneur && !isset($interdits[$donneur][$receveur]),
            ));

            if ([] === $possibles) {
                return null;
            }

            $candidats[$donneur] = $hasard->shuffleArray($possibles);
        }

        // Les plus contraints d'abord ; à contrainte égale, l'ordre du mélange.
        $donneurs = $hasard->shuffleArray($personnes);
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
