/**
 * Le volet « Ajouter une personne » de `/arbre` (`VoletProposition.vue`) : ce qui,
 * de son voisinage avec l'arbre, se décide sans DOM — et se teste donc sans lui.
 *
 *  - **Le clavier.** L'arbre écoute toute la fenêtre (`/` chercher, `0` tout
 *    l'arbre, `+` et `-` zoomer, Échap désélectionner). Un formulaire ouvert à côté
 *    ne doit pas les déclencher : taper « Jean-Marie » ne dézoome pas l'arbre.
 *  - **Le cadrage.** Le volet se place avant le canevas : il le pousse, et l'arbre
 *    ne doit pas sauter pour autant.
 *  - **Les deux volets.** La fiche choisie garde le sien à droite ; à gauche, la
 *    proposition. Les deux ne tiennent ensemble qu'au-delà de 90 rem.
 *  - **Après le dépôt**, ce que la confirmation dit de la personne dans l'arbre —
 *    une création, une fiche complétée, une proposition complétée sur place, ou
 *    un complément (ADR 0017).
 */
import type { Cadrage } from '@/histoire/vue'
import { annonceDesDemandes, type PersonneDeposee } from '@/components/proposition/depot'

/** Sa largeur, en pixels : celle de `w-[440px]` sur son `<aside>`. */
export const LARGEUR_VOLET_PROPOSITION = 440

/**
 * Le volet n'existe qu'à partir de `lg` — la chaîne exacte que Tailwind 4 compile
 * pour `lg:`, dont `max-lg:hidden` (le bouton qui l'ouvre) est l'envers. Plus
 * étroit, le canevas n'aurait plus la place d'être un arbre.
 */
export const REQUETE_VOLET_PROPOSITION = '(width >= 64rem)'

/**
 * Au-delà de 90 rem (1 440 px), les deux volets tiennent ensemble à côté de
 * l'arbre : 440 + 380 px, et il reste 620 px de canevas. En deçà, ouvrir la
 * proposition masque la fiche — la sélection et son anneau restent.
 */
export const REQUETE_DEUX_VOLETS = '(width >= 90rem)'

/**
 * Le cadrage quand le volet paraît (`parait`) ou s'efface.
 *
 * Il se place **avant** le canevas, dans la même rangée : quand il paraît,
 * l'origine du canevas glisse de 440 px vers la droite, et tout l'arbre avec elle.
 * On retire donc 440 px à `tx`, dans le même tic que l'apparition, et l'arbre reste
 * où il était à l'écran ; l'inverse quand il s'efface.
 *
 * `pret` : un arbre disposé, dans un canevas mesuré. Sans lui, il n'y a rien à
 * garder en place — le premier ajustement mesurera le canevas tel qu'il sera.
 */
export function cadrageApresVolet(cadrage: Cadrage, parait: boolean, pret: boolean): Cadrage {
  if (!pret) return cadrage
  return { ...cadrage, tx: cadrage.tx + (parait ? -LARGEUR_VOLET_PROPOSITION : LARGEUR_VOLET_PROPOSITION) }
}

/** Ce dont dépend le volet de la fiche, à droite. */
export interface EtatDesVolets {
  estMobile: boolean
  /** La fiche choisie ; `null` sans sélection. */
  fiche: object | null
  /** Le volet « Ajouter une personne » est-il à l'écran ? */
  propositionOuverte: boolean
  /** Au-delà de 90 rem : les deux volets tiennent ensemble. */
  tresLarge: boolean
}

/**
 * Le volet de la fiche est-il à l'écran ? Sur ordinateur, dès qu'une fiche est
 * choisie (le téléphone garde son panneau du bas) — sauf quand la proposition
 * occupe la gauche d'un écran trop étroit pour les deux.
 */
export function voletDroitVisible(etat: EtatDesVolets): boolean {
  if (etat.estMobile || etat.fiche === null) return false
  return !etat.propositionOuverte || etat.tresLarge
}

/**
 * Où en est la personne déposée, dans l'arbre redessiné :
 *  - `recherche` : l'arbre se relit ;
 *  - `placee` : dessinée, choisie, et l'écran l'a rejointe ;
 *  - `masquee` : dans le graphe, mais un réglage de l'affichage la retire de l'écran
 *    (branche masquée, ligne directe seule, familles par alliance…) ;
 *  - `absente` : pas dans le graphe — un brouillon, ou une fiche que le serveur n'a
 *    pas su rattacher (`calque.nonPlaces`) ;
 *  - `echec` : le dépôt est fait, mais l'arbre n'a pas pu se relire (réseau, serveur).
 *    Ce n'est pas `absente` : on ne sait pas où elle est, on ne dit pas qu'elle n'y est pas.
 */
