import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { AreteGraphe, Graphe, NoeudPersonne, ResultatRevalide } from '@/lib/api'

/**
 * Ce que ces tests protègent : le magasin du graphe de l'arbre.
 *
 *  - un `304` reprend le graphe ET sa disposition, sans rien recalculer ;
 *  - un changement de session (tous les `ETag` oubliés) vide le magasin, et une
 *    réponse partie avant ne s'écrit pas : un lecteur ne voit jamais l'arbre du
 *    précédent ;
 *  - sans graphe en mémoire, on ne revalide pas (un `304` ne rendrait rien).
 */
const api = vi.hoisted(() => ({ chargerGraphe: vi.fn() }))
vi.mock('@/lib/api', async (original) => ({
  ...(await original<typeof import('@/lib/api')>()),
  chargerGraphe: api.chargerGraphe,
}))

import { CLE_ETAG_GRAPHE, etagConnu, invaliderGraphe, oublierEtag } from '@/lib/api'
import { cleDesReglages, disposer, grapheEnMemoire, revaliderGraphe, viderMagasinGraphe, type ReglagesDeDisposition } from './magasinGraphe'

function personne(id: string, nom: string, sex: NoeudPersonne['sex']): NoeudPersonne {
  return {
    id,
    type: 'personne',
    personId: Number(id.slice(1)),
    x: 0,
    y: 0,
    w: 140,
    h: 56,
    displayName: nom,
    etiquetteNom: nom,
    sex,
    generation: 0,
    branch: 'tr',
    vivant: false,
    visibilite: 'complete',
  }
}

function graphe(empreinte = 'a'): Graphe {
  const aretes: AreteGraphe[] = [
    { de: 'p2', vers: 'f1', genre: 'union' },
    { de: 'p3', vers: 'f1', genre: 'union' },
    { de: 'f1', vers: 'p1', genre: 'filiation' },
  ]
  return {
    empreinte: empreinte.repeat(64),
    ego: 'p1',
    boite: { x: 0, y: 0, w: 1, h: 1 },
    calque: { actif: false, demandes: [], proposes: 0, places: 0, nonPlaces: [] },
    noeuds: [
      personne('p1', 'Opale Bourrache', 'feminin'),
      personne('p2', 'Marne Bourrache', 'masculin'),
      personne('p3', 'Ambre Sureau', 'feminin'),
      { id: 'f1', type: 'famille', familyId: 1, x: 0, y: 0, w: 10, h: 10, divorced: false },
    ],
    aretes,
  }
}

const REGLAGES: ReglagesDeDisposition = {
  avecConjoint: true,
  avecAlliances: false,
  avecSansLien: true,
  alliancesDansLArbre: false,
  fratriesParNaissance: false,
  format: 'compact',
  ligneSeule: null,
}

const nouveau = (g: Graphe): ResultatRevalide<Graphe> => ({ statut: 'nouveau', donnees: g, etag: '"e"' })

beforeEach(() => {
  api.chargerGraphe.mockReset()
  viderMagasinGraphe()
})

describe('le magasin du graphe', () => {
  it('garde le graphe rendu par un 200', async () => {
    const g = graphe()
    api.chargerGraphe.mockResolvedValue(nouveau(g))

    await expect(revaliderGraphe()).resolves.toEqual({ statut: 'nouveau', graphe: g })
    expect(grapheEnMemoire()).toBe(g)
  })

  it('reprend graphe et disposition sur un 304, sans les recalculer', async () => {
    const g = graphe()
    api.chargerGraphe.mockResolvedValueOnce(nouveau(g))
    await revaliderGraphe()
    const avant = disposer(g, REGLAGES)

    api.chargerGraphe.mockResolvedValueOnce({ statut: 'inchange' })
    const r = await revaliderGraphe()

    expect(r).toEqual({ statut: 'inchange', graphe: g })
    // La même instance : rien n'a été recalculé.
    expect(disposer(g, REGLAGES)).toBe(avant)
  })

  it('refait la disposition quand un réglage change, et seulement alors', async () => {
    const g = graphe()
    api.chargerGraphe.mockResolvedValueOnce(nouveau(g))
    await revaliderGraphe()
    const compacte = disposer(g, REGLAGES)
    const portrait = disposer(g, { ...REGLAGES, format: 'portrait' })

    expect(portrait).not.toBe(compacte)
    expect(disposer(g, { ...REGLAGES, format: 'portrait' })).toBe(portrait)
    expect(cleDesReglages(REGLAGES)).not.toBe(cleDesReglages({ ...REGLAGES, fratriesParNaissance: true }))
    // La ligne directe seule : une autre forme, l'arbre binaire.
    const ligne = disposer(g, { ...REGLAGES, ligneSeule: 'p1' })
    expect(ligne).not.toBe(compacte)
    expect(ligne.fiches.map((f) => f.id).sort()).toEqual(['p1', 'p2', 'p3'])
  })

  it('oublie la disposition gardée quand un graphe neuf arrive', async () => {
    const g1 = graphe('a')
    api.chargerGraphe.mockResolvedValueOnce(nouveau(g1))
    await revaliderGraphe()
    const d1 = disposer(g1, REGLAGES)

    const g2 = graphe('b')
    api.chargerGraphe.mockResolvedValueOnce(nouveau(g2))
    await revaliderGraphe()

    expect(grapheEnMemoire()).toBe(g2)
    expect(disposer(g2, REGLAGES)).not.toBe(d1)
  })

  it('se vide à tout changement de session (tous les ETag oubliés)', async () => {
    api.chargerGraphe.mockResolvedValueOnce(nouveau(graphe()))
    await revaliderGraphe()

    oublierEtag()

    expect(grapheEnMemoire()).toBeNull()
  })

  it('ne se vide pas après une écriture : l’arbre reste affiché jusqu’au 200', async () => {
    const g = graphe()
    api.chargerGraphe.mockResolvedValueOnce(nouveau(g))
    await revaliderGraphe()

    invaliderGraphe()

    expect(grapheEnMemoire()).toBe(g)
  })

  it('jette une réponse partie avant un changement de session', async () => {
    let repondre: (r: ResultatRevalide<Graphe>) => void = () => undefined
    api.chargerGraphe.mockReturnValueOnce(new Promise((fin) => (repondre = fin)))
    const enCours = revaliderGraphe()

    // Déconnexion, ou entrée dans une incarnation, pendant l'appel.
    oublierEtag()
    repondre(nouveau(graphe()))

    await expect(enCours).resolves.toEqual({ statut: 'perime' })
    expect(grapheEnMemoire()).toBeNull()
    // L'ETag du lecteur précédent n'est pas gardé non plus.
    expect(etagConnu(CLE_ETAG_GRAPHE)).toBeUndefined()
  })

  it('sans graphe en mémoire, demande un 200 plutôt que de revalider', async () => {
    // Un ETag connu, mais rien de gardé (le magasin a été vidé depuis).
    api.chargerGraphe.mockImplementationOnce(async () => {
      expect(etagConnu(CLE_ETAG_GRAPHE)).toBeUndefined()
      return nouveau(graphe())
    })
    await revaliderGraphe()
    expect(api.chargerGraphe).toHaveBeenCalledOnce()
  })

  it('partage la requête entre deux revalidations simultanées', async () => {
    api.chargerGraphe.mockResolvedValue(nouveau(graphe()))
    const [a, b] = await Promise.all([revaliderGraphe(), revaliderGraphe()])

    expect(api.chargerGraphe).toHaveBeenCalledOnce()
    expect(a).toBe(b)
  })
})
