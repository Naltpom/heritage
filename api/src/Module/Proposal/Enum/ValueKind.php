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
 * - `Media` : `{mediaId, kind, caption, sha256}` ;
 * - `Citation` : `{sourceId, sourceTitle, page, quay, dataText, scope}` ;
 * - `Note` : `{body, scope}` ;
 * - `FinDeRecherche` : objet à 3 clés, **toujours les 3**, ou `null` —
 *   `{"reason":…, "beforeYear":…, "body":…}`. Un côté clos d'une personne (ADR 0023) ;
 *   `null` est sa valeur « pas de fin de recherche » : pose `null → {…}`, levée `{…} → null` ;
 * - `Suppression` : l'inventaire de ce qu'une suppression emporte —
 *   `{personneId, displayName, notes, citations, faits, filiations, unions, descendants,
 *   medias}`, dans cet ordre exact. Voir
 *   {@see \App\Module\Proposal\Contract\InventaireDeSuppression}.
 *
 * **Pourquoi `Citation` n'est pas un `Reference` sur la source.** Une citation n'est pas
 * un lien vers une source : c'est **l'usage** d'une source sur un fait précis, avec sa
 * page et son `QUAY` (§ 3.7 du modèle). Les quatre valeurs qui la qualifient sont
 * solidaires — une page sans sa source ne retrouve rien, un `QUAY` sans sa page ne se
 * vérifie pas — et la même raison qui rend un bloc date atomique la rend atomique : un
 * administrateur qui pourrait en valider la moitié fabriquerait une citation qu'aucune
 * main n'a écrite. `sourceTitle` est **en lecture seule**, recopié depuis la ligne au
 * dépôt : il sert à afficher le diff sans jointure, jamais à décider (§ 0.4 du modèle).
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
    case Citation = 'citation';
    case Note = 'note';
    /**
     * La fin de recherche d'un côté d'une personne (ADR 0023) : `{reason, beforeYear, body}`
     * ou `null`. Seize caractères : tient pile dans `prp_change_op.value_kind VARCHAR(16)`.
     */
    case FinDeRecherche = 'fin_de_recherche';
    /**
     * L'inventaire de ce qu'une suppression emporte.
     *
     * **C'est le seul `ValueKind` qui vit dans `old_value` sans jumeau dans `new_value`**
     * : une suppression n'a pas d'« après », `new_value` vaut `null`, et l'« avant »
     * n'est pas la valeur d'un champ mais le décompte de ce qui disparaîtra. C'est
     * délibérément cette forme-là qui est stockée plutôt qu'un simple identifiant :
     * l'inventaire du dépôt est ce à quoi le contrôle préalable compare l'inventaire
     * courant, et c'est cette comparaison qui empêche d'effacer plus que ce qui a été
     * annoncé.
     *
     * L'ordre des clés est un contrat, pour la même raison qu'un bloc date :
     * la comparaison se fait sur le tableau entier, et `JSONB` ne préserve pas l'ordre
     * d'écriture. Les deux côtés repassent donc par
     * {@see \App\Module\Proposal\Contract\InventaireDeSuppression::depuisJson()}.
     */
    case Suppression = 'suppression';
}
