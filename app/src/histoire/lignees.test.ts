import { describe, expect, it } from 'vitest'
import type { AreteGraphe, Graphe, NoeudFamille, NoeudPersonne, Sexe } from '@/lib/api'
import { anneeDe, disposerLignees, nomDeFamilleDe, prenomDe, type DispositionHistoire } from './lignees'

/**
 * Ce que ces tests protègent : les règles de placement de la disposition
 * `lignees` — aucune fiche perdue ni posée deux fois, aucun chevauchement par
 * rangée, la lignée directe ordonnée selon Sosa, et l'union de chaque couple
 * d'ancêtres qui ne traverse aucune fiche.
 */

let prochain = 1

function construire(): { graphe: Graphe; id: Record<string, string> } {
  prochain = 1
  const noeuds: Graphe['noeuds'] = []
  const aretes: AreteGraphe[] = []
  const id: Record<string, string> = {}

  const personne = (cle: string, nom: string, sex: Sexe, annee: number | null, w = 140): string => {
    const n = prochain++
    const p: NoeudPersonne = {
      id: `p${n}`,
      type: 'personne',
      personId: n,
      x: 0,
      y: 0,
      w,
      h: 56,
      displayName: nom,
      etiquetteNom: nom,
      sex,
      generation: 0,
      branch: 'tr',
      vivant: annee !== null && annee > 1950,
      visibilite: 'complete',
      ...(annee !== null ? { etiquetteDates: `${annee} – ` } : {}),
    }
    noeuds.push(p)
    id[cle] = p.id
    return p.id
  }
  const famille = (epoux: string[], enfants: string[], divorced = false): string => {
    const f: NoeudFamille = { id: `f${prochain++}`, type: 'famille', familyId: prochain, x: 0, y: 0, w: 10, h: 10, divorced }
    noeuds.push(f)
    for (const e of epoux) aretes.push({ de: e, vers: f.id, genre: 'union' })
    for (const e of enfants) aretes.push({ de: f.id, vers: e, genre: 'filiation' })
    return f.id
  }

  const ego = personne('ego', 'Nathan Provost', 'masculin', 1998)
  const pere = personne('pere', 'Laurent Provost', 'masculin', 1969)
  const mere = personne('mere', 'Emmanuelle Voulgre', 'feminin', 1971)
  const soeur = personne('soeur', 'Lucie Provost', 'feminin', 2001)
  famille([pere, mere], [soeur, ego])

  const gpp = personne('gpp', 'Daniel Provost', 'masculin', 1946)
  const gmp = personne('gmp', 'Elisabeth Destombes', 'feminin', 1949)
  const tante = personne('tante', 'Céline Provost', 'feminin', 1972)
  const oncle = personne('oncle', 'Stéphane Provost', 'masculin', 1977)
  famille([gpp, gmp], [pere, tante, oncle])
  // L'oncle a une famille : un conjoint et deux cousins.
  const tanteAlliee = personne('tanteAlliee', 'Anne Martin', 'feminin', 1978)
  const cousin1 = personne('cousin1', 'Hugo Provost', 'masculin', 2004)
  const cousin2 = personne('cousin2', 'Léa Provost', 'feminin', 2007)
  famille([oncle, tanteAlliee], [cousin1, cousin2])

  const gpm = personne('gpm', 'Gabriel Voulgre', 'masculin', 1930)
  const gmm = personne('gmm', 'Marie Figarol', 'feminin', 1937)
  const tanteM = personne('tanteM', 'Nicole Voulgre', 'feminin', 1958)
  famille([gpm, gmm], [tanteM, mere])

  // Arrière-grands-parents du côté du père du père.
  const agp = personne('agp', 'Alain Provost', 'masculin', 1913)
  const agm = personne('agm', 'Aimée Samara', 'feminin', 1917)
  const grandOncle = personne('grandOncle', 'Solange Provost', 'feminin', 1943)
  famille([agp, agm], [grandOncle, gpp])

  // Remariage du grand-père maternel : une demi-tante.
  const secondeEpouse = personne('secondeEpouse', 'Rose Bernard', 'feminin', 1940)
  const demiTante = personne('demiTante', 'Odile Voulgre', 'feminin', 1965)
  famille([gpm, secondeEpouse], [demiTante], true)

  // Le conjoint de l'ego, ses enfants, et les parents du conjoint.
  const conjoint = personne('conjoint', 'Camille Durand', 'feminin', 1999)
  const enfant = personne('enfant', 'Arthur Provost', 'masculin', 2024)
  famille([ego, conjoint], [enfant])
  const beauPere = personne('beauPere', 'Paul Durand', 'masculin', 1970)
  const belleMere = personne('belleMere', 'Anne Lefebvre', 'feminin', 1972)
  famille([beauPere, belleMere], [conjoint])

  // Un îlot, relié à personne.
  personne('ilot', 'Personne Isolée', 'inconnu', null)

  const graphe: Graphe = {
    empreinte: '0'.repeat(64),
    ego,
    boite: { x: 0, y: 0, w: 1000, h: 1000 },
    calque: { actif: false, demandes: [], proposes: 0, places: 0, nonPlaces: [] },
    noeuds,
    aretes,
  }
  return { graphe, id }
}

