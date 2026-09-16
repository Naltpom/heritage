/**
 * La construction et le pilotage **impératifs** du canevas de la chronologie.
 *
 * ────────────────────────────────────────────────────────────────────
 * **RÈGLE N° 3 : le canevas vit hors de la réactivité de Vue.** Les barres —
 * jusqu'à 274, et moins pour qui a un cercle restreint (§ 9.1) —, leurs
 * hachures et leurs noms sont créés ici par `createElementNS`, insérés une
 * fois, et les interactions ultérieures — zoom, déplacement, sélection —
 * passent par `classList` et par un seul attribut `viewBox`. Ils ne figurent
 * jamais dans un `v-for` réactif. Vue gère le cadre, la barre d'outils et la
 * légende (ADR 0007, § 7.2 du dossier).
 *
 * **RÈGLE N° 2 : aucune propriété de peinture n'est posée ici.** Ce fichier ne
 * pose ni `fill`, ni `stroke`, ni `opacity` : il pose des **classes**, et
 * `canevas.css` peint — en `fill-opacity` / `stroke-opacity` uniquement.
 * `opacity` créerait un contexte d'empilement par élément, donc une couche de
 * composition par barre.
 * ────────────────────────────────────────────────────────────────────
 *
 * **Pourquoi une `Map` plutôt qu'un `querySelector`.** Même raison que pour
 * l'arbre : on indexe les éléments à la construction et on ne recompose jamais
 * de sélecteur depuis un identifiant. Ici l'identifiant est un entier, mais la
 * règle vaut d'être tenue — c'est celle qui empêchera le prochain identifiant
 * exotique de casser la sélection.
 *
 * **La hachure est un motif, défini une fois dans `<defs>`.** Son identifiant
 * `chrono-hachure` est référencé **depuis la feuille de style** (`.hachure {
 * fill: url(#chrono-hachure) }`), pas depuis ce fichier : la peinture reste dans
 * la CSS, la structure reste ici. Si l'un des deux change de nom, la hachure
 * disparaît — d'où le test qui vérifie que le motif est bien créé.
 */

import type { BarreChronologie, OrientationChronologie, VueChronologie } from '@/lib/api'
import type { Boite } from '@/components/arbre/geometrie'
import { viewBoxEnTexte } from '@/components/arbre/geometrie'
import {
  boiteDuContenu,
  etiquetteDeBarre,
  etiquettesDeBande,
  filetDeBande,
  rectangleDeBande,
  type Rectangle,
} from './geometrie'
import {
  disposer,
  entetesDeGroupe,
  placerJalons,
  type BarrePlacee,
  type Disposition,
} from './disposition'

const NS_SVG = 'http://www.w3.org/2000/svg'

/** Identifiant du motif de hachure. `canevas.css` le référence tel quel. */
export const ID_HACHURE = 'chrono-hachure'

/** Rayon des coins d'une barre. */
const RAYON_BARRE = 3

function creer<K extends keyof SVGElementTagNameMap>(
  nom: K,
  attributs: Record<string, string | number>,
): SVGElementTagNameMap[K] {
  const element = document.createElementNS(NS_SVG, nom)
  for (const [cle, valeur] of Object.entries(attributs)) {
    element.setAttribute(cle, String(valeur))
  }
  return element
}

function rect(classe: string, r: Rectangle): SVGRectElement {
  return creer('rect', { class: classe, x: r.x, y: r.y, width: r.w, height: r.h })
}

/** Les réglages **du lecteur** : ils ne viennent pas de la donnée, ils décident
 *  du rendu. `montrerIncertitude` n'a pas de valeur par défaut — voir
 *  `tracerBarre`. */
export interface ReglagesRendu {
  orientation: OrientationChronologie
  montrerIncertitude: boolean
  anneeCourante: number
}

/** L'interface impérative offerte au composant Vue. */
export interface PiloteChronologie {
  readonly disposition: Disposition
  /** La boîte du contenu : elle alimente le `viewBox` initial. */
  readonly boite: Boite
  /** Le seul endroit de ce module qui écrit l'attribut `viewBox`. */
  appliquerViewBox: (vb: Boite) => void
  /** La barre sous un événement de souris, ou `null` sur le fond. */
  barreSousLeCurseur: (cible: EventTarget | null) => BarreChronologie | null
  /** Met en avant une barre et met les autres en retrait. `null` rend tout. */
  selectionner: (personId: number | null) => void
  detruire: () => void
}

