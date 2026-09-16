<?php

declare(strict_types=1);

namespace App\Module\Tree\Enum;

/**
 * Le `2 EVEN` de MyHeritage : d'où vient la citation (§ 3.9 du modèle de données).
 *
 * **C'est le champ qui a servi à la requalification des `QUAY`** décrite dans le
 * `README.md` du chantier : une citation issue d'une *Person Discovery* est une
 * recopie, pas une preuve. On le garde pour pouvoir refaire le tri — sans lui, la
 * requalification n'est plus vérifiable ni révisable.
 */
enum CitationContext: string
{
    case Discovery = 'discovery';
    case Record = 'record';
    case SmartMatching = 'smart_matching';
    case Autre = 'autre';

    public function label(): string
    {
        return match ($this) {
            self::Discovery => 'Suggestion MyHeritage',
            self::Record => 'Fiche de collection',
            self::SmartMatching => 'Rapprochement automatique',
            self::Autre => 'Autre',
        };
    }
}
