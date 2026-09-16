<?php

declare(strict_types=1);

namespace App\Module\Proposal\Enum;

/**
 * Les trois natures de lien du § 6.4 du dossier de mission, ni plus ni moins (§ 5.4).
 *
 * La demande décrit une **intention** (« conjoint de X ») ; c'est un service de `Tree`
 * qui en déduit les familles à créer, et il le fait **au moment de la validation**, pas
 * au moment de la demande.
 */
enum LinkKind: string
{
    case EnfantDe = 'enfant_de';
    case ParentDe = 'parent_de';
    case ConjointDe = 'conjoint_de';

    public function label(): string
    {
        return match ($this) {
            self::EnfantDe => 'Enfant de',
            self::ParentDe => 'Parent de',
            self::ConjointDe => 'Conjoint de',
        };
    }
}
