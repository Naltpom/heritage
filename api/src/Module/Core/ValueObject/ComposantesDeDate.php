<?php

declare(strict_types=1);

namespace App\Module\Core\ValueObject;

/**
 * Les composantes d'une date de naissance qu'un lecteur a le droit de voir — **année,
 * mois, jour**, chacune indépendante des deux autres (ADR 0016, réciprocité des vivants).
 *
 * C'est à la fois ce qu'un compte **consent à partager** (`mbr_member.birth_share_*`), ce
 * qu'une autorisation signée **accorde** pour un vivant sans compte, et ce qu'un lecteur
 * **voit** d'un vivant — l'intersection des deux premiers. Un seul type pour les trois,
 * parce que la règle « je vois ce que je partage » est littéralement {@see inter()}.
 *
 * Les combinaisons sont libres : « jour + mois sans année » est le cas de qui veut
 * apparaître au calendrier des anniversaires sans dire son âge.
 */
final readonly class ComposantesDeDate
{
    public const int ANNEE = 1;
    public const int MOIS = 2;
    public const int JOUR = 4;

    public function __construct(
        public bool $annee = false,
        public bool $mois = false,
        public bool $jour = false,
    ) {
    }

    public static function tout(): self
    {
        return new self(true, true, true);
    }

    public static function aucune(): self
    {
        return new self();
    }

    /** L'inverse de {@see masque()} : les bits au-delà de 7 sont ignorés. */
    public static function depuisMasque(int $masque): self
    {
        return new self(
            0 !== ($masque & self::ANNEE),
            0 !== ($masque & self::MOIS),
            0 !== ($masque & self::JOUR),
        );
    }

    /** La réciprocité : une composante n'est vue que si les deux côtés la partagent. */
    public function inter(self $autre): self
    {
        return new self(
            $this->annee && $autre->annee,
            $this->mois && $autre->mois,
            $this->jour && $autre->jour,
        );
    }

    public function estComplete(): bool
    {
        return $this->annee && $this->mois && $this->jour;
    }

    public function estVide(): bool
    {
        return !$this->annee && !$this->mois && !$this->jour;
    }

    /** Un entier de 0 à 7, pour les clés de cache et les empreintes. */
    public function masque(): int
    {
        return ($this->annee ? self::ANNEE : 0) | ($this->mois ? self::MOIS : 0) | ($this->jour ? self::JOUR : 0);
    }

    /** @return array{annee: bool, mois: bool, jour: bool} */
    public function enTableau(): array
    {
        return ['annee' => $this->annee, 'mois' => $this->mois, 'jour' => $this->jour];
    }
}
