import { describe, expect, it } from 'vitest'
import type { AreteGraphe, Branche, Graphe, NoeudFamille, NoeudPersonne, ResumeFinDeRecherche, Sexe } from '@/lib/api'
import { nomDeFamilleDe, anneeDe } from '@/histoire/lignees'
import { disposerEnRevue } from '@/arbre-review/disposition'
import { avecCartesDeFinDeRecherche, estCarteDeFin, LARGEUR_MIN_DE_LA_CARTE, motifDeLaCarte } from './cartesDeFin'
import { disposerEnGroupes } from './groupes'

/*
 * Les cartes « Recherche close » de `/arbre` (ADR 0023, révision du 18/11/2026) : à la place du
 * parent introuvable, un nœud de plus dans le graphe, que le moteur pose comme le parent.
 */

interface Fins {
  finDeRecherchePere?: ResumeFinDeRecherche
  finDeRechercheMere?: ResumeFinDeRecherche
}

function nouvelArbre() {
  let n = 1
  const noeuds: Graphe['noeuds'] = []
  const aretes: AreteGraphe[] = []
  const personne = (cle: string, sex: Sexe, branch: Branche, fins: Fins = {}, extra: Partial<NoeudPersonne> = {}): NoeudPersonne => {
    const k = n++
    const p: NoeudPersonne = {
      id: `p${k}`,
      type: 'personne',
      personId: k,
      x: 0,
      y: 0,
      w: 140,
      h: 56,
      displayName: cle,
      etiquetteNom: `${cle} Nom${k}`,
      etiquetteDates: '1900 – 1970',
      sex,
      generation: 0,
      branch,
      parente: 'famille',
      vivant: false,
      visibilite: 'complete',
      ...fins,
      ...extra,
    }
    noeuds.push(p)
    return p
  }
  const famille = (epoux: NoeudPersonne[], enfants: NoeudPersonne[]): NoeudFamille => {
    const f: NoeudFamille = { id: `f${n++}`, type: 'famille', familyId: n, x: 0, y: 0, w: 10, h: 10, divorced: false }
    noeuds.push(f)
    for (const e of epoux) aretes.push({ de: e.id, vers: f.id, genre: 'union' })
    for (const e of enfants) aretes.push({ de: f.id, vers: e.id, genre: 'filiation' })
    return f
  }
  const finir = (ego: NoeudPersonne): Graphe => ({
    empreinte: '0'.repeat(64),
    ego: ego.id,
    boite: { x: 0, y: 0, w: 1000, h: 1000 },
    calque: { actif: false, demandes: [], proposes: 0, places: 0, nonPlaces: [] },
    noeuds,
    aretes,
  })
  return { personne, famille, finir }
}

const PERE_INCONNU: ResumeFinDeRecherche = { reason: 'pere_inconnu', beforeYear: null }
const REGISTRES: ResumeFinDeRecherche = { reason: 'registres_inexistants', beforeYear: 1668 }

/**
 * L'ego, ses parents ; le père de l'ego n'a pas de père connu (registres inexistants), la mère
 * de l'ego a son père mais pas sa mère (lacune) ; le conjoint de l'ego a un père « inconnu » ;
 * une grand-mère a ses deux côtés clos ; un grand-père a un père lié ET une fin de recherche.
 */
function arbreDEssai() {
  const a = nouvelArbre()
  const ego = a.personne('ego', 'masculin', 'tr')
  const pere = a.personne('pere', 'masculin', 'tr', { finDeRecherchePere: REGISTRES })
  const mere = a.personne('mere', 'feminin', 'tr', { finDeRechercheMere: { reason: 'lacune', beforeYear: null } })
  a.famille([pere, mere], [ego])
  const grandPereMaternel = a.personne('gpm', 'masculin', 'mp', { finDeRecherchePere: { reason: 'registres_detruits', beforeYear: null } })
  a.famille([grandPereMaternel], [mere])
  const arriere = a.personne('arriere', 'masculin', 'mp')
  a.famille([arriere], [grandPereMaternel])
  const grandMere = a.personne('gm-pm', 'feminin', 'pm', { finDeRecherchePere: REGISTRES, finDeRechercheMere: REGISTRES })
  const grandPere = a.personne('gp-pm', 'masculin', 'pm')
  // La grand-mère est l'épouse d'un collatéral : on l'accroche par une union, sans parents.
  a.famille([grandPere, grandMere], [])
  const conjoint = a.personne('conjoint', 'feminin', 'tr')
  a.famille([ego, conjoint], [])
  const mereDuConjoint = a.personne('belle-mere', 'feminin', 'cmp')
  const parentsDuConjoint = a.famille([mereDuConjoint], [conjoint])
  // Le conjoint : père inconnu, la famille de ses parents ne porte que sa mère.
  conjoint.finDeRecherchePere = PERE_INCONNU
  mereDuConjoint.finDeRechercheMere = REGISTRES
  return { graphe: a.finir(ego), ego, pere, mere, grandPereMaternel, grandMere, conjoint, mereDuConjoint, parentsDuConjoint }
}