function chevauchements(d: DispositionHistoire): string[] {
  const parRangee = new Map<number, typeof d.fiches>()
  for (const f of d.fiches) parRangee.set(f.y, [...(parRangee.get(f.y) ?? []), f])
  const fautes: string[] = []
  for (const rangee of parRangee.values()) {
    const tri = [...rangee].sort((a, b) => a.x - b.x)
    for (let i = 1; i < tri.length; i++) {
      if (tri[i]!.x < tri[i - 1]!.x + tri[i - 1]!.w) fautes.push(`${tri[i - 1]!.id} / ${tri[i]!.id}`)
    }
  }
  return fautes
}

describe('disposition lignees', () => {
  const { graphe, id } = construire()
  const d = disposerLignees(graphe)
  const f = (cle: string) => d.parId.get(id[cle]!)!

  it('pose chaque personne une fois et une seule', () => {
    const personnes = graphe.noeuds.filter((n) => n.type === 'personne')
    expect(d.fiches).toHaveLength(personnes.length)
    expect(new Set(d.fiches.map((x) => x.id)).size).toBe(personnes.length)
  })

  it('ne fait se chevaucher aucune fiche sur une rangée', () => {
    expect(chevauchements(d)).toEqual([])
  })

  it('garde la largeur et la hauteur du moteur', () => {
    for (const fiche of d.fiches) {
      expect(fiche.w).toBe(fiche.noeud.w)
      expect(fiche.h).toBe(fiche.noeud.h)
    }
  })

  it('met une génération par rangée', () => {
    expect(f('pere').y).toBe(f('mere').y)
    expect(f('gpp').y).toBe(f('gmm').y)
    expect(f('pere').y).toBeLessThan(f('ego').y)
    expect(f('gpp').y).toBeLessThan(f('pere').y)
    expect(f('agp').y).toBeLessThan(f('gpp').y)
    expect(f('enfant').y).toBeGreaterThan(f('ego').y)
    expect(f('conjoint').y).toBe(f('ego').y)
  })

  it('numérote la lignée directe selon Sosa', () => {
    expect(f('ego').sosa).toBe(1)
    expect(f('pere').sosa).toBe(2)
    expect(f('mere').sosa).toBe(3)
    expect(f('gpp').sosa).toBe(4)
    expect(f('gmm').sosa).toBe(7)
    expect(f('agm').sosa).toBe(9)
    expect(f('tante').directe).toBe(false)
  })

  it('range le côté père à gauche et le côté mère à droite, à tous les niveaux', () => {
    expect(f('pere').cx).toBeLessThan(f('mere').cx)
    expect(f('gpp').cx).toBeLessThan(f('gmp').cx)
    expect(f('gmp').cx).toBeLessThan(f('gpm').cx)
    expect(f('gpm').cx).toBeLessThan(f('gmm').cx)
    expect(f('agp').cx).toBeLessThan(f('agm').cx)
  })

  it('range les collatéraux à l’extérieur de la lignée directe', () => {
    // Le père est père dans son couple : sa fratrie est à sa gauche.
    expect(f('tante').cx).toBeLessThan(f('pere').cx)
    expect(f('oncle').cx).toBeLessThan(f('pere').cx)
    expect(f('cousin1').cx).toBeLessThan(f('pere').cx)
    // La mère est mère dans son couple : sa fratrie est à sa droite.
    expect(f('tanteM').cx).toBeGreaterThan(f('mere').cx)
  })

  it('ne fait traverser aucune fiche par l’union d’un couple d’ancêtres', () => {
    for (const [a, b] of [['pere', 'mere'], ['gpp', 'gmp'], ['gpm', 'gmm'], ['agp', 'agm']] as const) {
      const g = f(a)
      const m = f(b)
      const entre = d.fiches.filter((x) => x.y === g.y && x !== g && x !== m && x.x < m.x && x.x + x.w > g.x + g.w)
      expect(entre.map((x) => x.noeud.displayName)).toEqual([])
    }
  })

  it('n’oublie ni les remariages, ni l’ascendance du conjoint, ni les îlots', () => {
    for (const cle of ['secondeEpouse', 'demiTante', 'beauPere', 'belleMere', 'ilot']) {
      expect(d.parId.has(id[cle]!)).toBe(true)
    }
    expect(f('demiTante').y).toBe(f('mere').y)
    expect(f('beauPere').y).toBe(f('pere').y)
  })

  it('dessine la ligne directe et le divorce à part', () => {
    expect(d.traces.directs.length).toBeGreaterThan(0)
    expect(d.traces.divorces.length).toBeGreaterThan(0)
    expect(d.traces.ordinaires.length).toBeGreaterThan(0)
  })

  it('libelle chaque génération relativement à l’ego', () => {
    const libelles = d.generations.map((g) => g.libelle)
    expect(libelles).toEqual(['Arrière-grands-parents', 'Grands-parents', 'Parents', 'Votre génération', 'Enfants'])
    expect(d.generations.find((g) => g.rangee === -1)!.annees).toBe('vers 1970')
  })

  it('donne un nom à chaque foyer à partir du mari', () => {
    const noms = d.foyers.map((x) => x.nom)
    expect(noms).toContain('Provost')
    expect(noms).toContain('Voulgre')
    expect(d.foyers.find((x) => x.nom === 'Voulgre' && x.ancetres)).toBeDefined()
  })
})

describe('lecture des étiquettes', () => {
  const noeud = (etiquetteNom: string, etiquetteDates?: string): NoeudPersonne => ({
    id: 'p1',
    type: 'personne',
    personId: 1,
    x: 0,
    y: 0,
    w: 100,
    h: 56,
    displayName: etiquetteNom,
    etiquetteNom,
    sex: 'inconnu',
    generation: 0,
    branch: 'tr',
    vivant: false,
    visibilite: 'complete',
    ...(etiquetteDates !== undefined ? { etiquetteDates } : {}),
  })

  it('le prénom est le premier mot, le nom de famille garde ses espaces', () => {
    const n = noeud('Jade de la Tour')
    expect(prenomDe(n)).toBe('Jade')
    expect(nomDeFamilleDe(n)).toBe('de la Tour')
  })

  it('ne lit aucune année sur un nœud restreint ou vivant', () => {
    expect(anneeDe(noeud('Jade Tour'))).toBeNull()
    expect(anneeDe(noeud('Jade Tour', 'vivant·e'))).toBeNull()
    expect(anneeDe(noeud('Jade Tour', '1901-1909 – 1987'))).toBe(1901)
  })
})
