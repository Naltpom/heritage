import { describe, expect, it } from 'vitest'
import { BRIQUE, epaisseurDuMur } from '@/lib/finDeRecherche'
import { ArbreAffiche } from './donnees'
import { arbreDeCousins, arbreFictif, avecFinsDeRecherche, avecLeCoupleEnFinDeRecherche, mursDeLaScene } from './essai/arbreFictif'
import { aUnMur, desMurs, murDansUneCase, murDansUnSecteur, murDuCouple, TAILLE_DU_MUR, TAILLE_MIN } from './murs'
import { Scene } from './scene'
import { ascendance } from './structure'

/** Les coordonnées d'un tracé de mur : tous les nombres de son chemin, deux à deux. */
function boite(d: string): { x0: number; y0: number; x1: number; y1: number } {
  const n = d.match(/-?\d+(\.\d+)?/g)!.map(Number)
  const xs: number[] = []
  const ys: number[] = []
  // Le tracé n'a que des commandes absolues M, H, V, L, Z : on les relit une à une.
  let x = 0
  let y = 0
  const re = /([MHVLZ])(-?\d+(?:\.\d+)?)?(?: (-?\d+(?:\.\d+)?))?/g
  for (const m of d.matchAll(re)) {
    if (m[1] === 'M' || m[1] === 'L') [x, y] = [Number(m[2]), Number(m[3])]
    else if (m[1] === 'H') x = Number(m[2])
    else if (m[1] === 'V') y = Number(m[2])
    if (m[1] !== 'Z') {
      xs.push(x)
      ys.push(y)
    }
  }
  expect(n.length).toBeGreaterThan(0)
  return { x0: Math.min(...xs), y0: Math.min(...ys), x1: Math.max(...xs), y1: Math.max(...ys) }
}

describe('qui porte un mur', () => {
  const { essai, sommets, parentServi } = avecFinsDeRecherche(arbreFictif())
  const A = new ArbreAffiche(essai.charge)

  it('un sommet de branche clos en porte un', () => {
    for (const id of sommets) expect(aUnMur(A, A.P.get(id)!), `personne ${id}`).toBe(true)
  })

  it('un parent servi l’emporte sur une fin de recherche restée enregistrée', () => {
    const p = A.P.get(parentServi)!
    expect(p.finsDeRecherche.finDeRecherchePere).toBeDefined()
    expect(aUnMur(A, p)).toBe(false)
  })

  it('sans fin de recherche, personne n’en porte', () => {
    const sans = new ArbreAffiche(arbreFictif().charge)
    expect([...sans.P.values()].some((p) => aUnMur(sans, p))).toBe(false)
  })

  it('une personne restreinte n’en porte jamais, même si la charge en portait une', () => {
    const base = arbreFictif().charge
    const restreinte = { id: 99999, visibilite: 'restreinte', sex: 'inconnu', finDeRecherchePere: { reason: 'lacune', beforeYear: null } }
    const charge = { ...base, personnes: [...base.personnes, restreinte] } as unknown as typeof base
    const B = new ArbreAffiche(charge)
    expect(B.P.get(99999)!.finsDeRecherche).toEqual({})
    expect(aUnMur(B, B.P.get(99999)!)).toBe(false)
  })

  it('un ancêtre tronqué (profondeur) ou une seconde occurrence garde ses parents connus : pas de mur', () => {
    const H = A.P.get(essai.mari)!
    const F = A.P.get(essai.femme)!
    const { noeuds } = ascendance(A, H, F, 2)
    const coupes = noeuds.filter((n) => n.niveau === 2 && A.parents(n.p.id).some((p) => p !== null))
    expect(coupes.length).toBeGreaterThan(0)
    for (const n of coupes) expect(aUnMur(A, n.p)).toBe(false)
    const tous = ascendance(A, H, F, Infinity).noeuds
    for (const n of tous.filter((x) => x.tronque)) expect(aUnMur(A, n.p)).toBe(false)
  })

  it('desMurs() compte le couple, même quand son premier anneau est masqué (les quatre parents au centre)', () => {
    const cousins = avecFinsDeRecherche(arbreDeCousins()).essai
    const C = new ArbreAffiche(cousins.charge)
    const { noeuds } = ascendance(C, C.P.get(cousins.mari)!, C.P.get(cousins.femme)!, Infinity)
    expect(desMurs(C, noeuds)).toBe(true)
    expect(desMurs(new ArbreAffiche(arbreFictif().charge), noeuds.slice(0, 3))).toBe(false)

    // Le couple seul porte un mur : il suffit à la légende.
    const couple = avecLeCoupleEnFinDeRecherche(arbreDeCousins())
    const A = new ArbreAffiche(couple.charge)
    const sansAncetres = ascendance(A, A.P.get(couple.mari)!, A.P.get(couple.femme)!, Infinity).noeuds
    expect(sansAncetres.filter((n) => aUnMur(A, n.p)).map((n) => n.niveau)).toEqual([0])
    expect(desMurs(A, sansAncetres)).toBe(true)
  })

  it('murDuCouple() ne désigne que les deux époux', () => {
    const couple = avecLeCoupleEnFinDeRecherche(arbreDeCousins())
    const A = new ArbreAffiche(couple.charge)
    const H = A.P.get(couple.mari)!
    const F = A.P.get(couple.femme)!
    const mur = murDuCouple(A, H, F)

    expect(mur(F)).toBe(true)
    expect(mur(H)).toBe(false)
    // Un frère, un enfant : jamais, même avec une fin de recherche.
    for (const p of A.P.values()) if (p.id !== F.id) expect(mur(p)).toBe(false)
  })
})

