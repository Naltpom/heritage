/**
 * L'arbre en 3D — où pousse chaque feuille. Pur, sans WebGL : `disposition.test.ts`.
 *
 * ────────────────────────────────────────────────────────────────────
 * **Le principe.** Un vrai arbre, posé sur l'île :
 *
 * - **le lecteur est au sommet du tronc** (l'ego du graphe), face à la caméra ;
 * - **ses ancêtres forment la couronne** : une génération par étage, chaque
 *   étage plus haut et plus large que le précédent ;
 * - **ses descendants sont les racines**, sous l'île flottante ;
 * - **les couples sont des nœuds dans le bois** : la branche y bifurque vers
 *   les enfants.
 *
 * **Ce qui range une feuille, c'est sa génération ; ce qui la colore, c'est sa
 * lignée.** Ranger par lignée (quatre grosses branches) était tentant, et
 * faux : sur le graphe servi, le tronc porte 431 personnes sur 468 (voir
 * `Branche`, `lib/api/types.ts`) — trois branches seraient presque nues et la
 * quatrième écrasée.
 *
 * **L'ordre autour de l'étage est celui du moteur de placement** (`x`, ADR
 * 0005) : frères, cousins et couples y sont déjà voisins. On le replie en
 * anneau, centré pour que la personne la plus proche du lecteur en `x` soit
 * devant. Un étage trop peuplé pour un anneau en prend plusieurs, un peu plus
 * larges et plus hauts, garnis en alternance : jamais deux feuilles l'une sur
 * l'autre, et des voisins qui restent voisins.
 *
 * **Rien ici ne lit un nom pour décider.** La confidentialité est déjà faite
 * par le serveur (cercle de visibilité, ADR 0003) ; un nœud restreint arrive
 * avec un libellé neutre, et c'est ce libellé que la feuille porte.
 * ────────────────────────────────────────────────────────────────────
 */

import type { Graphe, NoeudFamille, NoeudPersonne } from '@/lib/api'
import { ligneDe } from '@/histoire/ligneDirecte'
import { nomEcrit } from '@/histoire/lignees'

export type Point = [number, number, number]

/** Les lignées qui ont leur teinte ; tout le reste est `hors`. */
export type Teinte = 'pp' | 'pm' | 'mp' | 'mm' | 'tr' | 'cpp' | 'cpm' | 'cmp' | 'cmm' | 'hors'
const TEINTES = new Set<string>(['pp', 'pm', 'mp', 'mm', 'tr', 'cpp', 'cpm', 'cmp', 'cmm'])

export interface Feuille {
  id: string
  personId: number | null
  /** Le nom à écrire : le libellé neutre sur un nœud restreint (`nomEcrit`). */
  nom: string
  /** Le nom complet, pour le panneau — neutre aussi sur un nœud restreint. */
  nomComplet: string
  dates: string
  generation: number
  teinte: Teinte
  vivant: boolean
  restreinte: boolean
  /** Une demande en attente touche cette fiche : le bourgeon luit. */
  enAttente: boolean
  /** Un portrait existe (son URL ne sert qu'au panneau, pas à la 3D). */
  portrait: string | null
  ego: boolean
  ligne: 'ascendant' | 'descendant' | null
  position: Point
}

export interface Noeud {
  id: string
  position: Point
  divorce: boolean
  /** Le couple relie deux générations de la ligne directe. */
  direct: boolean
}

/** Un rameau de bois, d'un point à un autre, courbé vers l'extérieur. */
export interface Rameau {
  de: Point
  vers: Point
  /** `filiation` : du nœud du couple vers l'enfant ; `union` : de l'époux vers le nœud. */
  genre: 'union' | 'filiation'
  direct: boolean
  enAttente: boolean
}

export interface DispositionDeLArbre {
  feuilles: Feuille[]
  noeuds: Noeud[]
  rameaux: Rameau[]
  /** Le haut du tronc : là où est le lecteur. */
  hautDuTronc: number
  /** Le plus grand rayon atteint par une feuille : la caméra s'en sert pour cadrer. */
  rayon: number
  /** Le plus haut et le plus bas atteints. */
  haut: number
  bas: number
  /** Combien de feuilles par génération, pour la légende des étages. */
  parGeneration: Map<number, number>
}

/* ───────────────── Les mesures de l'arbre ───────────────── */