/**
 * Construit le canevas. Rend `null` quand il n'y a pas de bande : l'appelant
 * affiche alors son état vide, plutôt qu'un canevas blanc qu'on prendrait pour
 * une panne.
 */
export function construireCanevasChronologie(
  svg: SVGSVGElement,
  vue: VueChronologie,
  reglages: ReglagesRendu,
): PiloteChronologie | null {
  const { orientation, montrerIncertitude, anneeCourante } = reglages

  const disposition = disposer(vue, orientation, anneeCourante, montrerIncertitude)
  if (disposition === null) return null

  while (svg.firstChild !== null) svg.removeChild(svg.firstChild)

  const { axe, epaisseur } = disposition

  /* ---- le motif de hachure, référencé par la feuille de style ---- */

  const defs = creer('defs', {})
  const motif = creer('pattern', {
    id: ID_HACHURE,
    width: 6,
    height: 6,
    patternUnits: 'userSpaceOnUse',
  })
  // Trois segments plutôt qu'un `patternTransform` : le motif se répète de
  // lui-même aux quatre coins de la tuile, et le fichier reste sans aucun
  // `transform`.
  motif.appendChild(creer('path', { class: 'trait', d: 'M-1,1 L1,-1 M0,6 L6,0 M5,7 L7,5' }))
  defs.appendChild(motif)

  /* ---- bandes : alternance et filets ---- */

  const gBandes = creer('g', { class: 'bandes' })
  vue.bandes.forEach((bande, index) => {
    const r = rectangleDeBande(bande, axe, orientation, epaisseur)
    gBandes.appendChild(rect(index % 2 === 0 ? 'bande paire' : 'bande impaire', r))
    gBandes.appendChild(creer('line', { class: 'filet', ...filetDeBande(bande, axe, orientation, epaisseur) }))
  })

  /* ---- libellés de bande (rendus par le serveur, jamais recomposés) ---- */

  const gEtiquettes = creer('g', { class: 'etiquettes' })
  for (const etiquette of etiquettesDeBande(vue.bandes, axe, orientation)) {
    const texte = creer('text', {
      class: 'etiquette-bande',
      x: etiquette.x,
      y: etiquette.y,
      'text-anchor': etiquette.ancrage,
    })
    texte.textContent = etiquette.texte
    gEtiquettes.appendChild(texte)
  }

  /* ---- noms de branche ---- */

  const gEntetes = creer('g', { class: 'entetes' })
  for (const entete of entetesDeGroupe(disposition.groupes, orientation)) {
    const texte = creer('text', {
      class: `entete-branche b-${entete.branche}`,
      x: entete.x,
      y: entete.y,
      'text-anchor': entete.ancrage,
    })
    texte.textContent = entete.texte
    gEntetes.appendChild(texte)
  }

  /* ---- barres ---- */

  const gBarres = creer('g', { class: 'barres' })
  const parPersonne = new Map<number, SVGGElement>()
  const placeeParPersonne = new Map<number, BarrePlacee>()
  for (const placee of disposition.barres) {
    const g = construireBarre(placee, orientation)
    gBarres.appendChild(g)
    parPersonne.set(placee.barre.personId, g)
    placeeParPersonne.set(placee.barre.personId, placee)
  }

  /* ---- jalons : les faits datés, posés sur la barre de leur personne ---- */

  const gJalons = creer('g', { class: 'jalons' })
  for (const place of placerJalons(vue, disposition, orientation)) {
    // Le trait de rattachement d'abord : sous la marque, jamais par-dessus.
    if (place.attache !== null) {
      gJalons.appendChild(creer('line', { class: 'attache', ...place.attache }))
    }
    const marque = creer('circle', {
      class: 'jalon',
      cx: place.point.x,
      cy: place.point.y,
      r: 3.2,
    })
    const titre = document.createElementNS(NS_SVG, 'title')
    titre.textContent = `${place.jalon.libelle} (${place.jalon.annee})`
    marque.appendChild(titre)
    gJalons.appendChild(marque)
  }

  const fragment = document.createDocumentFragment()
  fragment.appendChild(defs)
  fragment.appendChild(gBandes)
  fragment.appendChild(gEtiquettes)
  fragment.appendChild(gEntetes)
  fragment.appendChild(gBarres)
  fragment.appendChild(gJalons)
  svg.appendChild(fragment)

  return {
    disposition,
    boite: boiteDuContenu(orientation, disposition.longueur, epaisseur),

    appliquerViewBox(vb) {
      svg.setAttribute('viewBox', viewBoxEnTexte(vb))
    },

    barreSousLeCurseur(cible) {
      if (!(cible instanceof Element)) return null
      const groupe = cible.closest('.barre')
      if (groupe === null) return null
      const valeur = (groupe as SVGGElement).dataset.personne
      if (valeur === undefined) return null
      return placeeParPersonne.get(Number(valeur))?.barre ?? null
    },

    selectionner(personId) {
      // La mise en retrait est portée par une classe sur le canevas et non sur
      // `body` : deux canevas peuvent coexister dans l'application.
      svg.classList.toggle('selection', personId !== null)
      for (const [id, g] of parPersonne) g.classList.toggle('sel', id === personId)
    },

    detruire() {
      while (svg.firstChild !== null) svg.removeChild(svg.firstChild)
      svg.classList.remove('selection')
      svg.removeAttribute('viewBox')
    },
  }
}