describe('le mur dans une case', () => {
  it('pose un chemin en BRIQUE, sans fond, dans le coin haut droit', () => {
    const s = new Scene(200, 100)
    const t = murDansUneCase(s, 10, 20, 60, 30, 1)
    const [m] = mursDeLaScene(s)
    expect(mursDeLaScene(s)).toHaveLength(1)
    expect(m!.fond).toBe('none')
    expect(m!.trait).toBe(BRIQUE)
    expect(m!.epaisseur).toBeCloseTo(epaisseurDuMur(t), 9)
    expect(m!.arrondi).toBe(true)
    expect(t).toBeCloseTo(TAILLE_DU_MUR, 9)
    const b = boite(m!.d)
    // Dans la case, à droite et en haut, à 0,8 mm près du bord (le cadre du mur est dans sa boîte de 24).
    expect(b.x1).toBeLessThanOrEqual(70 - 0.8 + 1e-6)
    expect(b.y0).toBeGreaterThanOrEqual(20 + 0.8 - 1e-6)
    expect(b.x0).toBeGreaterThan(10 + 30)
  })

  it('ne chevauche pas le nom : il est réduit quand le nom monte presque au coin', () => {
    const s = new Scene(200, 100)
    // Un nom large : il laisse 3,6 mm à droite du texte, le mur de 3,2 ne tient pas avec 0,8 de marge.
    const t = murDansUneCase(s, 0, 0, 40, 20, 1, [32.4, 18])
    expect(t).toBeLessThan(TAILLE_DU_MUR)
    expect(t).toBeGreaterThanOrEqual(TAILLE_MIN)
    const b = boite(mursDeLaScene(s)[0]!.d)
    // Entre le bord droit du texte (36,2) et celui de la case.
    expect(b.x0).toBeGreaterThanOrEqual(20 + 16.2 - 1e-6)
    expect(b.x1).toBeLessThanOrEqual(40)
  })

  it('le pose contre la case, au-dessus, quand même 2 mm ne tiennent pas', () => {
    const s = new Scene(200, 100)
    const t = murDansUneCase(s, 10, 20, 30, 12, 1, [30, 12])
    expect(t).toBe(TAILLE_MIN)
    const b = boite(mursDeLaScene(s)[0]!.d)
    expect(b.y1).toBeLessThanOrEqual(20)
    expect(b.x1).toBeLessThanOrEqual(40 + 1e-6)
  })

  it('grandit avec le facteur k de la page', () => {
    const petit = murDansUneCase(new Scene(1, 1), 0, 0, 100, 50, 1)
    const grand = murDansUneCase(new Scene(1, 1), 0, 0, 100, 50, 1.5)
    expect(grand).toBeGreaterThan(petit)
  })
})

