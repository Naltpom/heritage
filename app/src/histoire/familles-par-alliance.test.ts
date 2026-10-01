import { describe, expect, it } from 'vitest'
import type { AreteGraphe, Branche, Graphe, NoeudFamille, NoeudPersonne, Sexe } from '@/lib/api'
import { classerParente, sansFamillesParAlliance } from './alliances'
import { bandeauDesFamilles, type BrancheDuBandeau } from './bandeau'
import { largeurDuTexte, poserEtiquettes, type EtiquettePosee } from './etiquettes'
import { disposerLignees, type DispositionHistoire, type FicheHistoire } from './lignees'

/**
 * **Les captures « voir comme Camille », retournées.** Ce fichier était le diagnostic
 * du 01/10 (`diagnostic-alliance.test.ts`) : il constatait le défaut ; il tient
 * désormais la correction.
 *
 * L'arbre : la lectrice L ; sa mère M, fille du couple Voulgre × Figarol ; la sœur S de
 * M, mariée à P, qui a SIX générations d'ascendance propre (Provost, Bornerie,
 * Savignac…) avec des remariages et des foyers à un seul parent connu ; leurs enfants U
 * et U2. Les branches sont posées comme le serveur les servait AVANT la correction : P
 * et toute son ascendance en `mp`, la branche Voulgre de L — le front ne doit plus s'y
 * fier. Avant la correction, mesuré sur ce même arbre : 281 personnes affichées, la
 * bande Voulgre étirée de x 60 à 21 275, 446 croisements de traits, 37 traits
 * horizontaux de plus de 1 500 unités, 42 paires d'étiquettes de foyer superposées à
 * l'échelle 0,3, et deux noms du bandeau au même pixel.
 */

let prochain = 1
const NOMS_P = ['Provost', 'Bornerie', 'Savignac', 'Lalot', 'Bonmartin', 'Basset', 'Becquelin', 'Dumortier', 'Damay', 'Ducatteau', 'Delobel', 'Destombes']

