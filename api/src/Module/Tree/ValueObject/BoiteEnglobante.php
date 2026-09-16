<?php

declare(strict_types=1);

namespace App\Module\Tree\ValueObject;

/**
 * La boîte englobante du graphe, marge de 60 comprise.
 *
 * Elle alimente **directement** le `viewBox` initial du viewer (ADR 0007) et n'est
 * jamais recalculée côté client : le zoom passe par le `viewBox`, jamais par
 * `transform: scale()` — avec `scale()`, Chrome rastérise les 15 824 px entiers à
 * l'échelle demandée (§ 7.2 du dossier de mission, piège déjà payé une fois).
 *
 * Sur les données de référence : `-7770.390869140625 -1730 15824 1810`.
 */
final readonly class BoiteEnglobante
{
    public function __construct(
        public float $x,
        public float $y,
        public float $w,
        public float $h,
    ) {
    }

    public function viewBox(): string
    {
        return \sprintf('%s %s %s %s', $this->x, $this->y, $this->w, $this->h);
    }
}
