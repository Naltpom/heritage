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

    /**
     * Effacer une fiche de l'arbre, et tout ce qu'elle porte.
     *
     * ── Pourquoi une nature d'op et pas seulement une nature de demande ──────────────
     *
     * Parce que c'est un op qui porte `base_version`, et que la suppression d'une fiche
     * est une écriture **sur cette fiche** : elle doit entrer en conflit avec les autres.
     * Une nature de demande sans op — comme `creation_personne` — n'a rien à confronter à
     * l'état courant, et c'est exactement ce dont une suppression a besoin.
     *
     * C'est aussi un op qui porte `old_value`, et c'est là que vit **l'inventaire de ce
     * que la suppression emporte** au moment du dépôt. Le contrôle préalable relit
     * l'inventaire courant et le compare : une note ajoutée entre le dépôt et la
     * validation rend la demande **caduque**, parce que l'administrateur s'apprêterait
     * sinon à effacer une ligne que personne ne lui a montrée. Voir
     * {@see \App\Module\Proposal\Contract\InventaireDeSuppression}.
     */
    case SuppressionPersonne = 'suppression_personne';
}