describe('le mur dans un secteur', () => {
  const dedans = (x: number, y: number, cx: number, cy: number, r: number): number => Math.hypot(x - cx, y - cy) - r

  it('pose un chemin en BRIQUE, droit, centré à r2 − taille sur l’angle médian', () => {
    const s = new Scene(400, 400)
    const t = murDansUnSecteur(s, 200, 200, 100, 90, 1)
    expect(t).toBeCloseTo(TAILLE_DU_MUR, 9)
    const [m] = mursDeLaScene(s)
    expect(m!.fond).toBe('none')
    expect(m!.trait).toBe(BRIQUE)
    const b = boite(m!.d)
    // Au-dessus du centre (angle 90°), à 100 − 3,2 de lui, le tracé dans son carré.
    expect(b.x1 - b.x0).toBeLessThanOrEqual(t + 1e-6)
    expect((b.x0 + b.x1) / 2).toBeCloseTo(200, 0)
    expect(dedans((b.x0 + b.x1) / 2, b.y1, 200, 200, 100)).toBeLessThan(0)
  })

  it('reste droit : aucun transform, même à un angle oblique', () => {
    const s = new Scene(400, 400)
    murDansUnSecteur(s, 200, 200, 100, 33, 1)
    const [m] = mursDeLaScene(s)
    expect(Object.keys(m!)).not.toContain('transform')
    expect(m!.d).not.toMatch(/[ACQSTR]/)
  })

  it('se réduit pour ne pas toucher le texte, puis passe au-delà de l’arc', () => {
    const r2 = 100
    const reduit = new Scene(400, 400)
    // Le texte monte à r2 − 6 : un mur de 3,2 (centré à r2 − 3,2) le toucherait, un mur plus petit non.
    const t = murDansUnSecteur(reduit, 200, 200, r2, 90, 1, { rTexte: r2 - 4.6 })
    expect(t).toBeLessThan(TAILLE_DU_MUR)
    expect(t).toBeGreaterThanOrEqual(TAILLE_MIN)
    const dehors = new Scene(400, 400)
    const t2 = murDansUnSecteur(dehors, 200, 200, r2, 90, 1, { rTexte: r2 - 0.5 })
    expect(t2).toBe(TAILLE_MIN)
    const b = boite(mursDeLaScene(dehors)[0]!.d)
    // Entièrement au-delà de l'arc : plus loin du centre que r2 (angle 90° : plus haut, y plus petit).
    expect(200 - b.y1).toBeGreaterThanOrEqual(r2 - 1e-6)
  })

  it('passe aussi au-delà quand le secteur est trop fin pour le contenir', () => {
    const s = new Scene(400, 400)
    const t = murDansUnSecteur(s, 200, 200, 100, 90, 1, { ouverture: 0.01 })
    expect(t).toBe(TAILLE_MIN)
    expect(200 - boite(mursDeLaScene(s)[0]!.d).y1).toBeGreaterThanOrEqual(100 - 1e-6)
  })

  it('se pose à l’angle libre quand il passe à l’extérieur', () => {
    const s = new Scene(400, 400)
    murDansUnSecteur(s, 200, 200, 100, 90, 1, { ouverture: 0.01, aExterieur: 0 })
    const b = boite(mursDeLaScene(s)[0]!.d)
    // À 0° : à droite du centre.
    expect((b.x0 + b.x1) / 2).toBeGreaterThan(300)
    expect((b.y0 + b.y1) / 2).toBeCloseTo(200, 0)
  })
})
