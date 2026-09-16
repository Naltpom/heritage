<?php

declare(strict_types=1);

namespace App\Module\Tree\ValueObject;

/**
 * Un nœud positionné : une fiche de personne (40 px de haut) ou un nœud de jonction
 * familial (le losange 22 × 14 du § 7.1 du dossier de mission — **pas** un petit cercle,
 * l'erreur a été commise une fois en déduisant l'algorithme de sa sortie).
 *
 * `x` et `y` sont les coordonnées du **centre**, pas du coin : c'est la convention du
 * script d'origine, et la boîte englobante s'en déduit par `x ± w / 2`.
 *
 * `w` et `h` restent des entiers parce que le moteur les produit entiers — la largeur
 * de fiche passe par une troncature (`int()` en Python, `(int)` en PHP, tous deux vers
 * zéro). Les rendre flottants inviterait à les recalculer autrement.
 */
final readonly class NoeudPlace
{
    public function __construct(
        public string $id,
        public float $x,
        public float $y,
        public int $w,
        public int $h,
    ) {
    }

    /** Translation horizontale — le recentrage sur l'ego, et rien d'autre. */
    public function decaleDe(float $dx): self
    {
        return new self($this->id, $this->x + $dx, $this->y, $this->w, $this->h);
    }

    public function gauche(): float
    {
        return $this->x - $this->w / 2;
    }

    public function droite(): float
    {
        return $this->x + $this->w / 2;
    }

    public function haut(): float
    {
        return $this->y - $this->h / 2;
    }

    public function bas(): float
    {
        return $this->y + $this->h / 2;
    }
}
