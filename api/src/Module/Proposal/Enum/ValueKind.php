<?php

declare(strict_types=1);

namespace App\Module\Proposal\Enum;

/**
 * Le type sémantique de `old_value`/`new_value` (§ 5.2 du modèle de données).
 *
 * C'est lui qui dit comment interpréter le JSON et quel contrôle appliquer avant de
 * rejouer. La forme de chaque cas est un **contrat** entre le formulaire, l'affichage du
 * diff et le service de rejeu, à respecter au caractère près :
 *
 * - `Texte` : scalaire JSON string ou `null` — `"BOURRACHE"` ;
 * - `Entier` : scalaire JSON number ou `null` — `3` ;
 * - `Booleen` : scalaire JSON boolean — `true` ;
 * - `Enum` : scalaire JSON string, valeur de l'énumération — `"feminin"` ;
 * - `DateIncertaine` : objet à 5 clés, **toujours les 5** —
 *   `{"raw":…, "qualifier":…, "min":…, "max":…, "place":…}` ;
 * - `Reference` : `{kind, id, draftRef, label}`, un seul des deux parmi `id`/`draftRef` ;
 * - `Lien` : la forme sérialisée de `prp_draft_link` ;
 * - `Media` : `{mediaId, kind, caption, sha256}`.
 *
 * **Une date incertaine est un seul op, pas cinq.** Découper `raw`, `qualifier`, `min`,
 * `max` et `place` permettrait à un administrateur d'en valider trois sur cinq et de
 * fabriquer une date incohérente — exactement l'aplatissement que le § 9.1 interdit. Le
 * bloc date est atomique dans le modèle, il l'est donc aussi dans le diff, et il
 * s'affiche comme une seule ligne : « Naissance : 1987 → entre 1901 et 1909 ».
 */
enum ValueKind: string
{
    case Texte = 'texte';
    case Entier = 'entier';
    case Booleen = 'booleen';
    case Enum = 'enum';
    case DateIncertaine = 'date_incertaine';
    case Reference = 'reference';
    case Lien = 'lien';
    case Media = 'media';
}