export type PlaceDansLArbre = 'recherche' | 'placee' | 'masquee' | 'absente' | 'echec'

/** La phrase commune à toutes les natures, quand l'arbre n'a pas pu se relire après le dépôt. */
const ARBRE_NON_RELU = 'L’arbre n’a pas pu se relire : rechargez la page pour l’y voir.'

/**
 * Le titre et la phrase de la confirmation, après un dépôt. Le sujet est « la
 * fiche de … » : un nom seul imposerait d'accorder au sexe de la personne, qu'on
 * ne connaît pas toujours.
 */
export function confirmationDuDepot(depot: PersonneDeposee, place: PlaceDansLArbre): { titre: string; phrase: string } {
  const numero = depot.demandeIds[0]
  const fiche = `La fiche de ${depot.libelle}`
  if (depot.nature === 'fiche') return confirmationDeLaFiche(depot, fiche, place)
  if (depot.nature === 'completion') return confirmationDeLaCompletion(depot, fiche, place)
  if (depot.nature === 'complement') return confirmationDuComplement(depot, fiche, place)
  if (!depot.soumise) {
    return {
      titre: `Brouillon enregistré — demande n° ${numero}`,
      phrase: `${fiche} n’apparaîtra dans l’arbre qu’une fois la demande envoyée, depuis son suivi.`,
    }
  }
  const titre = `Proposition envoyée — demande n° ${numero}`
  switch (place) {
    case 'recherche':
      return { titre, phrase: `${fiche} prend sa place dans l’arbre…` }
    case 'placee':
      return { titre, phrase: `${fiche} est dans l’arbre, en pointillé jusqu’à sa validation ; elle y est choisie.` }
    case 'masquee':
      return {
        titre,
        phrase: `${fiche} est dans l’arbre, en pointillé jusqu’à sa validation, mais l’affichage la masque : une branche, la ligne directe seule ou une famille retirée par les filtres.`,
      }
    case 'absente':
      return {
        titre,
        phrase: `${fiche} n’est pas encore dessinée dans l’arbre. Le suivi de la demande la montre en attendant.`,
      }
    case 'echec':
      return { titre, phrase: `${ARBRE_NON_RELU} Le suivi de la demande la montre en attendant.` }
  }
}

/**
 * « C'est elle : compléter sa fiche » : rien n'a été créé — la fiche était déjà
 * dans l'arbre, et c'est elle que l'arbre va chercher. Ses compléments n'y
 * paraîtront qu'une fois validés : on le dit, plutôt que de laisser croire que
 * l'arbre les montre déjà.
 */
function confirmationDeLaFiche(depot: PersonneDeposee, fiche: string, place: PlaceDansLArbre): { titre: string; phrase: string } {
  const titre = annonceDesDemandes(depot.demandeIds)
  const suite = 'vos compléments y paraîtront une fois validés.'
  switch (place) {
    case 'recherche':
      return { titre, phrase: `${fiche} se retrouve dans l’arbre…` }
    case 'placee':
      return { titre, phrase: `${fiche} est choisie dans l’arbre ; ${suite}` }
    case 'masquee':
      return {
        titre,
        phrase: `${fiche} est dans l’arbre, mais l’affichage la masque : une branche, la ligne directe seule ou une famille retirée par les filtres. ${suite.charAt(0).toUpperCase()}${suite.slice(1)}`,
      }
    case 'absente':
      return { titre, phrase: `${fiche} n’est pas dessinée dans l’arbre avec vos réglages ; ${suite}` }
    case 'echec':
      return { titre, phrase: `${ARBRE_NON_RELU} ${suite.charAt(0).toUpperCase()}${suite.slice(1)}` }
  }
}

/**
 * Une proposition **complétée sur place** (ADR 0017) : aucune demande neuve, la
 * même, réécrite. Son nœud en pointillé porte déjà ce qu'on vient d'y ajouter —
 * c'est la fiche proposée elle-même —, et l'arbre relu va le chercher.
 */