export const HAUT_DU_TRONC = 5
/** L'écart minimal entre deux feuilles voisines d'un anneau. */
export const ECART_MIN = 1.05
/** La part d'un anneau qu'on remplit : le dos de l'arbre reste ouvert, la caméra y voit clair. */
const ANNEAU_UTILE = 0.86
const ETAGE_ANCETRES = 2.3
const ETAGE_RACINES = 1.8

/** Le rayon de base d'un étage, avant tout élargissement pour faire place. */
export function rayonDeBase(generation: number): number {
  if (generation < 0) return 2.2 + 1.35 * -generation
  if (generation === 0) return 2.2
  return 1.4 + 1.2 * generation
}

/** La hauteur de base d'un étage : la couronne au-dessus du tronc, les racines sous l'île. */
export function hauteurDeBase(generation: number): number {
  if (generation < 0) return HAUT_DU_TRONC + 0.6 + ETAGE_ANCETRES * -generation
  if (generation === 0) return HAUT_DU_TRONC - 0.2
  return -2.4 - ETAGE_RACINES * (generation - 1)
}

/** Combien de feuilles tient un anneau de ce rayon. */
export function capacite(rayon: number): number {
  return Math.max(1, Math.floor((ANNEAU_UTILE * 2 * Math.PI * rayon) / ECART_MIN))
}

function teinteDe(n: NoeudPersonne): Teinte {
  // Une famille par alliance ne se range jamais par sa branche (voir `Branche`).
  if (n.parente === 'alliance') return 'hors'
  return TEINTES.has(n.branch) ? (n.branch as Teinte) : 'hors'
}

const centreX = (n: { x: number; w: number }): number => n.x + n.w / 2