function construire(): { graphe: Graphe; ids: Record<string, string>; alliance: Set<string> } {
  prochain = 1
  const noeuds: Graphe['noeuds'] = []
  const aretes: AreteGraphe[] = []
  const ids: Record<string, string> = {}
  const alliance = new Set<string>()

  const personne = (cle: string, nom: string, sex: Sexe, annee: number, branch: Branche): string => {
    const n = prochain++
    const p: NoeudPersonne = {
      id: `p${n}`,
      type: 'personne',
      personId: n,
      x: 0,
      y: 0,
      w: 60 + nom.length * 7,
      h: 56,
      displayName: nom,
      etiquetteNom: nom,
      sex,
      generation: 0,
      branch,
      vivant: annee > 1940,
      visibilite: 'complete',
      etiquetteDates: `${annee} – `,
    }
    noeuds.push(p)
    ids[cle] = p.id
    return p.id
  }
  const famille = (epoux: string[], enfants: string[]): string => {
    const f: NoeudFamille = { id: `f${prochain++}`, type: 'famille', familyId: prochain, x: 0, y: 0, w: 10, h: 10, divorced: false }
    noeuds.push(f)
    for (const e of epoux) aretes.push({ de: e, vers: f.id, genre: 'union' })
    for (const e of enfants) aretes.push({ de: f.id, vers: e, genre: 'filiation' })
    return f.id
  }

  // Le tronc de L.
  const L = personne('L', 'Camille Martin', 'feminin', 1995, 'tr')
  const F = personne('F', 'Thomas Martin', 'masculin', 1965, 'tr')
  const M = personne('M', 'Nicole Voulgre', 'feminin', 1967, 'tr')
  famille([F, M], [L])
  const gpp = personne('gpp', 'Jean Martin', 'masculin', 1935, 'pp')
  const gpm = personne('gpm', 'Paule Roux', 'feminin', 1938, 'pm')
  famille([gpp, gpm], [F])

  // Le côté mère : Voulgre (mp) × Figarol (mm), avec une fratrie par génération.
  const gv = personne('gv', 'Gabriel Voulgre', 'masculin', 1930, 'mp')
  const gf = personne('gf', 'Marie Figarol', 'feminin', 1937, 'mm')
  const S = personne('S', 'Emmanuelle Voulgre', 'feminin', 1971, 'mp')
  famille([gv, gf], [M, S])
  let enfant = gv
  for (let g = 2; g <= 5; g++) {
    const pere = personne(`v${g}`, `Pierre${g} Voulgre`, 'masculin', 1930 - 28 * (g - 1), 'mp')
    const mere = personne(`vf${g}`, `Irène${g} Marboeuf`, 'feminin', 1932 - 28 * (g - 1), 'mp')
    const frere = personne(`vfr${g}`, `Paul${g} Voulgre`, 'masculin', 1934 - 28 * (g - 1), 'mp')
    famille([pere, mere], [enfant, frere])
    enfant = pere
  }
  enfant = gf
  for (let g = 2; g <= 4; g++) {
    const pere = personne(`f${g}`, `Louis${g} Figarol`, 'masculin', 1935 - 28 * (g - 1), 'mm')
    const mere = personne(`ff${g}`, `Jeanne${g} Amelot`, 'feminin', 1937 - 28 * (g - 1), 'mm')
    famille([pere, mere], [enfant])
    enfant = pere
  }

  // La tante S, son mari P et leurs enfants (cousins germains de L).
  const P = personne('P', 'Laurent Provost', 'masculin', 1969, 'mp')
  const U = personne('U', 'Nathan Provost', 'masculin', 1998, 'mp')
  const U2 = personne('U2', 'Lucie Provost', 'feminin', 2001, 'mp')
  famille([P, S], [U2, U])

  // L'ascendance de P : six générations, une fratrie (un collatéral) par couple.
  const file: [string, number, string, number][] = [[P, 1, 'Provost', 1969]]
  let k = 0
  while (file.length) {
    const [enf, g, nomPere, an] = file.shift()!
    if (g > 6) continue
    const nomMere = NOMS_P[++k % NOMS_P.length]!
    const pere = personne(`a${k}p`, `Jean${k} ${nomPere}`, 'masculin', an - 28, 'mp')
    const mere = personne(`a${k}m`, `Marie${k} ${nomMere}`, 'feminin', an - 26, 'mp')
    const collateral = personne(`a${k}c`, `Louis${k} ${nomPere}`, 'masculin', an + 2, 'mp')
    alliance.add(pere).add(mere).add(collateral)
    famille([pere, mere], [enf, collateral])
    file.push([pere, g + 1, nomPere, an - 28], [mere, g + 1, nomMere, an - 26])
    // Ce que portent les vraies données à cette profondeur : des REMARIAGES (le même
    // homme dans deux foyers, sur la même rangée) et des foyers à UN seul époux connu.
    if (k % 3 === 0) {
      const seconde = personne(`a${k}s`, `Rose${k} ${NOMS_P[(k + 5) % NOMS_P.length]}`, 'feminin', an - 20, 'mp')
      const demi = personne(`a${k}d`, `Paul${k} ${nomPere}`, 'masculin', an + 8, 'mp')
      alliance.add(seconde).add(demi)
      famille([pere, seconde], [demi])
    } else if (k % 3 === 1) {
      const naturel = personne(`a${k}n`, `Anne${k} ${nomMere}`, 'feminin', an - 4, 'mp')
      alliance.add(naturel)
      famille([mere], [naturel])
    }
  }

  const graphe: Graphe = {
    empreinte: '0'.repeat(64),
    ego: L,
    boite: { x: 0, y: 0, w: 1000, h: 1000 },
    calque: { actif: false, demandes: [], proposes: 0, places: 0, nonPlaces: [] },
    noeuds,
    aretes,
  }
  return { graphe, ids, alliance }
}

/** Les segments d'un chemin `M x y L x y` du tracé. */
function segments(chemin: string): [number, number, number, number][] {
  return [...chemin.matchAll(/M(-?[\d.]+) (-?[\d.]+)L(-?[\d.]+) (-?[\d.]+)/g)].map((m) => [
    Number(m[1]),
    Number(m[2]),
    Number(m[3]),
    Number(m[4]),
  ])
}

/** Croisements stricts entre un segment horizontal et un vertical ; ceux d'abscisse ≤ `xMax` si donnée. */
function croisements(d: DispositionHistoire, xMax = Infinity): number {
  const tous = [...segments(d.traces.ordinaires), ...segments(d.traces.directs)]
  const h = tous.filter(([, y1, , y2]) => y1 === y2)
  const v = tous.filter(([x1, , x2]) => x1 === x2)
  let n = 0
  for (const [hx1, hy, hx2] of h) {
    const [a, b] = [Math.min(hx1, hx2), Math.max(hx1, hx2)]
    for (const [vx, vy1, , vy2] of v) {
      if (vx > xMax) continue
      const [c, e] = [Math.min(vy1, vy2), Math.max(vy1, vy2)]
      if (vx > a + 0.5 && vx < b - 0.5 && hy > c + 0.5 && hy < e - 0.5) n++
    }
  }
  return n
}

