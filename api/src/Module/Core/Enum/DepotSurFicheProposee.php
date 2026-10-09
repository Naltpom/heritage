<?php

declare(strict_types=1);

namespace App\Module\Core\Enum;

/**
 * Le verdict sur le dépôt d'une pièce **sur une personne proposée** — la réponse de
 * {@see \App\Module\Core\Contract\FichesProposeesInterface::depot()}.
 *
 * Trois issues et pas deux, parce qu'elles appellent deux réponses HTTP différentes et
 * que la troisième doit rester muette :
 *
 *  - `Joignable`   — le déposant a le droit de joindre une pièce à cette fiche : le
 *                    téléversement continue ;
 *  - `Introuvable` — la demande n'existe pas, la référence n'y existe pas, ou le déposant
 *                    n'a rien à y faire. **Les trois cas sont fondus exprès** : `404`
 *                    indistinguable, comme pour un sujet hors cercle (§ 15.3 du contrat).
 *                    Distinguer « n'existe pas » de « pas à vous » ferait de la route un
 *                    oracle d'énumération des demandes des autres ;
 *  - `Tranchee`    — la demande est **la sienne**, et elle est déjà validée, refusée,
 *                    annulée ou caduque. Son auteur sait qu'elle existe : le lui cacher
 *                    derrière un `404` ne protégerait rien et lui ferait croire à une
 *                    panne. `409 demande_deja_decidee`, le même code que valider,
 *                    refuser ou retirer une demande tranchée (§ 15.2) — le front sait déjà
 *                    qu'il doit relire.
 *
 * L'énumération vit dans `Core` et non dans `Proposal` : `Media` la consomme, et la règle
 * 3 de l'ADR 0002 ne lui laisse lire que le noyau. Elle ne porte **pas** le statut de la
 * demande — ce serait faire traverser le vocabulaire de `Proposal` (`RequestStatus`) par
 * un contrat qui n'en a pas besoin : `Media` n'a qu'une décision à prendre, pas un état
 * à afficher.
 */
enum DepotSurFicheProposee
{
    case Joignable;
    case Introuvable;
    case Tranchee;
}
