import { describe, expect, it } from 'vitest'
import type { AreteGraphe, Graphe, NoeudFamille, NoeudPersonne, Sexe } from '@/lib/api'
import { sansFamillesParAlliance } from './alliances'
import { disposerLignees, type DispositionHistoire, type FicheHistoire } from './lignees'

/**
 * Ce que ces tests protègent : **aucun trait ne passe derrière une fiche**, et
 * **deux familles ne se confondent jamais** par des traits superposés.
 *
 * Le cas d'origine, relevé en production (fiches 467 et 407) : un oncle marié
 * deux fois, dont la première épouse a elle-même eu un autre compagnon. Ce
 * compagnon était posé au bord de l'arbre, et le trait d'union avec l'épouse
 * traversait toute la rangée, derrière une vingtaine de fiches.
 */

let prochain = 1

function construire(): { graphe: Graphe; id: Record<string, string> } {
  prochain = 1
  const noeuds: Graphe['noeuds'] = []
  const aretes: AreteGraphe[] = []
  const id: Record<string, string> = {}
  const personne = (cle: string, sex: Sexe, annee: number | null, w = 150): string => {
    const n = prochain++
    const p: NoeudPersonne = {
      id: `p${n}`,
      type: 'personne',
      personId: n,
      x: 0,
      y: 0,
      w,
      h: 56,
      displayName: cle,
      etiquetteNom: `${cle} Nom`,
      sex,
      generation: 0,
      branch: 'tr',
      vivant: false,
      visibilite: 'complete',
      ...(annee !== null ? { etiquetteDates: `${annee} – ` } : {}),
    }
    noeuds.push(p)
    id[cle] = p.id
    return p.id
  }
  const famille = (epoux: string[], enfants: string[]): string => {
    const f: NoeudFamille = { id: `f${prochain++}`, type: 'famille', familyId: prochain, x: 0, y: 0, w: 10, h: 10, divorced: false }
    noeuds.push(f)
    for (const e of epoux) aretes.push({ de: e, vers: f.id, genre: 'union' })
    for (const e of enfants) aretes.push({ de: f.id, vers: e, genre: 'filiation' })
    return f.id
  }

  const ego = personne('ego', 'masculin', 1998)
  const pere = personne('pere', 'masculin', 1969)
  const mere = personne('mere', 'feminin', 1971)
  famille([pere, mere], [ego])
  const gp = personne('gp', 'masculin', 1946)
  const gm = personne('gm', 'feminin', 1949)
  const tante = personne('tante', 'feminin', 1972)
  const oncle = personne('oncle', 'masculin', 1977)
  famille([gp, gm], [pere, tante, oncle])

  // L'oncle : deux unions. Sa première épouse a eu un autre compagnon.
  const epouse1 = personne('epouse1', 'feminin', 1975)
  const cousin = personne('cousin', 'masculin', 2005)
  famille([oncle, epouse1], [cousin])
  const epouse2 = personne('epouse2', 'feminin', 1980)
  famille([oncle, epouse2], [])
  const compagnon = personne('compagnon', 'masculin', 1970)
  const demiCousine = personne('demiCousine', 'feminin', 2000)
  famille([compagnon, epouse1], [demiCousine])

  // La tante a beaucoup d'enfants : de quoi faire chevaucher les barres voisines.
  const oncleAllie = personne('oncleAllie', 'masculin', 1970)
  famille([oncleAllie, tante], ['a', 'b', 'c', 'd'].map((k, i) => personne(k, 'masculin', 1995 + i)))

  const gpM = personne('gpM', 'masculin', 1940)
  const gmM = personne('gmM', 'feminin', 1942)
  famille([gpM, gmM], [mere, ...['e', 'f', 'g'].map((k, i) => personne(k, 'feminin', 1965 + i))])

  return {
    graphe: {
      empreinte: '0'.repeat(64),
      ego,
      boite: { x: 0, y: 0, w: 1, h: 1 },
      calque: { actif: false, demandes: [], proposes: 0, places: 0, nonPlaces: [] },
      noeuds,
      aretes,
    },
    id,
  }
}

interface Segment {
  x1: number
  y1: number
  x2: number
  y2: number
}

function segments(d: DispositionHistoire): Segment[] {
  const tout = Object.values(d.traces).join('')
  const re = /M([-\d.]+) ([-\d.]+)L([-\d.]+) ([-\d.]+)/g
  const liste: Segment[] = []
  let m: RegExpExecArray | null
  while ((m = re.exec(tout))) liste.push({ x1: +m[1]!, y1: +m[2]!, x2: +m[3]!, y2: +m[4]! })
  return liste
}