export function disposerArbre(graphe: Graphe): DispositionDeLArbre {
  const personnes = graphe.noeuds.filter((n): n is NoeudPersonne => n.type === 'personne')
  const familles = graphe.noeuds.filter((n): n is NoeudFamille => n.type === 'famille')
  const ego = personnes.find((p) => p.id === graphe.ego) ?? null
  const egoX = ego ? centreX(ego) : 0
  const { ascendants, descendants } = ego ? ligneDe(graphe, ego.id) : { ascendants: new Set<string>(), descendants: new Set<string>() }

  const positions = new Map<string, Point>()
  const parGeneration = new Map<number, number>()
  let rayonMax = 0
  let haut = HAUT_DU_TRONC
  let bas = 0

  // Étage par étage, dans l'ordre du moteur de placement.
  const etages = new Map<number, NoeudPersonne[]>()
  for (const p of personnes) {
    if (p === ego) continue
    const l = etages.get(p.generation)
    if (l) l.push(p)
    else etages.set(p.generation, [p])
  }
  for (const [g, membres] of etages) {
    membres.sort((a, b) => centreX(a) - centreX(b) || a.id.localeCompare(b.id))
    parGeneration.set(g, membres.length)
    // Un seul ordre angulaire pour tout l'étage : des voisins dans l'ordre du moteur
    // restent voisins autour de l'arbre. Un étage trop peuplé pour un anneau en prend
    // plusieurs, en ALTERNANT (1er, 2e, 3e, 1er…) : chaque anneau reste régulier, et
    // un couple ou une fratrie ne se retrouve jamais aux deux bouts de l'arbre.
    const r0 = rayonDeBase(g)
    const n = membres.length
    const anneaux = Math.max(1, Math.ceil((n * ECART_MIN) / (ANNEAU_UTILE * 2 * Math.PI * r0)))
    const pas = ECART_MIN / (anneaux * r0)
    // Centrer : la feuille la plus proche du lecteur en `x` est devant (θ = 0).
    let pivot = 0
    let meilleur = Infinity
    membres.forEach((m, i) => {
      const d = Math.abs(centreX(m) - egoX)
      if (d < meilleur) {
        meilleur = d
        pivot = i
      }
    })
    membres.forEach((m, i) => {
      const rang = i % anneaux
      const r = r0 + rang * 0.95
      const y = hauteurDeBase(g) + (g > 0 ? -0.55 : 0.55) * rang
      const theta = (i - pivot) * pas
      positions.set(m.id, [Math.sin(theta) * r, y, Math.cos(theta) * r])
      rayonMax = Math.max(rayonMax, r)
      haut = Math.max(haut, y)
      bas = Math.min(bas, y)
    })
  }
  if (ego) {
    positions.set(ego.id, [0, HAUT_DU_TRONC, 0])
    parGeneration.set(0, (parGeneration.get(0) ?? 0) + 1)
  }

  const feuilles: Feuille[] = personnes.map((p) => {
    const restreinte = p.visibilite === 'restreinte'
    return {
      id: p.id,
      personId: p.personId,
      nom: nomEcrit(p),
      nomComplet: restreinte ? nomEcrit(p) : p.displayName,
      dates: p.vivant ? '' : (p.etiquetteDates ?? ''),
      generation: p.generation,
      teinte: teinteDe(p),
      vivant: p.vivant,
      restreinte,
      enAttente: p.enAttente === true,
      portrait: restreinte ? null : (p.portrait ?? null),
      ego: p === ego,
      ligne: p === ego || ascendants.has(p.id) ? 'ascendant' : descendants.has(p.id) ? 'descendant' : null,
      position: positions.get(p.id) ?? [0, 0, 0],
    }
  })

  // Les couples : un nœud dans le bois, un peu sous leurs feuilles et vers le tronc.
  const epoux = new Map<string, string[]>()
  const enfants = new Map<string, string[]>()
  for (const a of graphe.aretes) {
    const m = a.genre === 'union' ? epoux : enfants
    const cle = a.genre === 'union' ? a.vers : a.de
    const val = a.genre === 'union' ? a.de : a.vers
    const l = m.get(cle)
    if (l) l.push(val)
    else m.set(cle, [val])
  }
  const dansLaLigne = (id: string): boolean => ascendants.has(id) || descendants.has(id)
  const noeuds: Noeud[] = []
  const posNoeud = new Map<string, Point>()
  for (const f of familles) {
    const pts = (epoux.get(f.id) ?? []).map((id) => positions.get(id)).filter((p): p is Point => p !== undefined)
    if (pts.length === 0) {
      // Une famille sans époux visible : son nœud se pose au-dessus de ses enfants.
      const e = (enfants.get(f.id) ?? []).map((id) => positions.get(id)).filter((p): p is Point => p !== undefined)
      if (e.length === 0) continue
      pts.push([e[0]![0], e[0]![1] + 1.6, e[0]![2]])
    }
    const moy: Point = [pts.reduce((s, p) => s + p[0], 0) / pts.length, pts.reduce((s, p) => s + p[1], 0) / pts.length, pts.reduce((s, p) => s + p[2], 0) / pts.length]
    // Sous les époux (au-dessus, pour un couple de racines), et rapproché de l'axe.
    const versLeHaut = moy[1] < 0
    const p: Point = [moy[0] * 0.86, moy[1] + (versLeHaut ? 0.8 : -0.85), moy[2] * 0.86]
    posNoeud.set(f.id, p)
    const direct =
      (epoux.get(f.id) ?? []).some(dansLaLigne) && (enfants.get(f.id) ?? []).some((c) => dansLaLigne(c) || c === ego?.id)
    noeuds.push({ id: f.id, position: p, divorce: f.divorced, direct })
  }

  const noeudDirect = new Map(noeuds.map((n) => [n.id, n.direct]))
  const rameaux: Rameau[] = []
  for (const a of graphe.aretes) {
    if (a.genre === 'union') {
      const de = positions.get(a.de)
      const vers = posNoeud.get(a.vers)
      if (!de || !vers) continue
      rameaux.push({ de, vers, genre: 'union', direct: dansLaLigne(a.de) && noeudDirect.get(a.vers) === true, enAttente: a.enAttente === true })
    } else {
      const de = posNoeud.get(a.de)
      const vers = positions.get(a.vers)
      if (!de || !vers) continue
      rameaux.push({ de, vers, genre: 'filiation', direct: dansLaLigne(a.vers) && noeudDirect.get(a.de) === true, enAttente: a.enAttente === true })
    }
  }

  return { feuilles, noeuds, rameaux, hautDuTronc: HAUT_DU_TRONC, rayon: rayonMax, haut, bas, parGeneration }
}

/* ───────────────── Recherche ───────────────── */

/** Les feuilles dont le nom écrit contient la recherche — sans accent ni casse ; jamais une feuille restreinte. */
export function chercher(feuilles: readonly Feuille[], q: string, max = 8): Feuille[] {
  const plier = (s: string): string => s.normalize('NFD').replace(/\p{M}/gu, '').toLowerCase()
  const c = plier(q.trim())
  if (c.length < 2) return []
  return feuilles.filter((f) => !f.restreinte && plier(f.nomComplet).includes(c)).slice(0, max)
}