/**
 * Une barre : le corps, les hachures d'incertitude, la pointe d'une vie en
 * cours, le nom.
 *
 * L'ordre d'insertion est l'ordre de peinture : le corps d'abord, les hachures
 * par-dessus, le nom en dernier. Une hachure posée avant le corps serait
 * recouverte, et l'incertitude disparaîtrait sans que rien ne le signale.
 */
function construireBarre(placee: BarrePlacee, orientation: OrientationChronologie): SVGGElement {
  const { barre, trace } = placee
  const classe = `b-${barre.branch}`

  // `sans-debut` et `sans-fin` disent ce que la donnée ne sait pas : une
  // naissance inconnue, un décès non daté. Le bord correspondant est rendu en
  // tirets par `canevas.css` — il ne prétend pas être une date.
  const etats = [
    barre.ouverte ? 'ouverte' : '',
    trace.sansDebut ? 'sans-debut' : '',
    trace.sansFin ? 'sans-fin' : '',
  ].filter(Boolean)

  const g = creer('g', {
    class: ['barre', classe, ...etats].join(' '),
    'data-personne': barre.personId,
  })

  // `<title>` : le nom complet et la ligne de dates au survol et pour les
  // lecteurs d'écran. La ligne de dates vient du serveur — « entre 1901 et 1909
  // – 1987 » s'affiche tel quel, jamais aplati en « 1901 » (§ 14).
  const titre = document.createElementNS(NS_SVG, 'title')
  titre.textContent = `${barre.displayName} — ${barre.ligneDates}`
  g.appendChild(titre)

  const corps = rect(`corps ${classe}`, trace.corps)
  corps.setAttribute('rx', String(RAYON_BARRE))
  g.appendChild(corps)

  if (trace.hachureDebut !== null) g.appendChild(rect('hachure', trace.hachureDebut))
  if (trace.hachureFin !== null) g.appendChild(rect('hachure', trace.hachureFin))

  if (trace.pointe !== null) {
    g.appendChild(creer('polygon', { class: `pointe ${classe}`, points: trace.pointe }))
  }
  if (trace.pointeAvant !== null) {
    g.appendChild(
      creer('polygon', { class: `pointe avant ${classe}`, points: trace.pointeAvant }),
    )
  }

  const etiquette = etiquetteDeBarre(barre, trace, orientation)
  if (etiquette !== null) {
    const attributs: Record<string, string | number> = {
      class: 'nom',
      x: etiquette.x,
      y: etiquette.y,
    }
    if (etiquette.rotation !== null) {
      // Rotation d'un texte, **pas** un `transform: scale()` sur le canevas :
      // la règle n° 1 vise le zoom, qui passe par le `viewBox` et rien d'autre.
      attributs['transform'] = `rotate(${etiquette.rotation} ${etiquette.x} ${etiquette.y})`
    }
    const nom = creer('text', attributs)
    nom.textContent = etiquette.texte
    g.appendChild(nom)
  }

  return g
}
