/**
 * La construction et le pilotage **impératifs** du canevas SVG.
 *
 * ────────────────────────────────────────────────────────────────────
 * **RÈGLE N° 3, PROPRE AU PORTAGE VUE : le canevas vit hors de la réactivité.**
 * Les 377 nœuds sont créés ici par `createElementNS`, insérés une fois, et
 * toutes les interactions ultérieures — zoom, déplacement, surlignage de
 * recherche, sélection, filtres — passent par `classList` et par un seul
 * attribut `viewBox`. Ils ne figurent **jamais** dans un `v-for` réactif : les
 * faire rendre par Vue à chaque frappe dans la recherche détruirait la
 * fluidité. Vue gère le cadre, le panneau latéral et les contrôles.
 *
 * **RÈGLE N° 2 : jamais `opacity` sur un élément SVG.** Ce fichier ne pose
 * aucune propriété de peinture : il ne fait que poser et retirer des classes,
 * et `canevas.css` ne met en retrait qu'avec `fill-opacity` / `stroke-opacity`.
 * `opacity` créerait un contexte d'empilement, donc une couche de composition
 * par arête ; à 377 arêtes, la frappe devient saccadée.
 * ────────────────────────────────────────────────────────────────────
 *
 * **Pourquoi des `Map` et jamais un sélecteur CSS pour retrouver un nœud.** Un
 * identifiant provisoire vaut `#12/p1` : passé à `querySelector`, le `#` en
 * ferait un sélecteur d'identifiant et le `/` une erreur de syntaxe. On indexe
 * donc les éléments à la construction, et on ne recompose jamais de sélecteur
 * depuis un identifiant de nœud.
 */

import type { Graphe, NoeudGraphe } from '@/lib/api'
import type { Boite, BoiteCentree } from './geometrie'
import { viewBoxEnTexte } from './geometrie'
import { barreDeDivorce, pointsDeLosange, tracerAretes } from './rendu'
import {
  classesDeFamille,
  classesDeFiche,
  datesDeFiche,
  nomDeFiche,
  indexer,
  type IndexGraphe,
  type NoeudPersonneEtendu,
} from './graphe'

const NS_SVG = 'http://www.w3.org/2000/svg'

/** Rayon des coins d'une fiche, repris de `rendu.py`. */
const RAYON_FICHE = 5
/** Le liseré de branche : 4 unités de large, encastré de 1,2 dans le bord. */
const LISERE_LARGEUR = 4
const LISERE_MARGE_X = 1.2
const LISERE_MARGE_Y = 6
/** Lignes de texte, mesurées depuis le haut de la fiche. */
const LIGNE_NOM = 17
const LIGNE_DATES = 31

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

/** L'interface impérative offerte au composant Vue. */
export interface PiloteCanevas {
  readonly index: IndexGraphe
  /** Le seul endroit du projet qui écrit l'attribut `viewBox`. */
  appliquerViewBox: (vb: Boite) => void
  /** Met en retrait tout ce qui n'est pas dans `garder`. */
  eclairer: (garder: ReadonlySet<string>, selection: string | null, chaine: ReadonlySet<string> | null) => void
  /** Rend tout le graphe à sa peinture pleine. */
  toutAfficher: () => void
  marquerTrouves: (ids: ReadonlySet<string>) => void
  effacerRecherche: () => void
  /** Le nœud sous un événement de souris, ou `null` hors d'une fiche. */
  noeudSousLeCurseur: (cible: EventTarget | null) => NoeudGraphe | null
  boiteDe: (id: string) => BoiteCentree | null
  boitesDe: (ids: Iterable<string>) => BoiteCentree[]
  detruire: () => void
}

