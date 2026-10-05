import { describe, expect, it } from 'vitest'
import type { AreteGraphe, Graphe, NoeudGraphe } from '@/lib/api'
import { seulementLaLigne } from './ligneDirecte'
import { disposerEnPedigree } from './pedigree'

/**
 * La ligne directe seule, en arbre binaire : chacun au milieu de ses deux parents, le
 * père à gauche, et le même traitement pour toutes les branches — une case vide reste
 * vide, elle ne déplace personne.
 */
function personne(id: string, sex: 'masculin' | 'feminin', branch = 'tr'): NoeudGraphe {
  return {
    id,
    type: 'personne',
    personId: id.length,
    x: 0,
    y: 0,
    w: 132,
    h: 48,
    displayName: id,
    etiquetteNom: `${id} Nom${id}`,
    sex,
    generation: 0,
    branch,
    vivant: false,
    visibilite: 'complete',
  } as NoeudGraphe
}

function graphe(): Graphe {
  const noeuds: NoeudGraphe[] = [
    personne('moi', 'masculin'),
    personne('pere', 'masculin'),
    personne('mere', 'feminin'),
    personne('gpp', 'masculin', 'pp'),
    personne('gmp', 'feminin', 'pm'),
    // Côté mère : seul le grand-père est connu.
    personne('gpm', 'masculin', 'mp'),
    personne('oncle', 'masculin', 'pp'),
    personne('conjointe', 'feminin'),
    personne('fils', 'masculin'),
    personne('fille', 'feminin'),
    personne('petit', 'masculin'),
  ]
  const aretes: AreteGraphe[] = []
  const famille = (id: string, epoux: string[], enfants: string[]): void => {
    noeuds.push({ id, type: 'famille', familyId: null, x: 0, y: 0, w: 1, h: 1, divorced: false } as NoeudGraphe)
    for (const e of epoux) aretes.push({ de: e, vers: id, genre: 'union' })
    for (const e of enfants) aretes.push({ de: id, vers: e, genre: 'filiation' })
  }
  famille('f-gp', ['gpp', 'gmp'], ['pere', 'oncle'])
  famille('f-gm', ['gpm'], ['mere'])
  famille('f-p', ['pere', 'mere'], ['moi'])
  famille('f-moi', ['moi', 'conjointe'], ['fils', 'fille'])
  famille('f-fils', ['fils'], ['petit'])
  return {
    empreinte: '0'.repeat(64),
    ego: 'moi',
    boite: { x: 0, y: 0, w: 1, h: 1 },
    calque: { actif: false, demandes: [], proposes: 0, places: 0, nonPlaces: [] },
    noeuds,
    aretes,
  }
}

const disposer = (racine = 'moi') => disposerEnPedigree(seulementLaLigne(graphe(), racine), racine, 'moi')

describe('la ligne directe en arbre binaire', () => {
  it('met chaque enfant au milieu de ses deux parents, le père à gauche', () => {
    const d = disposer()
    const f = (id: string) => d.parId.get(id)!
    expect(f('moi').cx).toBeCloseTo((f('pere').cx + f('mere').cx) / 2)
    expect(f('pere').cx).toBeCloseTo((f('gpp').cx + f('gmp').cx) / 2)
    expect(f('pere').cx).toBeLessThan(f('mere').cx)
    expect(f('gpp').cx).toBeLessThan(f('gmp').cx)
  })

  it('traite les deux côtés de la même façon : un ancêtre inconnu garde sa case vide', () => {
    const d = disposer()
    const f = (id: string) => d.parId.get(id)!
    // Les deux côtés sont symétriques autour de vous, même si la grand-mère maternelle manque.
    expect(f('moi').cx - f('pere').cx).toBeCloseTo(f('mere').cx - f('moi').cx)
    // Le grand-père maternel est dans SA case (Sosa 6), pas recentré au-dessus de la mère.
    expect(f('gpm').cx).toBeLessThan(f('mere').cx)
    expect(f('mere').cx - f('gpm').cx).toBeCloseTo(f('pere').cx - f('gpp').cx)
  })

  it('écarte deux couples voisins davantage que les deux époux d’un couple', () => {
    const d = disposer()
    const f = (id: string) => d.parId.get(id)!
    const dansLeCouple = f('gmp').cx - f('gpp').cx
    const entreCouples = f('gpm').cx - f('gmp').cx
    expect(entreCouples).toBeGreaterThan(dansLeCouple)
  })

  it('ne garde que la ligne : ni oncle, ni conjointe', () => {
    const d = disposer()
    expect(d.parId.has('oncle')).toBe(false)
    expect(d.parId.has('conjointe')).toBe(false)
  })

  it('pose les descendants sous la racine, centrés sur elle', () => {
    const d = disposer()
    const f = (id: string) => d.parId.get(id)!
    expect(f('fils').rangee).toBe(1)
    expect(f('petit').rangee).toBe(2)
    expect((f('fils').cx + f('fille').cx) / 2).toBeCloseTo(f('moi').cx)
    expect(f('petit').cx).toBeCloseTo(f('fils').cx)
    expect(f('fils').cx).toBeLessThan(f('fille').cx)
  })

  it('compte les rangées depuis le lecteur, et range chaque génération sur sa ligne', () => {
    const d = disposer()
    expect(d.parId.get('moi')!.rangee).toBe(0)
    expect(d.parId.get('gpp')!.rangee).toBe(-2)
    expect(new Set(d.fiches.filter((f) => f.rangee === -2).map((f) => f.y)).size).toBe(1)
    expect(d.generations.map((g) => g.libelle)).toContain('Grands-parents')
  })

  it('suit la ligne d’un ancêtre : ses descendants, le lecteur compris, sous lui', () => {
    const d = disposer('pere')
    expect(d.ego).toBe('moi')
    expect(d.parId.get('pere')!.rangee).toBe(-1)
    expect(d.parId.has('oncle')).toBe(false)
    expect(d.parId.get('moi')!.cx).toBeCloseTo(d.parId.get('pere')!.cx)
  })

  it('dessine une union droite et une descente qui tombe sur l’enfant', () => {
    const d = disposer()
    const union = d.traces.familles.find((t) => t.id === 'f-p')!
    expect(union.personnes).toEqual(['pere', 'mere', 'moi'])
    const moi = d.parId.get('moi')!
    expect(union.d).toContain(`M${moi.cx.toFixed(1)} `)
    expect(d.traces.directs).not.toBe('')
  })

  it('donne les quarts du lecteur au bandeau', () => {
    const d = disposer()
    expect(d.quarts.map((q) => q.branche)).toEqual(['pp', 'pm', 'mp'])
    expect(d.quarts[0]!.nom).toBe('Nomgpp')
  })
})
