<?php

declare(strict_types=1);

namespace App\Module\Statistiques\Service;

/**
 * Un échantillon de valeurs pour une moyenne — et **le seuil de discrétion**.
 *
 * ═══════════════════════════════════════════════════════════════════════════════
 *  POURQUOI UNE MOYENNE A BESOIN D'UNE RÈGLE DE CONFIDENTIALITÉ
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * Le commanditaire a tranché (02/10/2026) : **les agrégats anonymes comptent tout le
 * monde**, y compris les fiches que le lecteur ne peut pas ouvrir. Mais une moyenne n'est
 * anonyme que si elle porte sur plusieurs personnes. « Âge moyen au premier mariage des
 * femmes nées au XXIᵉ siècle : 27 ans, sur 1 personne » publie l'âge au mariage d'une
 * personne précise — et si cette personne a une fiche confidentielle, c'est une donnée
 * masquée qui sort par la porte d'un graphique. L'ADR 0003 le dit en une ligne : « tout
 * champ dérivé d'une donnée masquée est lui-même une donnée masquée ».
 *
 * D'où la règle, appliquée case par case (une barre, un mois, une commune) :
 *
 * > **Une case qui compte au moins une fiche invisible du lecteur n'est publiée avec
 * > elle que si elle réunit au moins {@see SEUIL} contributions. Sinon, les fiches
 * > invisibles en sont retirées** — la case est calculée sur les seules fiches visibles.
 *
 * Pour l'administrateur (cercle complet) et pour un membre devant un arbre sans fiche
 * confidentielle hors de son cercle, la règle ne retire rien : elle ne mord que là où elle
 * protège quelqu'un. Et elle ne dit pas qu'elle a mordu — un « (1 fiche retirée) » serait
 * lui-même l'information qu'on retient.
 */
final class Echantillon
{
    /** En dessous de cinq contributions, une case qui contient une fiche invisible la perd. */
    public const int SEUIL = 5;

    /** @var list<array{float, bool}> */
    private array $valeurs = [];

    public function ajouter(float $valeur, bool $masque): void
    {
        $this->valeurs[] = [$valeur, $masque];
    }

    /**
     * Les valeurs publiables, après le seuil de discrétion.
     *
     * @return list<float>
     */
    public function valeurs(): array
    {
        $toutes = $this->valeurs;
        $avecMasquees = array_filter($toutes, static fn (array $v): bool => $v[1]);

        if ([] !== $avecMasquees && \count($toutes) < self::SEUIL) {
            $toutes = array_filter($toutes, static fn (array $v): bool => !$v[1]);
        }

        return array_values(array_map(static fn (array $v): float => $v[0], $toutes));
    }

    public function n(): int
    {
        return \count($this->valeurs());
    }

    /** La moyenne arrondie au dixième, `null` sur un échantillon vide. */
    public function moyenne(): ?float
    {
        $valeurs = $this->valeurs();

        return [] === $valeurs ? null : round(array_sum($valeurs) / \count($valeurs), 1);
    }

    /** La médiane au sens strict (moyenne des deux valeurs centrales sur un effectif pair). */
    public function mediane(): ?float
    {
        $valeurs = $this->valeurs();

        if ([] === $valeurs) {
            return null;
        }

        sort($valeurs);
        $n = \count($valeurs);
        $milieu = intdiv($n, 2);

        return round(0 === $n % 2 ? ($valeurs[$milieu - 1] + $valeurs[$milieu]) / 2 : $valeurs[$milieu], 1);
    }

    /**
     * `{moyenne, n}` — la forme d'une case dans la charge.
     *
     * @return array{moyenne: float|null, n: int}
     */
    public function resume(): array
    {
        return ['moyenne' => $this->moyenne(), 'n' => $this->n()];
    }

    /**
     * Le même seuil pour un **compte** (une commune, un mois, un prénom) : `$total`
     * contributions dont `$masquees` invisibles. Rend le compte publiable.
     */
    public static function comptePublie(int $total, int $masquees): int
    {
        return $masquees > 0 && $total < self::SEUIL ? $total - $masquees : $total;
    }
}
