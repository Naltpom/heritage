import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { chercherRessemblancesArbre, type FicheAComparer } from './arbre'
import { chercherRessemblancesBrouillons } from './propositions'

/**
 * Les deux routes de ressemblance (§ 5.5 bis, § 6.6 quater), vues du fil.
 *
 * Ce qui compte est la forme du corps, parce que le serveur la juge **au
 * caractère près** : une clé inconnue dans `fiche` rend `422`, un `texte` dans un
 * bloc date aussi. Le corps est donc recopié clé par clé, quoi que l'appelant
 * passe — même un objet qui en porte plus que son type ne le dit.
 */
interface Appel {
  url: string
  methode: string
  corps: unknown
}
let appels: Appel[] = []

beforeEach(() => {
  appels = []
  vi.stubGlobal('fetch', (entree: RequestInfo | URL, init?: RequestInit) => {
    appels.push({
      url: String(entree),
      methode: init?.method ?? 'GET',
      corps: typeof init?.body === 'string' ? JSON.parse(init.body) : null,
    })
    return Promise.resolve(
      new Response(JSON.stringify({ elements: [], tronque: false }), { status: 200, headers: { 'Content-Type': 'application/json' } }),
    )
  })
})
afterEach(() => vi.unstubAllGlobals())

const FICHE: FicheAComparer = {
  givenNames: 'Pyroxène',
  surname: 'Tilleule',
  marriedName: null,
  nickname: null,
  sex: 'masculin',
  deceased: false,
  birth: { raw: 'ABT 1934', qualifier: 'environ', min: '1934-01-01', max: '1934-12-31', place: 'Sorbière' },
  death: null,
}

/** La même fiche, avec ce qu'un formulaire porterait en plus — et qui ne doit pas partir. */
const FICHE_ENCOMBREE = {
  ...FICHE,
  note: 'Livret de famille',
  liens: [],
  birth: { ...FICHE.birth!, texte: 'vers 1934' },
} as unknown as FicheAComparer

describe('POST /api/arbre/ressemblances', () => {
  it('poste la fiche et la limite, en POST — rien dans l’URL', async () => {
    await chercherRessemblancesArbre(FICHE)

    expect(appels).toHaveLength(1)
    expect(appels[0]!.methode).toBe('POST')
    expect(appels[0]!.url).toBe('/api/arbre/ressemblances')
    expect(appels[0]!.corps).toEqual({ fiche: FICHE, limite: 5 })
  })

  it('ne laisse partir ni clé inconnue ni `texte` dans une date', async () => {
    await chercherRessemblancesArbre(FICHE_ENCOMBREE, 10)

    const corps = appels[0]!.corps as { fiche: Record<string, unknown>; limite: number }
    expect(Object.keys(corps.fiche).sort()).toEqual(
      ['birth', 'death', 'deceased', 'givenNames', 'marriedName', 'nickname', 'sex', 'surname'].sort(),
    )
    expect(Object.keys(corps.fiche['birth'] as object).sort()).toEqual(['max', 'min', 'place', 'qualifier', 'raw'])
    expect(corps.limite).toBe(10)
  })
})

describe('POST /api/demandes/brouillons/ressemblances', () => {
  it('poste la fiche, la limite par défaut et `sauf` à null', async () => {
    await chercherRessemblancesBrouillons(FICHE_ENCOMBREE)

    expect(appels[0]!.methode).toBe('POST')
    expect(appels[0]!.url).toBe('/api/demandes/brouillons/ressemblances')
    expect(appels[0]!.corps).toEqual({ fiche: FICHE, limite: 5, sauf: null })
  })

  it('transmet `sauf` quand on complète une demande', async () => {
    await chercherRessemblancesBrouillons(FICHE, { limite: 3, sauf: 12 })

    expect(appels[0]!.corps).toMatchObject({ limite: 3, sauf: 12 })
  })
})
