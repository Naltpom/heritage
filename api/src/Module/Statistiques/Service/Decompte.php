<?php

declare(strict_types=1);

namespace App\Module\Statistiques\Service;

/**
 * Un palmarès : combien de fois chaque libellé revient (une commune, un métier, un pays).
 *
 * La clé de regroupement et le libellé affiché sont séparés : « Saint-Ronce » et
 * « saint-ronce » sont le même lieu, et l'on affiche la graphie la plus fréquente. Le
 * seuil de discrétion d'{@see Echantillon} s'applique à chaque ligne du palmarès.
 */
final class Decompte
{
    /** @var array<string, array{total: int, masquees: int, graphies: array<string, int>}> */
    private array $lignes = [];

    public function ajouter(string $cle, string $libelle, bool $masque): void
    {
        $ligne = $this->lignes[$cle] ?? ['total' => 0, 'masquees' => 0, 'graphies' => []];
        ++$ligne['total'];
        $ligne['masquees'] += $masque ? 1 : 0;
        $ligne['graphies'][$libelle] = ($ligne['graphies'][$libelle] ?? 0) + 1;
        $this->lignes[$cle] = $ligne;
    }

    /**
     * Les lignes publiables, des plus fréquentes aux moins fréquentes (à égalité, par
     * ordre alphabétique — un ordre stable, pour que deux affichages se ressemblent).
     *
     * @return list<array{libelle: string, n: int}>
     */
    public function palmares(int $limite = 10): array
    {
        $publiees = [];

        foreach ($this->lignes as $ligne) {
            $n = Echantillon::comptePublie($ligne['total'], $ligne['masquees']);

            if ($n <= 0) {
                continue;
            }

            $graphies = $ligne['graphies'];
            arsort($graphies);
            $publiees[] = ['libelle' => (string) array_key_first($graphies), 'n' => $n];
        }

        usort($publiees, static fn (array $a, array $b): int => [$b['n'], $a['libelle']] <=> [$a['n'], $b['libelle']]);

        return \array_slice($publiees, 0, $limite);
    }

    /** Le nombre de lignes publiables — « 214 patronymes ». */
    public function distincts(): int
    {
        return \count($this->palmares(\PHP_INT_MAX));
    }

    /** Le total publiable, toutes lignes confondues — l'échantillon d'un palmarès. */
    public function total(): int
    {
        return array_sum(array_column($this->palmares(\PHP_INT_MAX), 'n'));
    }
}
