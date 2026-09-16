<?php

declare(strict_types=1);

namespace App\Module\Proposal\Enum;

/**
 * Ce que fait un op (§ 5.7 du modèle de données).
 *
 * Seul `Champ` nomme un champ ; les autres n'en nomment pas, et la contrainte
 * `ck_prp_op_champ` fait de cette équivalence une règle de base plutôt qu'une discipline
 * de service.
 */
enum OpKind: string
{
    case Champ = 'champ';
    case AjoutLien = 'ajout_lien';
    case RetraitLien = 'retrait_lien';
    case AjoutMedia = 'ajout_media';
    case RetraitMedia = 'retrait_media';
    case AjoutCitation = 'ajout_citation';
    case RetraitCitation = 'retrait_citation';
    case AjoutNote = 'ajout_note';
}
