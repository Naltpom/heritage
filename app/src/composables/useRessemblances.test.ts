/**
 * Les ressemblances cherchées pendant la saisie, sans navigateur ni serveur.
 *
 * L'API est simulée, les minuteurs aussi : ce qu'on tient, c'est le **rythme** —
 * rien avant 400 ms de calme, une frappe qui annule ce qui part, une réponse
 * tardive qui n'écrit rien, une route en panne qui ne vide pas l'autre, et
 * **aucun appel** quand ce qu'on compare n'a pas changé.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { effectScope, nextTick, ref, type EffectScope } from 'vue'
import type { RessemblanceDansLArbre, RessemblanceProposition } from '@/lib/api'

const api = vi.hoisted(() => ({
  chercherRessemblancesArbre: vi.fn(),
  chercherRessemblancesBrouillons: vi.fn(),
}))
vi.mock('@/lib/api', async (original) => ({
  ...(await original<typeof import('@/lib/api')>()),
  ...api,
}))

import { brouillonVide, type SaisieBrouillon } from '@/components/proposition/creation'
import { DELAI_DES_RESSEMBLANCES_MS, useRessemblances } from './useRessemblances'

function personne(personId: number): RessemblanceDansLArbre {
  return {
    personId,
    displayName: `Personne ${personId}`,
    sex: 'masculin',
    vivant: false,
    generation: -2,
    branch: 'pp',
    ligneDates: '1901 – 1968',
    lieuNaissance: null,
    criteres: ['nom', 'prenom'],
    raisons: ['même nom', 'un prénom en commun'],
    score: 2,
  }
}

const DISCRETE: RessemblanceProposition = {
  forme: 'discrete',
  draftPersonId: 9,
  action: 'complement',
  criteres: ['nom', 'prenom', 'sexe'],
  raisons: ['nom semblable', 'un prénom en commun', 'même sexe'],
}

/** Une promesse qu'on tient à la main. */
function differee<T>(): { promesse: Promise<T>; tenir: (valeur: T) => void; rompre: (cause: unknown) => void } {
  let tenir!: (valeur: T) => void
  let rompre!: (cause: unknown) => void
  const promesse = new Promise<T>((oui, non) => {
    tenir = oui
    rompre = non
  })
  return { promesse, tenir, rompre }
}

let portee: EffectScope

function monter(depart: Partial<SaisieBrouillon> = {}, actif = ref(true)) {
  const saisie = ref<SaisieBrouillon>({ ...brouillonVide(), ...depart })
  portee = effectScope()
  const etat = portee.run(() => useRessemblances(() => saisie.value, { actif: () => actif.value }))!
  return { saisie, actif, etat }
}

/** Laisse passer les promesses résolues et les rendus qu'elles déclenchent. */
async function vider(): Promise<void> {
  for (let i = 0; i < 5; i++) await Promise.resolve()
  await nextTick()
}

beforeEach(() => {
  vi.useFakeTimers()
  api.chercherRessemblancesArbre.mockReset().mockResolvedValue({ elements: [personne(1)], tronque: false })
  api.chercherRessemblancesBrouillons.mockReset().mockResolvedValue({ elements: [DISCRETE], tronque: false })
})

afterEach(() => {
  portee?.stop()
  vi.useRealTimers()
})