function confirmationDeLaCompletion(depot: PersonneDeposee, fiche: string, place: PlaceDansLArbre): { titre: string; phrase: string } {
  const numero = depot.demandeIds[0]
  if (!depot.soumise) {
    return {
      titre: `Brouillon complété — demande n° ${numero}`,
      phrase: `${fiche} n’apparaîtra dans l’arbre qu’une fois la demande envoyée, depuis son suivi.`,
    }
  }
  const titre = `Proposition complétée — demande n° ${numero}`
  switch (place) {
    case 'recherche':
      return { titre, phrase: `${fiche} se met à jour dans l’arbre…` }
    case 'placee':
      return { titre, phrase: `${fiche} est à jour dans l’arbre, en pointillé jusqu’à sa validation ; elle y est choisie.` }
    case 'masquee':
      return {
        titre,
        phrase: `${fiche} est à jour dans l’arbre, mais l’affichage la masque : une branche, la ligne directe seule ou une famille retirée par les filtres.`,
      }
    case 'absente':
      return { titre, phrase: `${fiche} n’est pas dessinée dans l’arbre. Le suivi de la demande la montre, complétée.` }
    case 'echec':
      return { titre, phrase: `${ARBRE_NON_RELU} Le suivi de la demande la montre, complétée.` }
  }
}

/**
 * Un **complément** (ADR 0017) : une proposition de plus, rattachée à celle d'un
 * autre membre, que l'administrateur fusionnera. Elle paraît dans l'arbre de son
 * auteur, en pointillé, jusqu'à la fusion — c'est elle que l'arbre va chercher.
 */
function confirmationDuComplement(depot: PersonneDeposee, fiche: string, place: PlaceDansLArbre): { titre: string; phrase: string } {
  const numero = depot.demandeIds[0]
  const fusion = 'l’administrateur la fusionnera dans la proposition existante.'
  if (!depot.soumise) {
    return {
      titre: `Brouillon de complément enregistré — demande n° ${numero}`,
      phrase: `Envoyez-le depuis son suivi : ${fusion}`,
    }
  }
  const titre = `Complément envoyé — demande n° ${numero}`
  switch (place) {
    case 'recherche':
      return { titre, phrase: `${fiche} prend sa place dans l’arbre… ${fusion.charAt(0).toUpperCase()}${fusion.slice(1)}` }
    case 'placee':
      return { titre, phrase: `${fiche} est dans votre arbre, en pointillé, et y est choisie ; ${fusion}` }
    case 'masquee':
      return {
        titre,
        phrase: `${fiche} est dans votre arbre, mais l’affichage la masque. ${fusion.charAt(0).toUpperCase()}${fusion.slice(1)}`,
      }
    case 'absente':
      return { titre, phrase: `${fiche} n’est pas dessinée dans l’arbre ; ${fusion}` }
    case 'echec':
      return { titre, phrase: `${ARBRE_NON_RELU} ${fusion.charAt(0).toUpperCase()}${fusion.slice(1)}` }
  }
}

/** Ce que `toucheIgnoree` lit d'une cible de clavier — de quoi s'écrire à la main
 *  dans un test, sans DOM. Un `HTMLElement` en est un. */
export interface CibleDeTouche {
  tagName?: string
  isContentEditable?: boolean
  closest?: (selecteur: string) => unknown
}

/** L'attribut qui marque le volet, posé sur son `<aside>`. */
export const SELECTEUR_VOLET_PROPOSITION = '[data-volet-proposition]'

/** Les champs où une touche est une saisie. `SELECT` en est : Échap y referme la
 *  liste, `-` et `0` y choisissent une option. */
const CHAMPS_DE_SAISIE = new Set(['INPUT', 'TEXTAREA', 'SELECT'])

/**
 * Une touche que les raccourcis de l'arbre doivent laisser passer : frappée dans un
 * champ de saisie, dans un élément éditable, ou **n'importe où dans le volet** — un
 * bouton du formulaire qui a le focus, Échap compris : le formulaire n'est pas
 * l'arbre, et une sélection ne se perd pas d'un Échap tapé ailleurs.
 */
export function toucheIgnoree(cible: CibleDeTouche | null | undefined): boolean {
  if (!cible) return false
  if (cible.tagName !== undefined && CHAMPS_DE_SAISIE.has(cible.tagName)) return true
  if (cible.isContentEditable === true) return true
  return typeof cible.closest === 'function' && cible.closest(SELECTEUR_VOLET_PROPOSITION) != null
}