/** Un segment horizontal ou vertical qui entre DANS une fiche (pas seulement la toucher). */
function traverse(s: Segment, c: FicheHistoire): boolean {
  if (s.y1 === s.y2) {
    if (s.y1 <= c.y + 1 || s.y1 >= c.y + c.h - 1) return false
    const lo = Math.min(s.x1, s.x2)
    const hi = Math.max(s.x1, s.x2)
    return lo < c.x + c.w - 2 && hi > c.x + 2
  }
  if (s.x1 === s.x2) {
    if (s.x1 <= c.x + 1 || s.x1 >= c.x + c.w - 1) return false
    const lo = Math.min(s.y1, s.y2)
    const hi = Math.max(s.y1, s.y2)
    return lo < c.y + c.h - 2 && hi > c.y + 2
  }
  return false
}

describe('les traits de l’arbre', () => {
  const { graphe, id } = construire()
  const d = disposerLignees(graphe)
  const f = (cle: string) => d.parId.get(id[cle]!)!

  it('range l’autre compagnon d’une épouse dans une famille par alliance, à part', () => {
    // Décision du 2026-10-01 : le compagnon de la femme de l'oncle n'est ni du sang du
    // lecteur, ni le conjoint de quelqu'un de son sang — il est « par alliance », comme
    // sa fille. Masqués par défaut ; affichés, ils ont leur bloc, à droite de l'arbre,
    // et leur union avec l'épouse contourne par le dessous (le test suivant le tient).
    expect(f('compagnon').parente).toBe('alliance')
    expect(f('compagnon').pont).toBe(id['epouse1'])
    expect(f('demiCousine').parente).toBe('alliance')
    expect(f('epouse1').parente).toBe('famille')
    const bord = Math.max(...d.fiches.filter((x) => x.parente !== 'alliance').map((x) => x.x + x.w))
    expect(f('compagnon').x).toBeGreaterThan(bord)
    expect(f('compagnon').rangee).toBe(f('epouse1').rangee)
    expect(f('demiCousine').rangee).toBe(f('epouse1').rangee + 1)
    const sans = disposerLignees(sansFamillesParAlliance(graphe))
    expect(sans.parId.has(id['compagnon']!)).toBe(false)
    expect(sans.parId.has(id['demiCousine']!)).toBe(false)
    expect(sans.parId.has(id['epouse1']!)).toBe(true)
  })

  it('ne fait passer aucun trait derrière une fiche', () => {
    const fautes: string[] = []
    for (const s of segments(d)) {
      for (const c of d.fiches) if (traverse(s, c)) fautes.push(`${c.noeud.displayName} (${s.x1},${s.y1} → ${s.x2},${s.y2})`)
    }
    expect(fautes).toEqual([])
  })

  it('ne superpose jamais deux traits horizontaux différents à la même hauteur', () => {
    const horizontaux = segments(d)
      .filter((s) => s.y1 === s.y2)
      .map((s) => ({ y: s.y1, lo: Math.min(s.x1, s.x2), hi: Math.max(s.x1, s.x2) }))
      .sort((a, b) => a.y - b.y || a.lo - b.lo)
    const fautes: string[] = []
    for (let i = 0; i < horizontaux.length; i++) {
      for (let j = i + 1; j < horizontaux.length && horizontaux[j]!.y === horizontaux[i]!.y; j++) {
        const a = horizontaux[i]!
        const b = horizontaux[j]!
        const identique = Math.abs(a.lo - b.lo) < 0.5 && Math.abs(a.hi - b.hi) < 0.5
        // Un segment contenu dans un autre de la même famille (la portion directe
        // d'une barre, repeinte en ambre) n'est pas une confusion.
        const contenu = b.lo >= a.lo - 0.5 && b.hi <= a.hi + 0.5
        if (b.lo < a.hi - 1 && !identique && !contenu) fautes.push(`y=${a.y} [${a.lo},${a.hi}] × [${b.lo},${b.hi}]`)
      }
    }
    expect(fautes).toEqual([])
  })

  it('fait contourner par le dessous une union que d’autres fiches séparent', () => {
    // L'oncle et sa seconde épouse ne peuvent pas être voisins : sa première
    // épouse est entre eux.
    const oncle = f('oncle')
    const epouse2 = f('epouse2')
    // Le contournement : un trait horizontal dans la bande juste sous la rangée
    // (6 à 21 px sous les fiches), qui va d'un centre d'époux à l'autre.
    const bas = oncle.y + oncle.h
    const lo = Math.min(oncle.cx, epouse2.cx)
    const hi = Math.max(oncle.cx, epouse2.cx)
    const contournement = segments(d).filter(
      (s) =>
        s.y1 === s.y2 &&
        s.y1 >= bas + 5 &&
        s.y1 <= bas + 22 &&
        Math.abs(Math.min(s.x1, s.x2) - lo) < 0.5 &&
        Math.abs(Math.max(s.x1, s.x2) - hi) < 0.5,
    )
    expect(contournement).toHaveLength(1)
  })
})
