/**
 * Les gestes du canevas, réduits à une machine à états **pure**.
 *
 * ────────────────────────────────────────────────────────────────────
 * **Pourquoi ce fichier existe.**
 *
 * `CanevasArbre` et `CanevasChronologie` portaient chacun le même bloc de
 * gestionnaires de pointeur, à quelques mots de commentaire près. Deux copies,
 * donc deux endroits à corriger — et le défaut qu'on répare ici valait pour les
 * deux : **le zoom à deux doigts était impossible**. Pas difficile, impossible.
 * `canevas.css` pose `touch-action: none`, ce qui coupe le pincement natif du
 * navigateur, et le code ne suivait qu'**un seul** pointeur : un second doigt
 * écrasait simplement les coordonnées du premier. Sur un téléphone, on pouvait
 * donc déplacer l'arbre et jamais l'agrandir.
 *
 * On garde `touch-action: none` : c'est désormais nous qui fournissons le geste.
 *
 * **Tout est pur.** Entrées : des `Contact` — trois nombres. Sortie : une
 * `Consigne` — ce qu'il y a à faire. Aucun `PointerEvent`, aucun DOM, aucun Vue.
 * C'est ce qui rend testable, sans navigateur, la partie du pincement où une
 * erreur ne se voit pas : le seuil, l'étouffement du clic, et le réamorçage au
 * retrait d'un doigt.
 * ────────────────────────────────────────────────────────────────────
 */
import type { Pince } from './geometrie'

/** Un doigt, ou la souris. Les coordonnées sont celles du client. */
export interface Contact {
  id: number
  x: number
  y: number
}

/**
 * Ce que le réducteur demande à la couche DOM. Tous les champs sont toujours
 * présents — `| null` et jamais `?:`, parce que `exactOptionalPropertyTypes`
 * est actif et qu'une clé absente ne veut pas dire la même chose qu'une clé
 * nulle.
 */
export interface Consigne {
  /** Le seuil vient d'être franchi : poser la classe `deplacement`. */
  demarre: boolean
  /** Plus aucun contact : retirer la classe. */
  termine: boolean
  /** Un déplacement, en pixels d'écran. */
  deplacement: { dx: number; dy: number } | null
  /** Un pincement, entre deux images. */
  pincement: { avant: Pince; apres: Pince } | null
  /** Les pointeurs à capturer, et ceux à relâcher. */
  capturer: readonly number[]
  relacher: readonly number[]
}

export interface EtatGestes {
  /** Insertion ordonnée : **seuls les deux premiers doigts** pilotent le calcul.
   *  Un troisième est suivi pour la comptabilité et ne fait rien bouger — une
   *  paume posée sur l'écran ne doit pas prendre la main sur le geste en cours. */
  contacts: Map<number, Contact>
  /** La position de référence du déplacement à un doigt. Elle **n'avance pas**
   *  tant que le seuil n'est pas franchi : les trois pixels se mesurent depuis
   *  le point d'appui, jamais cumulativement. */
  reference: Contact | null
  /** La dernière pince mesurée, quand deux doigts sont posés. */
  pince: Pince | null
  /** Un clic a-t-il été étouffé ? Lu par le `surClic` de chaque canevas. */
  bouge: boolean
  /** Le seuil a-t-il été franchi ? */
  actif: boolean
}

/** Sans ce seuil, un clic un peu tremblant sur une fiche est compté comme un
 *  déplacement et n'ouvre jamais le panneau. Trois pixels, en distance de
 *  Manhattan — c'est la mesure d'origine, et la changer changerait une sensation
 *  que le commanditaire connaît. */
export const TOLERANCE_CLIC = 3

const RIEN: Consigne = {
  demarre: false,
  termine: false,
  deplacement: null,
  pincement: null,
  capturer: [],
  relacher: [],
}

export function creerEtatGestes(): EtatGestes {
  return { contacts: new Map(), reference: null, pince: null, bouge: false, actif: false }
}

export function aBouge(etat: EtatGestes): boolean {
  return etat.bouge
}

export function contactsActifs(etat: EtatGestes): readonly number[] {
  return [...etat.contacts.keys()]
}

/**
 * Un doigt se pose.
 *
 * **`bouge` n'est remis à zéro qu'au passage de zéro à un contact.** Le code
 * d'origine le remettait à chaque appui, ce qui était juste tant qu'un seul
 * doigt existait : avec deux, le second appui rouvrait le clic que le premier
 * venait d'étouffer, et un pincement se terminait en ouvrant la fiche qui se
 * trouvait dessous.
 *
 * **Le deuxième doigt étouffe le clic immédiatement**, sans attendre les trois
 * pixels. Un pincement ne sélectionne jamais personne, même parfaitement
 * immobile.
 */
