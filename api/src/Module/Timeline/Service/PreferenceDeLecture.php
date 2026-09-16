<?php

declare(strict_types=1);

namespace App\Module\Timeline\Service;

use App\Module\Core\Enum\Branch;
use App\Module\Timeline\Entity\Preference;
use App\Module\Timeline\Enum\TimelineOrientation;

/**
 * La préférence d'un lecteur, sous une forme que le reste du module manipule sans
 * toucher à l'entité — quatre valeurs, aucune identité, aucun `EntityManager`.
 *
 * Elle existe parce que `GET /api/chronologie` doit répondre à un compte qui **n'a jamais
 * enregistré de préférence**, et qu'il n'y a alors aucune ligne `tml_preference` à lire.
 * Faire répondre {@see ServiceDesPreferences} par une entité obligerait à en créer une —
 * c'est-à-dire à **écrire en base au premier GET**, exactement ce que le § 9.1 interdit :
 * *« un paramètre fourni n'écrit pas la préférence — c'est `PUT` qui écrit. Sans cette
 * séparation, chaque exploration d'un réglage devient une écriture, et la préférence ne
 * veut plus rien dire. »* La même phrase vaut pour la simple lecture.
 */
final readonly class PreferenceDeLecture
{
    /** @param list<Branch> $branches */
    public function __construct(
        public int $bandYears,
        public TimelineOrientation $orientation,
        public array $branches,
        public bool $showUncertainty,
    ) {
    }

    /**
     * Le défaut servi tant qu'aucune ligne n'existe.
     *
     * `showUncertainty` vaut `true`, « parce que masquer l'incertitude est précisément ce
     * que le § 4 du dossier interdit » (§ 9.2). Ce n'est pas un défaut d'ergonomie qu'on
     * pourrait retourner : c'est la règle de fond du produit, écrite en valeur par défaut.
     */
    public static function defaut(int $bandeParDefaut): self
    {
        return new self($bandeParDefaut, TimelineOrientation::Lignes, Branch::cases(), true);
    }

    public static function depuisLEntite(Preference $preference): self
    {
        return new self(
            $preference->getBandYears(),
            $preference->getOrientation(),
            $preference->getBranches(),
            $preference->isShowUncertainty(),
        );
    }

    /** @return array{bandYears: int, orientation: string, branches: list<string>, showUncertainty: bool} */
    public function enJson(): array
    {
        return [
            'bandYears' => $this->bandYears,
            'orientation' => $this->orientation->value,
            'branches' => array_map(static fn (Branch $b): string => $b->value, $this->branches),
            'showUncertainty' => $this->showUncertainty,
        ];
    }
}
