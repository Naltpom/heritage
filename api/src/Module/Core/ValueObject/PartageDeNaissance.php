<?php

declare(strict_types=1);

namespace App\Module\Core\ValueObject;

use App\Module\Core\Enum\NiveauDePartage;

/**
 * Ce qu'une personne vivante **partage** de sa date de naissance : un niveau
 * ({@see NiveauDePartage}) pour l'année, un pour le mois, un pour le jour — chacun
 * indépendant des deux autres (ADR 0016, révision du 2026-10-07).
 *
 * C'est à la fois le choix d'un compte (`mbr_member.birth_share_*`) et ce qu'accorde
 * l'autorisation signée d'un vivant sans compte. Ce qu'un lecteur **voit** d'un vivant,
 * lui, reste des {@see ComposantesDeDate} : {@see self::vuPar()} fait le passage.
 *
 * **Le défaut est `Proches` partout** : sans choix exprimé, les proches se voient entre
 * eux, et personne d'autre.
 */
final readonly class PartageDeNaissance
{
    public function __construct(
        public NiveauDePartage $annee = NiveauDePartage::Proches,
        public NiveauDePartage $mois = NiveauDePartage::Proches,
        public NiveauDePartage $jour = NiveauDePartage::Proches,
    ) {
    }

    public static function uniforme(NiveauDePartage $niveau): self
    {
        return new self($niveau, $niveau, $niveau);
    }

    public static function parDefaut(): self
    {
        return self::uniforme(NiveauDePartage::Proches);
    }

    /**
     * **La réciprocité** : ce que le lecteur, qui partage `$lecteur`, voit de la personne
     * qui partage `$this`. Une composante n'est vue que si **chacun la montre à l'autre** —
     * à un proche, il suffit que les deux soient au moins à `Proches` ; à un autre vivant,
     * il faut que les deux soient à `Tous`. Le lien « proche » est symétrique, d'où un seul
     * booléen.
     */
    public function vuPar(self $lecteur, bool $proche): ComposantesDeDate
    {
        return new ComposantesDeDate(
            $this->annee->montreA($proche) && $lecteur->annee->montreA($proche),
            $this->mois->montreA($proche) && $lecteur->mois->montreA($proche),
            $this->jour->montreA($proche) && $lecteur->jour->montreA($proche),
        );
    }

    /** Les composantes à `Tous` — celles que le verrou de 30 jours protège. */
    public function pourTous(): ComposantesDeDate
    {
        return new ComposantesDeDate(
            NiveauDePartage::Tous === $this->annee,
            NiveauDePartage::Tous === $this->mois,
            NiveauDePartage::Tous === $this->jour,
        );
    }

    public function niveau(string $cle): NiveauDePartage
    {
        return match ($cle) {
            'annee' => $this->annee,
            'mois' => $this->mois,
            'jour' => $this->jour,
            default => throw new \InvalidArgumentException($cle),
        };
    }

    /** Une copie où chaque composante cochée dans `$composantes` passe à `$niveau`. */
    public function avec(ComposantesDeDate $composantes, NiveauDePartage $niveau): self
    {
        return new self(
            $composantes->annee ? $niveau : $this->annee,
            $composantes->mois ? $niveau : $this->mois,
            $composantes->jour ? $niveau : $this->jour,
        );
    }

    public function egale(self $autre): bool
    {
        return $this->code() === $autre->code();
    }

    /** Trois lettres, pour les clés de cache et les empreintes : « tp- ». */
    public function code(): string
    {
        return $this->annee->code().$this->mois->code().$this->jour->code();
    }

    /** @return array{annee: string, mois: string, jour: string} */
    public function enTableau(): array
    {
        return ['annee' => $this->annee->value, 'mois' => $this->mois->value, 'jour' => $this->jour->value];
    }
}