export function poser(etat: EtatGestes, contact: Contact): Consigne {
  if (etat.contacts.size === 0) {
    etat.bouge = false
    etat.actif = false
  }

  etat.contacts.set(contact.id, { ...contact })

  if (etat.contacts.size === 1) {
    etat.reference = { ...contact }
    etat.pince = null
    return RIEN
  }

  const paire = deuxPremiers(etat)
  if (paire === null) return RIEN

  const demarre = !etat.actif
  etat.bouge = true
  etat.actif = true
  etat.pince = mesurer(paire)
  etat.reference = null

  return {
    demarre,
    termine: false,
    deplacement: null,
    pincement: null,
    capturer: contactsActifs(etat),
    relacher: [],
  }
}

/**
 * Un doigt bouge.
 *
 * Un pointeur inconnu est ignoré : c'est la forme forte du `if (!enfonce)
 * return` d'origine, et elle écarte en plus une seconde souris.
 */
export function bouger(etat: EtatGestes, contact: Contact): Consigne {
  const connu = etat.contacts.get(contact.id)
  if (connu === undefined) return RIEN

  connu.x = contact.x
  connu.y = contact.y

  if (etat.contacts.size >= 2) return imagePincement(etat, contact.id)

  return imageDeplacement(etat, contact)
}

/**
 * Un doigt se lève — ou son pointeur est annulé.
 *
 * **`pointercancel` est traité doigt par doigt**, et non comme une annulation
 * globale : une paume rejetée pendant un pincement doit dégrader vers un
 * déplacement à un doigt, pas tout interrompre.
 *
 * **`bouge` n'est pas remis à zéro ici.** Le `click` arrive *après* le
 * `pointerup`, et c'est lui qui le lit pour savoir s'il doit s'abstenir. Le
 * code d'origine ne le remettait pas non plus, et c'est pour cette raison-là.
 */
export function lever(etat: EtatGestes, id: number): Consigne {
  if (!etat.contacts.has(id)) return RIEN

  etat.contacts.delete(id)

  if (etat.contacts.size === 0) {
    const termine = etat.actif
    etat.actif = false
    etat.reference = null
    etat.pince = null
    return { ...RIEN, termine, relacher: [id] }
  }

  if (etat.contacts.size === 1) {
    // LE SAUT QU'ON ÉVITE ICI. Le déplacement reprend depuis la position
    // COURANTE du doigt restant. Sans ce réamorçage, la première image d'après
    // comparerait ce doigt au milieu des deux d'avant, et la vue sauterait de
    // l'écart entre les deux — l'à-coup classique de fin de pincement.
    const survivant = [...etat.contacts.values()][0]
    if (survivant !== undefined) etat.reference = { ...survivant }
    etat.pince = null
  } else {
    const paire = deuxPremiers(etat)
    etat.pince = paire === null ? null : mesurer(paire)
  }

  return { ...RIEN, relacher: [id] }
}

/* ------------------------------------------------------------------ */

function deuxPremiers(etat: EtatGestes): [Contact, Contact] | null {
  const [a, b] = [...etat.contacts.values()]
  return a === undefined || b === undefined ? null : [a, b]
}

function mesurer([a, b]: [Contact, Contact]): Pince {
  return {
    x: (a.x + b.x) / 2,
    y: (a.y + b.y) / 2,
    d: Math.hypot(a.x - b.x, a.y - b.y),
  }
}

function imagePincement(etat: EtatGestes, idBouge: number): Consigne {
  const paire = deuxPremiers(etat)
  if (paire === null) return RIEN

  // Un troisième doigt ne pilote rien : on le suit, on ne recalcule pas pour lui.
  if (paire[0].id !== idBouge && paire[1].id !== idBouge) return RIEN

  const avant = etat.pince
  const apres = mesurer(paire)
  etat.pince = apres
  if (avant === null) return RIEN

  return { ...RIEN, pincement: { avant, apres } }
}

function imageDeplacement(etat: EtatGestes, contact: Contact): Consigne {
  const reference = etat.reference
  if (reference === null) return RIEN

  let demarre = false
  if (!etat.actif) {
    if (Math.abs(contact.x - reference.x) + Math.abs(contact.y - reference.y) <= TOLERANCE_CLIC) {
      return RIEN
    }
    demarre = true
    etat.actif = true
    etat.bouge = true
  }

  const deplacement = { dx: contact.x - reference.x, dy: contact.y - reference.y }
  etat.reference = { ...contact }

  return {
    demarre,
    termine: false,
    deplacement,
    pincement: null,
    capturer: demarre ? [contact.id] : [],
    relacher: [],
  }
}