/** Les paires d'étiquettes qui se recouvrent sur une même ligne. */
function recouvrements(posees: EtiquettePosee[], hauteur = 14): string[] {
  const fautes: string[] = []
  for (let i = 0; i < posees.length; i++)
    for (let j = i + 1; j < posees.length; j++) {
      const [a, b] = [posees[i]!, posees[j]!]
      if (Math.abs(a.y - b.y) >= hauteur) continue
      if (Math.abs(a.x - b.x) < (a.largeur + b.largeur) / 2)
        fautes.push(`${a.nom}@${Math.round(a.x)} / ${b.nom}@${Math.round(b.x)}`)
    }
  return fautes
}

describe('familles par alliance — l’ascendance du mari de la tante, vue de la nièce', () => {
  const { graphe, ids, alliance } = construire()
  const classes = classerParente(graphe)

  it('classe toute l’ascendance de P « par alliance », pont P ; P, S, U, U2 sont de la famille', () => {
    const parAlliance = new Set([...classes].filter(([, c]) => c.parente === 'alliance').map(([id]) => id))
    expect(parAlliance).toEqual(alliance)
    for (const id of alliance) expect(classes.get(id)!.pont).toBe(ids.P)
    // Le pont est de la famille : conjoint d'une descendante d'ancêtre.
    for (const cle of ['L', 'M', 'S', 'P', 'U', 'U2', 'gv', 'v5', 'vfr3', 'ff4'])
      expect(classes.get(ids[cle]!)!.parente, cle).toBe('famille')
  })

  describe('par défaut : masquées', () => {
    const d = disposerLignees(sansFamillesParAlliance(graphe))

    it('ne garde que la famille, pont compris — le compteur suit (29, contre 281)', () => {
      expect(d.fiches).toHaveLength(29)
      expect(d.parId.has(ids.P!)).toBe(true)
      for (const id of alliance) expect(d.parId.has(id)).toBe(false)
      expect(d.alliances).toEqual([])
    })

    it('ne croise aucun trait', () => {
      expect(croisements(d)).toBe(0)
    })
  })

  describe('affichées : chacune son bloc, à droite', () => {
    const d = disposerLignees(graphe)
    const fiche = (id: string): FicheHistoire => d.parId.get(id)!
    const bordFamille = Math.max(...d.fiches.filter((f) => f.parente !== 'alliance').map((f) => f.x + f.w))

    it('pose toute l’alliance à droite de la famille, sans rien intercaler', () => {
      expect(d.fiches).toHaveLength(281)
      for (const id of alliance) {
        expect(fiche(id).parente).toBe('alliance')
        expect(fiche(id).x, fiche(id).noeud.displayName).toBeGreaterThan(bordFamille)
      }
      // P reste à côté de sa femme, dans l'arbre de la lectrice.
      expect(fiche(ids.P!).parente).toBe('famille')
      expect(fiche(ids.P!).rangee).toBe(fiche(ids.S!).rangee)
      expect(fiche(ids.P!).x).toBeLessThan(bordFamille)
      // Ses parents au-dessus de lui, à leur génération ; le père à gauche de la mère.
      const [pere, mere] = [fiche(ids.a1p!), fiche(ids.a1m!)]
      expect(pere.rangee).toBe(fiche(ids.P!).rangee - 1)
      expect(mere.rangee).toBe(fiche(ids.P!).rangee - 1)
      expect(pere.cx).toBeLessThan(mere.cx)
      expect(d.alliances).toHaveLength(1)
      expect(d.alliances[0]).toMatchObject({ pont: ids.P, nom: 'Provost', effectif: alliance.size })
    })

    it('ne gonfle plus la bande Voulgre : les quarts ne comptent que la famille', () => {
      const mp = d.quarts.find((q) => q.branche === 'mp')!
      const sangVoulgre = d.fiches.filter((f) => f.noeud.branch === 'mp' && !alliance.has(f.id))
      expect(mp.effectif).toBe(sangVoulgre.length)
      expect(d.alliances[0]!.x0).toBeGreaterThan(mp.x1)
    })

    it('ne croise aucun trait chez la lectrice, et presque aucun dans le bloc (446 avant)', () => {
      expect(croisements(d, bordFamille)).toBe(0)
      // Dans le bloc : seuls les remariages d'un parent « intérieur » (le père d'une
      // mère, la mère d'un père) contournent son couple — un trait croisé chacun.
      expect(croisements(d)).toBeLessThan(45)
      // Plus de barre de fratrie qui traverse l'arbre (37 traits de plus de 1 500 avant) :
      // une seule longue, le PONT — celle qui descend des parents de P, dans le bloc,
      // jusqu'à P, resté à côté de sa femme. (Les unions d'ancêtres, elles, sont longues
      // par nature : la largeur de leur ascendance les sépare, sans rien croiser.)
      const hauts = new Set(d.fiches.map((f) => f.y))
      const barres = segments(d.traces.ordinaires).filter(
        ([, y1, , y2]) => y1 === y2 && [...hauts].some((h) => y1 < h && y1 >= h - 40),
      )
      const longues = barres.filter(([x1, , x2]) => Math.abs(x2 - x1) > 1500)
      expect(longues).toHaveLength(1)
      const [x1, , x2] = longues[0]!
      expect(Math.min(x1, x2)).toBeCloseTo(fiche(ids.P!).cx, 0)
    })

    it('ne recouvre aucune étiquette de foyer, à aucune échelle du niveau Familles', () => {
      for (const s of [0.18, 0.3, 0.4, 0.5, 0.8]) {
        // Le calcul de `libellesFoyers` (HistoireView), à l'échelle s, sans cadrage.
        const demandes = d.foyers
          .filter((c) => (c.ancetres || s > 0.25) && c.envergure * s > 44)
          .map((c, i) => ({
            cle: String(i),
            x: c.x * s,
            y: c.y * s,
            nom: c.nom,
            taille: 10.5,
            min: c.x * s - (c.envergure * s) / 2 - 12,
            max: c.x * s + (c.envergure * s) / 2 + 12,
            priorite: (c.ancetres ? 1e9 : 0) + c.envergure,
          }))
        const posees = poserEtiquettes(demandes, { marge: 6, hauteur: 14 })
        expect(recouvrements(posees), `s = ${s}`).toEqual([])
        expect(posees.length).toBeGreaterThan(0)
      }
    })

    it('n’écrit qu’une étiquette par personne et par rangée (« Provos Provost »)', () => {
      const cles = d.foyers.map((e) => `${e.personne}|${e.y}`)
      expect(new Set(cles).size).toBe(cles.length)
    })

    it('ne recouvre jamais deux noms du bandeau, sur tout un balayage', () => {
      const largeurEcran = 1400
      for (const s of [0.05, 0.1, 0.2, 0.3, 0.6, 1])
        for (let centre = 0; centre < d.largeur; centre += 150) {
          const tx = largeurEcran / 2 - centre * s
          const branches: BrancheDuBandeau[] = [
            ...d.quarts.map((q) => ({
              cle: q.branche,
              couleur: '',
              nom: q.branche === 'mp' ? 'Voulgre' : q.branche === 'mm' ? 'Figarol' : q.branche,
              sous: `${q.libelle} · ${q.effectif}`,
              x0: q.x0 * s + tx,
              x1: q.x1 * s + tx,
            })),
            ...d.alliances.map((a) => ({
              cle: `alliance-${a.pont}`,
              couleur: '',
              nom: a.nom,
              sous: `famille par alliance · ${a.effectif}`,
              x0: a.x0 * s + tx,
              x1: a.x1 * s + tx,
            })),
          ]
          const segs = bandeauDesFamilles(branches, 140, largeurEcran, 13).filter((x) => x.etiquette)
          // La place réelle de chaque nom : la plus large de ses deux lignes.
          const posees: EtiquettePosee[] = segs.map((x) => ({
            cle: x.cle,
            x: x.etiquette!.x,
            y: 0,
            nom: x.etiquette!.nom,
            sous: x.etiquette!.sous,
            largeur: Math.max(largeurDuTexte(x.etiquette!.nom, 13), x.etiquette!.sous ? largeurDuTexte(x.etiquette!.sous, 11) : 0),
          }))
          expect(recouvrements(posees), `s = ${s}, centre = ${centre}`).toEqual([])
          // Chaque nom reste dans la part visible de sa branche.
          for (const x of segs) {
            expect(x.etiquette!.x).toBeGreaterThanOrEqual(x.gauche)
            expect(x.etiquette!.x).toBeLessThanOrEqual(x.gauche + x.largeur)
          }
        }
    })
  })
})
