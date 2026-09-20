/**
 * **Ce qu'un rattachement de compte fait voir — et surtout ce qu'il fait voir
 * quand il manque.**
 *
 * ── Le défaut que ce module referme ─────────────────────────────────────────
 *
 * L'écran des utilisateurs n'affichait la ligne « Rattaché à … » que sous
 * `v-if="membre !== null"`. Le compte **sans aucune ligne `mbr_member`** ne
 * produisait donc *rien* : ni mention, ni avertissement. Le cas le plus grave
 * était le seul silencieux, et un administrateur ne diagnostique pas ce qu'il
 * ne voit pas.
 *
 * L'information était pourtant déjà dans la charge : `UserAdminService::normaliser()`
 * rend `membre: null` sans ambiguïté pour ce cas. C'était l'affichage qui la
 * jetait, pas l'API qui la retenait.
 *
 * ── Pourquoi la phrase dit la CONSÉQUENCE et pas l'état ─────────────────────
 *
 * « Rattachement absent » ne dit rien à qui lit l'écran. Ce qu'il constate, lui,
 * c'est qu'un membre de la famille lui signale voir « l'arbre de quelqu'un
 * d'autre ». La phrase doit donc nommer ce symptôme-là.
 *
 * Et elle est exacte, pas imagée. `Tree\Serializer\PersonneDuLecteur` l'écrit
 * mot pour mot : « `null` veut dire « on retombe sur la racine de l'arbre »,
 * pas « interdit » ». Or `AnnuaireDesMembres::personneDuCompte()` ne rend une
 * personne que pour un membre **`actif`** — un membre `en_attente` ou
 * `suspendu` y rend `null` aussi sûrement qu'un membre absent.
 *
 * Deux choses en dépendent, et ce sont exactement les deux que le visiteur
 * voit : la **mise en page** du graphe — `generation` et `branch` sont
 * recalculées depuis le point de vue par `Tree\Service\ArbreVuDepuis` — et les
 * **libellés de parenté** servis par `Tree\Serializer\ParenteDuLecteur`. Un
 * repli sur la racine ne produit donc pas une erreur : il produit un arbre
 * parfaitement plausible, centré sur le propriétaire, où « ta grand-mère »
 * désigne la grand-mère de quelqu'un d'autre.
 *
 * ── Trois états, et pourquoi `suspendu` ne fait pas un quatrième ────────────
 *
 * `MemberStatus` en a trois — `actif`, `en_attente`, `suspendu` —, mais du
 * point de vue de ce que le compte **voit**, les deux derniers sont le même :
 * ils retombent sur la racine. On les réunit donc sous `inactif`, avec une
 * étiquette propre à chacun, plutôt que d'inventer une distinction que la
 * conséquence ne porte pas.
 *
 * ── Qui est vraiment `absent`, et pourquoi il ne crie pas ───────────────────
 *
 * Mesuré, et contre-intuitif : **un compte né d'une invitation a toujours une
 * ligne `mbr_member`**. `ServiceDInscription` en crée une sans condition, juste
 * après avoir persisté le `User` ; seul son *statut* varie. Les seuls comptes
 * sans ligne sont donc ceux d'**amorçage** — `CreerAdministrateurCommand` et
 * `AmorcageDuCompteAdministrateur` créent un `User` `ROLE_ADMIN` sans `Member`,
 * et `AmorcageFixtures::rattacher()` ressort sans rien poser dans cinq
 * conditions (pas d'identifiant, XREF absent, rattachement déjà là, arbre pas
 * encore importé, personne déjà réclamée).
 *
 * Or ce compte-là retombe sur la racine **et la racine est justement sa fiche**
 * : il obtient le bon arbre par coïncidence. Lui coller un ton d'alerte
 * reviendrait à allumer un voyant rouge permanent sur la ligne du propriétaire,
 * à chaque ouverture de l'écran — et à apprendre à l'administrateur que le
 * rouge de cet écran ne veut rien dire, juste avant la ligne où il voudra dire
 * quelque chose. D'où un ton neutre **et** une phrase qui nomme le cas attendu.
 *
 * Le corollaire vaut d'être retenu : le propriétaire est **structurellement
 * aveugle** à ce défaut. Il ne peut pas le reproduire depuis son propre compte,
 * et les comptes qui le subissent sont ceux qui ont une ligne — donc `inactif`.
 *
 * ── Ce que ce module ne fait pas ────────────────────────────────────────────
 *
 * **Il ne propose aucune réparation pour le cas `absent`**, et ce n'est pas un
 * oubli : il n'existe aujourd'hui aucune route pour rattacher un compte
 * *existant* à une fiche. `activerMembre(id, { personId })` corrige un
 * rattachement déjà présent ; sans ligne `mbr_member`, il n'y a pas d'`id` à
 * lui passer. Promettre un geste qui n'existe pas serait pire que de nommer le
 * défaut.
 */

