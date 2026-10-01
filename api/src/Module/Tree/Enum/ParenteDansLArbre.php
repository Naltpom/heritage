<?php

declare(strict_types=1);

namespace App\Module\Tree\Enum;

/**
 * **Qui est « de la famille » du lecteur** — la clé `parente` d'un nœud de personne du
 * graphe (§ 5.2 du contrat d'API).
 *
 * ── LA RÈGLE, MOT POUR MOT ──────────────────────────────────────────────────────
 *
 * Décision du commanditaire, 2026-10-01, après les captures « voir comme Camille » : la
 * cousine germaine du commanditaire voyait toute l'ascendance du père de celui-ci —
 * Provost, Bornerie, Savignac, Lalot… huit générations — rangée dans **sa** branche
 * « Voulgre », alors qu'elle n'a aucun lien de sang avec elle : c'est l'ascendance du
 * **mari de sa tante**.
 *
 * - {@see Famille} : les ancêtres du lecteur, **les descendants de ses ancêtres** (lui, sa
 *   descendance, sa fratrie, ses cousins à tout degré), et **les conjoints de toutes ces
 *   personnes** — le conjoint lui-même, pas sa famille. Le mari de la tante est donc
 *   « famille » : il est le pont, et il reste à côté de sa femme ;
 * - {@see Conjoint} : la famille du conjoint **du lecteur** — ce que le filtre « Famille du
 *   conjoint » montrait déjà. Inchangé ;
 * - {@see Alliance} : **tout le reste** — l'ascendance et la parenté d'un conjoint de
 *   collatéral, et ce qui s'y rattache de proche en proche. Masqué par défaut, montré par
 *   le filtre « Familles par alliance », chacune dans son propre bloc. **Un îlot qu'aucun
 *   chemin ne relie au lecteur n'en est pas** — il n'est relié à rien : il est servi
 *   {@see Famille} et garde son sort d'avant (sans branche, zone « sans lien connu »).
 *
 * ── CE QUE CE CHAMP APPREND À UN LECTEUR, ET POURQUOI CE N'EST RIEN ──────────────
 *
 * Il se **déduit des arêtes**, que tout lecteur reçoit, y compris pour une personne
 * vivante hors cercle : c'est le même argument que celui qui a fait entrer `branch` dans la
 * liste blanche de l'ADR 0003 (révision du 2026-09-15). Le serveur le calcule parce que le
 * front ne sait pas le faire de façon fiable — pas parce qu'il serait secret.
 *
 * ⚠ **CE N'EST PAS LE CERCLE DE VISIBILITÉ**, et la ressemblance des formules est un piège.
 * Le cercle (ADR 0003) borne l'ascendance à `N` générations et ne décide que du sort des
 * **vivants** ; cette parenté n'a pas de borne et porte sur tout le monde, défunts compris.
 * Elle range, elle ne masque aucune donnée.
 */
enum ParenteDansLArbre: string
{
    case Famille = 'famille';
    case Conjoint = 'conjoint';
    case Alliance = 'alliance';
}
