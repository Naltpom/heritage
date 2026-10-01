/**
 * La bascule « voir comme un membre » suspend **toutes** les écritures.
 *
 * Tant qu'elle est active, chaque route d'écriture rend `409
 * vue_comme_membre_active` — créer une demande, valider, refuser, inviter,
 * téléverser, changer un réglage (§ 10.4, règle 2). Le motif est net : on ne
 * valide pas une demande sous une identité empruntée. La `prp_revision`
 * porterait l'administrateur alors que l'écran montrait le cercle de quelqu'un
 * d'autre, et le journal deviendrait ininterprétable.
 *
 * **Le front désactive les boutons ; l'API ne compte pas dessus.** Les deux
 * barrières existent, et celle-ci n'est pas une mesure de sécurité — c'est une
 * mesure d'honnêteté : un bouton qui part en `409` à chaque clic ressemble à une
 * panne, et l'administrateur passera dix minutes à chercher ce qui est cassé
 * avant de se rappeler qu'il a activé la bascule une heure plus tôt.
 *
 * C'est aussi pourquoi le bandeau du socle (`BandeauRegime.vue`) est
 * **permanent** tant que la bascule est active : « sans lui, un administrateur
 * conclura à un bug en ne voyant plus la moitié des fiches » (§ 10.4). Le motif
 * ci-dessous en est le pendant sur chaque bouton éteint.
 */

import type { ChargeDeSession } from '@/lib/api'

/** La vue empruntée, telle que la session la porte. `null` = bascule inactive. */
export type VueEmpruntee = ChargeDeSession['voirComme']

/**
 * Les écritures sont-elles suspendues ?
 *
 * Le test porte sur la **présence de `voirComme`**, pas sur le rôle du lecteur
 * ni sur la forme du cercle : c'est la seule clé qui dit que la session est en
 * train d'emprunter une identité, et le serveur branche sur la même chose.
 */
export function ecrituresSuspendues(vueEmpruntee: VueEmpruntee): boolean {
  return vueEmpruntee !== null && vueEmpruntee !== undefined
}

/**
 * Pourquoi ce bouton est éteint, en une phrase destinée à quelqu'un qui a
 * oublié ce qu'il a activé — ou `null` s'il n'y a rien à expliquer.
 *
 * Le nom du membre emprunté y figure : c'est lui qui fait le déclic, bien plus
 * que le mot « bascule ».
 */
export function motifSuspension(vueEmpruntee: VueEmpruntee): string | null {
  if (!ecrituresSuspendues(vueEmpruntee)) return null

  const nom = vueEmpruntee?.memberLabel ?? 'un autre membre'
  return (
    `Vous voyez l’arbre comme ${nom} : aucune modification possible. ` +
    'Quittez la vue empruntée pour décider.'
  )
}

/**
 * L'infobulle d'un bouton suspendu, ou `undefined` pour ne pas poser
 * d'attribut `title` inutile quand tout va bien.
 */
export function infobulleSuspension(vueEmpruntee: VueEmpruntee): string | undefined {
  return motifSuspension(vueEmpruntee) ?? undefined
}
