<?php

declare(strict_types=1);

namespace App\Module\Tree\Enum;

/**
 * Ce qu'une note — ou une citation — étaye précisément (§ 3.9 du modèle de données).
 *
 * Cette énumération résout le problème posé par le découpage du § 3.1 : la naissance et
 * le décès n'ont pas de ligne à eux, ce sont des colonnes. Un `1 BIRT / 2 NOTE` devient
 * donc une note de portée `Naissance` rattachée à la personne, et une citation de
 * portée `Naissance` peut dire « cette source étaye la naissance » sans qu'il existe une
 * ligne « naissance » à désigner.
 *
 * Réutilisée telle quelle par `tree_citation.scope` : c'est la même question.
 */
enum NoteScope: string
{
    case Generale = 'generale';
    case Naissance = 'naissance';
    case Deces = 'deces';
    case Mariage = 'mariage';
    case Divorce = 'divorce';
    case Source = 'source';

    public function label(): string
    {
        return match ($this) {
            self::Generale => 'Générale',
            self::Naissance => 'Naissance',
            self::Deces => 'Décès',
            self::Mariage => 'Mariage',
            self::Divorce => 'Divorce',
            self::Source => 'Source',
        };
    }
}