describe('les cartes « Recherche close » du graphe', () => {
  const e = arbreDEssai()
  const g = avecCartesDeFinDeRecherche(e.graphe)
  const cartes = g.noeuds.filter(estCarteDeFin)
  const carteDe = (enfant: NoeudPersonne, cote: 'pere' | 'mere') => cartes.find((c) => c.carteDeFin.enfant === enfant.id && c.carteDeFin.cote === cote)
  const unions = (id: string) => g.aretes.filter((a) => a.genre === 'union' && a.de === id).map((a) => a.vers)
  const filiations = (id: string) => g.aretes.filter((a) => a.genre === 'filiation' && a.vers === id).map((a) => a.de)

  it('une carte par côté clos sans parent lié, et aucune autre', () => {
    const attendues = [
      [e.pere, 'pere'],
      [e.mere, 'mere'],
      [e.grandMere, 'pere'],
      [e.grandMere, 'mere'],
      [e.conjoint, 'pere'],
      [e.mereDuConjoint, 'mere'],
    ] as const
    expect(cartes).toHaveLength(attendues.length)
    for (const [enfant, cote] of attendues) expect(carteDe(enfant, cote), `${enfant.displayName} ${cote}`).toBeDefined()
    // Le grand-père maternel a un père lié : sa fin de recherche reste enregistrée, sans carte.
    expect(cartes.some((c) => c.carteDeFin.enfant === e.grandPereMaternel.id)).toBe(false)
  })

  it('ne modifie pas le graphe reçu ; sans côté clos, le rend tel quel', () => {
    expect(e.graphe.noeuds.some((n) => n.type === 'personne' && n.carteDeFin)).toBe(false)
    const sans = nouvelArbre()
    const ego = sans.personne('ego', 'masculin', 'tr')
    const graphe = sans.finir(ego)
    expect(avecCartesDeFinDeRecherche(graphe)).toBe(graphe)
  })

  it('rejoint la famille des parents qui existe : la mère connue reçoit pour époux la carte du père', () => {
    const c = carteDe(e.conjoint, 'pere')!
    expect(unions(c.id)).toEqual([e.parentsDuConjoint.id])
    // L'enfant reste sous le seul couple : aucune famille de plus pour lui.
    expect(filiations(e.conjoint.id)).toEqual([e.parentsDuConjoint.id])
  })

  it('sans famille de parents, une famille virtuelle, partagée par les deux cartes d’un même enfant', () => {
    const p = carteDe(e.grandMere, 'pere')!
    const m = carteDe(e.grandMere, 'mere')!
    const [famille] = unions(p.id)
    expect(unions(m.id)).toEqual([famille])
    expect(filiations(e.grandMere.id)).toEqual([famille])
    const noeud = g.noeuds.find((n) => n.id === famille)
    expect(noeud).toMatchObject({ type: 'famille', familyId: null, divorced: false })
  })

  it('prend la branche qu’aurait le parent : le père du père ouvre `pp`, la mère de la mère `mm`, ceux du conjoint `cpp`, `cmm`', () => {
    expect(carteDe(e.pere, 'pere')!.branch).toBe('pp')
    expect(carteDe(e.mere, 'mere')!.branch).toBe('mm')
    expect(carteDe(e.conjoint, 'pere')!.branch).toBe('cpp')
    expect(carteDe(e.mereDuConjoint, 'mere')!.branch).toBe('cmm')
    // Au-delà, la branche de l'enfant.
    expect(carteDe(e.grandMere, 'pere')!.branch).toBe('pm')
  })

  it('n’est pas une personne : pas d’identifiant de fiche, ni dates ni nom de famille, le sexe de sa place', () => {
    for (const c of cartes) {
      expect(c.personId).toBeNull()
      expect(c.etiquetteDates).toBeUndefined()
      expect(anneeDe(c)).toBeNull()
      expect(nomDeFamilleDe(c)).toBe('')
      expect(c.sex).toBe(c.carteDeFin.cote === 'pere' ? 'masculin' : 'feminin')
      expect(c.w).toBeGreaterThanOrEqual(LARGEUR_MIN_DE_LA_CARTE)
      expect(c.displayName).toContain('Recherche close')
    }
    expect(motifDeLaCarte(carteDe(e.pere, 'pere')!.carteDeFin)).toBe('Registres inexistants avant 1668')
    expect(motifDeLaCarte(carteDe(e.conjoint, 'pere')!.carteDeFin)).toBe('Père inconnu')
  })

  it('jamais sur un nœud restreint, même si la charge portait une fin de recherche', () => {
    const a = nouvelArbre()
    const ego = a.personne('ego', 'masculin', 'tr', { finDeRecherchePere: PERE_INCONNU }, { visibilite: 'restreinte' })
    expect(avecCartesDeFinDeRecherche(a.finir(ego)).noeuds.some((n) => n.type === 'personne' && n.carteDeFin)).toBe(false)
  })

  it.each([
    ['les rectangles', disposerEnGroupes],
    ['le moteur en couches de `/arbre`', disposerEnRevue],
  ] as const)('%s la posent au-dessus de son enfant, sans la compter dans les effectifs', (_, disposer) => {
    const sans = disposer(e.graphe, {})
    const avec = disposer(g, {})
    expect(avec.fiches).toHaveLength(sans.fiches.length + cartes.length)
    for (const c of cartes) {
      const fiche = avec.parId.get(c.id)!
      const enfant = avec.parId.get(c.carteDeFin.enfant)!
      expect(fiche.rangee, c.id).toBe(enfant.rangee - 1)
      expect(fiche.y + fiche.h).toBeLessThanOrEqual(enfant.y)
    }
    const effectif = (d: typeof avec) => d.groupes.reduce((t, r) => t + r.effectif, 0)
    expect(effectif(avec)).toBe(effectif(sans))
    const parGeneration = (d: typeof avec) => d.generations.reduce((t, x) => t + x.effectif, 0)
    expect(parGeneration(avec)).toBe(parGeneration(sans))
  })
})