describe('useRessemblances', () => {
  it('attend 400 ms de calme, puis interroge les deux routes en parallèle', async () => {
    const { saisie, etat } = monter()
    saisie.value = { ...saisie.value, givenNames: 'Barnabé', surname: 'Muguet' }
    await nextTick()

    vi.advanceTimersByTime(DELAI_DES_RESSEMBLANCES_MS - 1)
    expect(api.chercherRessemblancesArbre).not.toHaveBeenCalled()

    vi.advanceTimersByTime(1)
    expect(api.chercherRessemblancesArbre).toHaveBeenCalledTimes(1)
    expect(api.chercherRessemblancesBrouillons).toHaveBeenCalledTimes(1)
    expect(api.chercherRessemblancesBrouillons.mock.calls[0]?.[1]).toEqual({ limite: 5, sauf: null })

    await vider()
    expect(etat.arbre.value.map((r) => r.personId)).toEqual([1])
    expect(etat.propositions.value).toEqual([DISCRETE])
  })

  it('n’appelle pas sans fiche comparable', async () => {
    const { saisie } = monter()
    saisie.value = { ...saisie.value, givenNames: 'Barnabé', surname: 'M' }
    await nextTick()
    vi.advanceTimersByTime(5 * DELAI_DES_RESSEMBLANCES_MS)

    expect(api.chercherRessemblancesArbre).not.toHaveBeenCalled()
  })

  it('une frappe annule le minuteur et la requête en vol', async () => {
    const { saisie } = monter({ givenNames: 'Barnabé' })
    saisie.value = { ...saisie.value, surname: 'Mug' }
    await nextTick()
    vi.advanceTimersByTime(DELAI_DES_RESSEMBLANCES_MS / 2)
    saisie.value = { ...saisie.value, surname: 'Muguet' }
    await nextTick()
    vi.advanceTimersByTime(DELAI_DES_RESSEMBLANCES_MS)

    // Un seul appel, sur la dernière saisie.
    expect(api.chercherRessemblancesArbre).toHaveBeenCalledTimes(1)
    expect(api.chercherRessemblancesArbre.mock.calls[0]?.[0]).toMatchObject({ surname: 'Muguet' })

    const signal = api.chercherRessemblancesArbre.mock.calls[0]?.[2] as AbortSignal
    saisie.value = { ...saisie.value, surname: 'Muguette' }
    await nextTick()
    expect(signal.aborted).toBe(true)
  })

  it('une réponse tardive n’écrit pas par-dessus une plus récente', async () => {
    const lente = differee<{ elements: RessemblanceDansLArbre[]; tronque: boolean }>()
    api.chercherRessemblancesArbre.mockReturnValueOnce(lente.promesse)
    const { saisie, etat } = monter({ givenNames: 'Barnabé' })

    saisie.value = { ...saisie.value, surname: 'Muguet' }
    await nextTick()
    vi.advanceTimersByTime(DELAI_DES_RESSEMBLANCES_MS)
    saisie.value = { ...saisie.value, surname: 'Muguette' }
    await nextTick()
    vi.advanceTimersByTime(DELAI_DES_RESSEMBLANCES_MS)
    await vider()
    expect(etat.arbre.value.map((r) => r.personId)).toEqual([1])

    // La première requête répond enfin — malgré son annulation.
    lente.tenir({ elements: [personne(666)], tronque: false })
    await vider()
    expect(etat.arbre.value.map((r) => r.personId)).toEqual([1])
  })

  it('l’échec d’une route ne vide pas l’autre', async () => {
    api.chercherRessemblancesBrouillons.mockRejectedValue(new Error('réseau'))
    const { saisie, etat } = monter({ givenNames: 'Barnabé' })
    saisie.value = { ...saisie.value, surname: 'Muguet' }
    await nextTick()
    vi.advanceTimersByTime(DELAI_DES_RESSEMBLANCES_MS)
    await vider()

    expect(etat.arbre.value.map((r) => r.personId)).toEqual([1])
    expect(etat.propositions.value).toEqual([])

    api.chercherRessemblancesBrouillons.mockResolvedValue({ elements: [DISCRETE], tronque: false })
    api.chercherRessemblancesArbre.mockRejectedValue(new Error('réseau'))
    saisie.value = { ...saisie.value, surname: 'Muguette' }
    await nextTick()
    vi.advanceTimersByTime(DELAI_DES_RESSEMBLANCES_MS)
    await vider()

    expect(etat.arbre.value).toEqual([])
    expect(etat.propositions.value).toEqual([DISCRETE])
  })

  it('n’appelle pas quand le critère n’a pas changé : note, case du décès', async () => {
    const { saisie } = monter({ givenNames: 'Barnabé' })
    saisie.value = { ...saisie.value, surname: 'Muguet' }
    await nextTick()
    vi.advanceTimersByTime(DELAI_DES_RESSEMBLANCES_MS)
    await vider()
    expect(api.chercherRessemblancesArbre).toHaveBeenCalledTimes(1)

    saisie.value = { ...saisie.value, note: 'Livret de famille', deceased: true }
    await nextTick()
    vi.advanceTimersByTime(5 * DELAI_DES_RESSEMBLANCES_MS)
    await vider()

    expect(api.chercherRessemblancesArbre).toHaveBeenCalledTimes(1)
  })

  it('éteint, il n’appelle pas et oublie ce qu’il montrait', async () => {
    const { saisie, actif, etat } = monter({ givenNames: 'Barnabé' })
    saisie.value = { ...saisie.value, surname: 'Muguet' }
    await nextTick()
    vi.advanceTimersByTime(DELAI_DES_RESSEMBLANCES_MS)
    await vider()
    expect(etat.arbre.value).toHaveLength(1)

    actif.value = false
    await nextTick()
    expect(etat.arbre.value).toEqual([])
    saisie.value = { ...saisie.value, surname: 'Muguette' }
    await nextTick()
    vi.advanceTimersByTime(5 * DELAI_DES_RESSEMBLANCES_MS)
    expect(api.chercherRessemblancesArbre).toHaveBeenCalledTimes(1)
  })

  it('démonté, il annule ce qui part et n’appelle plus', async () => {
    const { saisie } = monter({ givenNames: 'Barnabé' })
    saisie.value = { ...saisie.value, surname: 'Muguet' }
    await nextTick()
    portee.stop()
    vi.advanceTimersByTime(5 * DELAI_DES_RESSEMBLANCES_MS)

    expect(api.chercherRessemblancesArbre).not.toHaveBeenCalled()
  })

  it('écarte une carte, et repart de zéro sur `reinitialiser`', async () => {
    const { saisie, etat } = monter({ givenNames: 'Barnabé' })
    saisie.value = { ...saisie.value, surname: 'Muguet' }
    await nextTick()
    vi.advanceTimersByTime(DELAI_DES_RESSEMBLANCES_MS)
    await vider()

    etat.ecarter('personne:1')
    etat.ecarter('personne:1')
    expect(etat.ecartees.value).toEqual(['personne:1'])

    etat.reinitialiser()
    expect(etat.ecartees.value).toEqual([])
    expect(etat.arbre.value).toEqual([])
    expect(etat.propositions.value).toEqual([])
  })
})
