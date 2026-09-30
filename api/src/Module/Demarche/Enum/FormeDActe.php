<?php

declare(strict_types=1);

namespace App\Module\Demarche\Enum;

/**
 * La forme demandée. Elle n'est pas un détail : elle décide de ce qu'on a le droit
 * d'obtenir. Une copie intégrale d'un acte de moins de soixante-quinze ans est réservée à
 * l'intéressé, à ses ascendants, descendants et époux ; un extrait sans filiation se
 * délivre à quiconque. Garder la forme, c'est savoir plus tard pourquoi une mairie a
 * répondu ce qu'elle a répondu.
 */
enum FormeDActe: string
{
    case CopieIntegrale = 'copie_integrale';
    case ExtraitAvecFiliation = 'extrait_filiation';
    case ExtraitSansFiliation = 'extrait_sans_filiation';
    case Autre = 'autre';

    public function libelle(): string
    {
        return match ($this) {
            self::CopieIntegrale => 'Copie intégrale',
            self::ExtraitAvecFiliation => 'Extrait avec filiation',
            self::ExtraitSansFiliation => 'Extrait sans filiation',
            self::Autre => 'Autre forme',
        };
    }
}