import type { MembreAdministration } from '@/lib/api'

/**
 * L'état d'un rattachement, **du point de vue de ce que le compte voit**.
 *
 *  - `actif`  — le compte regarde l'arbre depuis sa propre fiche ;
 *  - `inactif` — la ligne existe, mais elle ne sert pas de point de vue ;
 *  - `absent` — il n'y a aucune ligne `mbr_member`.
 */
export type EtatDuRattachement = 'actif' | 'inactif' | 'absent'

export interface MentionDeRattachement {
  etat: EtatDuRattachement
  /** L'état, nommé — ce qui remplace l'ancienne ligne « Rattaché à … ». */
  etiquette: string
  /**
   * Ce que ce compte voit **à cause** de cet état, en une phrase, ou `null`
   * quand il voit ce qu'il doit voir et qu'il n'y a donc rien à signaler.
   */
  consequence: string | null
}

/**
 * La conséquence, écrite une fois.
 *
 * Elle est identique pour les trois cas qui retombent — c'est le même `null`
 * rendu par `personneDuCompte()` qui les produit tous les trois — et la
 * recopier trois fois serait la laisser diverger.
 */
const DEPUIS_LA_RACINE =
  'ce compte regarde l’arbre depuis la racine : l’arbre lui est servi centré sur le ' +
  'propriétaire, et les parentés affichées (« ta grand-mère ») sont celles du propriétaire'

/** Première lettre en capitale — `DEPUIS_LA_RACINE` se compose aussi en incise. */
function phrase(texte: string): string {
  return `${texte.charAt(0).toUpperCase()}${texte.slice(1)}.`
}

/**
 * Comment désigner la fiche visée, sans jamais inventer de libellé.
 *
 * `mbr_member.person_label` est `NOT NULL` côté serveur, donc le cas vide ne
 * devrait pas se produire — mais le type de la charge l'autorise, et une fiche
 * désignée par un identifiant reste diagnosticable là où « Rattaché à  » ne
 * l'est pas.
 */
function fiche(membre: MembreAdministration): string | null {
  if (membre.personLabel !== null && membre.personLabel !== '') return membre.personLabel
  if (membre.personId !== null) return `la fiche n° ${membre.personId}`
  return null
}

/**
 * La mention à afficher pour un compte, d'après sa charge `membre`.
 *
 * `null` est une valeur d'entrée **attendue** et non un cas d'erreur : c'est
 * exactement celle du compte sans rattachement, et c'est elle qui ne produisait
 * rien jusqu'ici.
 */
export function mentionDeRattachement(
  membre: MembreAdministration | null,
): MentionDeRattachement {
  if (membre === null) {
    return {
      etat: 'absent',
      etiquette: 'Aucune fiche rattachée',
      // Le second membre de phrase est ce qui empêche cette mention de devenir
      // une fausse alerte permanente : voir l'en-tête du module.
      consequence:
        `${phrase(DEPUIS_LA_RACINE)} C’est l’état attendu du compte d’amorçage du ` +
        'propriétaire, dont la racine est justement la fiche ; sur tout autre compte, ' +
        'c’est un défaut.',
    }
  }

  const visee = fiche(membre)

  if (membre.status === 'en_attente') {
    return {
      etat: 'inactif',
      etiquette:
        visee !== null
          ? `Rattachement à ${visee} demandé, pas encore validé`
          : 'Rattachement demandé, pas encore validé',
      // Le bouton « Valider le rattachement » est déjà sur la ligne : on dit ce
      // que coûte l'attente, pas ce qu'il faut cliquer.
      consequence: `Tant qu’il n’est pas validé, ${DEPUIS_LA_RACINE}.`,
    }
  }

  if (membre.status === 'suspendu') {
    return {
      etat: 'inactif',
      etiquette:
        visee !== null ? `Rattachement à ${visee} suspendu` : 'Rattachement suspendu',
      consequence: phrase(DEPUIS_LA_RACINE),
    }
  }

  // `actif`. Le seul cas où le compte regarde l'arbre depuis sa propre fiche —
  // sauf si la ligne active ne désigne aucune fiche, auquel cas elle est active
  // et cassée, ce qui retombe sur la racine tout pareil.
  if (visee === null) {
    return {
      etat: 'inactif',
      etiquette: 'Rattachement actif, mais il ne désigne aucune fiche',
      consequence: phrase(DEPUIS_LA_RACINE),
    }
  }

  return { etat: 'actif', etiquette: `Rattaché à ${visee}`, consequence: null }
}