export function construireCanevas(svg: SVGSVGElement, graphe: Graphe): PiloteCanevas {
  const index = indexer(graphe)

  while (svg.firstChild !== null) svg.removeChild(svg.firstChild)

  const gAretes = creer('g', { class: 'aretes' })
  const gFamilles = creer('g', { class: 'familles' })
  const gFiches = creer('g', { class: 'fiches' })

  /* ---- arêtes, sous les fiches ---- */

  const aretes: { el: SVGPathElement; de: string; vers: string }[] = []
  for (const tracee of tracerAretes(index, graphe.aretes)) {
    const path = creer('path', {
      class: tracee.classes.join(' '),
      'data-de': tracee.de,
      'data-vers': tracee.vers,
      d: tracee.d,
    })
    gAretes.appendChild(path)
    aretes.push({ el: path, de: tracee.de, vers: tracee.vers })
  }

  /* ---- nœuds de famille : losange 22 × 14, pas un cercle ---- */

  const familles = new Map<string, SVGGElement>()
  for (const famille of index.familles) {
    const g = creer('g', {
      class: classesDeFamille(famille).join(' '),
      'data-id': famille.id,
    })
    g.appendChild(creer('polygon', { points: pointsDeLosange(famille) }))
    if (famille.divorced) {
      g.appendChild(creer('path', { class: 'barre', d: barreDeDivorce(famille) }))
    }
    gFamilles.appendChild(g)
    familles.set(famille.id, g)
  }

  /* ---- fiches ---- */

  const fiches = new Map<string, SVGGElement>()
  for (const personne of index.personnes) {
    fiches.set(personne.id, construireFiche(personne, index, gFiches))
  }

  const fragment = document.createDocumentFragment()
  fragment.appendChild(gAretes)
  fragment.appendChild(gFamilles)
  fragment.appendChild(gFiches)
  svg.appendChild(fragment)

  /* ---- pilotage ---- */

  const boiteDe = (id: string): BoiteCentree | null => index.parId.get(id) ?? null

  return {
    index,

    appliquerViewBox(vb) {
      svg.setAttribute('viewBox', viewBoxEnTexte(vb))
    },

    eclairer(garder, selection, chaine) {
      svg.classList.add('focus')
      for (const [id, el] of fiches) {
        el.classList.toggle('on', garder.has(id))
        el.classList.toggle('sel', id === selection)
        el.classList.toggle('lien', chaine !== null && chaine.has(id) && id !== selection)
      }
      for (const [id, el] of familles) el.classList.toggle('on', garder.has(id))
      for (const a of aretes) a.el.classList.toggle('on', garder.has(a.de) && garder.has(a.vers))
    },

    toutAfficher() {
      svg.classList.remove('focus')
      for (const el of fiches.values()) el.classList.remove('on', 'sel', 'lien')
      for (const el of familles.values()) el.classList.remove('on')
      for (const a of aretes) a.el.classList.remove('on')
    },

    marquerTrouves(ids) {
      for (const [id, el] of fiches) el.classList.toggle('trouve', ids.has(id))
      svg.classList.add('recherche')
    },

    effacerRecherche() {
      svg.classList.remove('recherche')
      for (const el of fiches.values()) el.classList.remove('trouve')
    },

    noeudSousLeCurseur(cible) {
      if (!(cible instanceof Element)) return null
      const groupe = cible.closest('.fiche, .fam')
      if (groupe === null) return null
      const id = (groupe as SVGGElement).dataset.id
      if (id === undefined) return null
      return index.parId.get(id) ?? null
    },

    boiteDe,

    boitesDe(ids) {
      const boites: BoiteCentree[] = []
      for (const id of ids) {
        const boite = boiteDe(id)
        if (boite !== null) boites.push(boite)
      }
      return boites
    },

    detruire() {
      while (svg.firstChild !== null) svg.removeChild(svg.firstChild)
      svg.classList.remove('focus', 'recherche')
      svg.removeAttribute('viewBox')
    },
  }
}

function construireFiche(
  personne: NoeudPersonneEtendu,
  index: IndexGraphe,
  parent: SVGGElement,
): SVGGElement {
  const x = personne.x - personne.w / 2
  const y = personne.y - personne.h / 2
  const unions = index.unionsParPersonne.get(personne.id) ?? 0

  const g = creer('g', {
    class: classesDeFiche(personne, index.ego, unions).join(' '),
    'data-id': personne.id,
  })

  // `<title>` : le nom complet au survol et pour les lecteurs d'écran, alors que
  // la fiche ne porte que l'étiquette courte, mesurée par le moteur.
  const titre = document.createElementNS(NS_SVG, 'title')
  titre.textContent = personne.displayName
  g.appendChild(titre)

  g.appendChild(
    creer('rect', { x, y, width: personne.w, height: personne.h, rx: RAYON_FICHE }),
  )

  g.appendChild(
    creer('rect', {
      class: `br b-${personne.branch}`,
      x: x + LISERE_MARGE_X,
      y: y + LISERE_MARGE_Y,
      width: LISERE_LARGEUR,
      height: personne.h - 2 * LISERE_MARGE_Y,
    }),
  )

  // `libelle` est le NOM ; `personne.etiquette`, vingt lignes plus bas, est la
  // ligne de DATES. Deux notions que le contrat a fini par séparer justement
  // parce qu'un seul mot les désignait — on ne les rapproche pas ici.
  const libelle = nomDeFiche(personne)
  const nom = creer('text', { class: 'nm', x: personne.x, y: y + LIGNE_NOM })
  if (libelle.largeurImposee !== null) {
    // Le nom complet dépasse la boîte mesurée par le moteur : on le condense
    // pour qu'il y tienne, plutôt que de le laisser chevaucher les fiches
    // voisines. Aucun nom n'est coupé ni récrit — voir `nomDeFiche`.
    nom.setAttribute('textLength', String(libelle.largeurImposee))
    nom.setAttribute('lengthAdjust', 'spacingAndGlyphs')
  }
  nom.textContent = libelle.texte
  g.appendChild(nom)

  // Absente sur un nœud restreint — on ne dessine alors aucune ligne, et on ne
  // la reconstitue jamais. Voir `datesDeFiche` pour laquelle des trois formes
  // de date c'est, et pourquoi elle est à part.
  const dates = datesDeFiche(personne)
  if (dates !== null) {
    const ligne = creer('text', { class: 'dt', x: personne.x, y: y + LIGNE_DATES })
    ligne.textContent = dates
    g.appendChild(ligne)
  }

  parent.appendChild(g)
  return g
}
