<?php

declare(strict_types=1);

namespace App\Module\Timeline\Service;

/**
 * Une bande de l'axe du temps : `{debut, fin, libelle}` du § 9.1.
 *
 * `fin` est **inclusive** et peut dépasser `bornes.max` sur la dernière bande — c'est
 * « correct et voulu » au sens littéral du § 9.1, pas un dépassement à rattraper.
 */
final readonly class Bande
{
    public function __construct(
        public int $debut,
        public int $fin,
    ) {
    }

    /**
     * `1646–1670` — **demi-cadratin (U+2013) sans espaces**, comme l'écrit le § 9.1.
     *
     * À ne pas confondre avec le séparateur de `ligneDates`, qui est le même signe
     * **entouré d'espaces** (« entre 1901 et 1909 – 1987 »), repris de `graphe.json`. Deux
     * usages du même caractère, deux espacements ; un test compare ces chaînes au signe
     * près, et c'est le genre de détail qui ne se redevine pas.
     */
    public function libelle(): string
    {
        return $this->debut.'–'.$this->fin;
    }

    /** @return array{debut: int, fin: int, libelle: string} */
    public function enJson(): array
    {
        return [
            'debut' => $this->debut,
            'fin' => $this->fin,
            'libelle' => $this->libelle(),
        ];
    }
}
