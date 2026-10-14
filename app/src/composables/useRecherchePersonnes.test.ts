import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { effectScope, nextTick } from 'vue'
import type { ReponseDeRecherche, ResultatDeRecherche } from '@/lib/api'

/**
 * Ce que ces tests protègent : la recherche de personnes, partagée par l'en-tête,
 * les deux arbres, la page des résultats et le sélecteur des formulaires.
 *
 * Le défaut d'origine : des copies qui avalaient les erreurs. Une coupure de
 * réseau s'affichait « Personne ne correspond. », et ce texte apparaissait avant
 * même la première réponse. Les cinq états doivent rester distincts.
 */
const api = vi.hoisted(() => ({ rechercherPersonnes: vi.fn() }))
vi.mock('@/lib/api', async (original) => ({
  ...(await original<typeof import('@/lib/api')>()),
  rechercherPersonnes: api.rechercherPersonnes,
}))

import { CODE_ANNULE, CODE_RESEAU, ErreurApi } from '@/lib/api/erreurs'
import { curseurSuivant, estAnnulation, etatDeRecherche, useRecherchePersonnes } from './useRecherchePersonnes'

function resultat(personId: number, displayName: string): ResultatDeRecherche {
  return { personId, displayName, sex: 'feminin', generation: 0, branch: 'pp', vivant: false, visibilite: 'complete' }
}

function reponse(elements: ResultatDeRecherche[], tronque = false): ReponseDeRecherche {
  return { elements, tronque }
}

/** Monte le composable hors composant, dans une portée qu'on peut fermer. */
function monter(limite = 8, surResultats = vi.fn()) {
  const portee = effectScope()
  const r = portee.run(() => useRecherchePersonnes({ limite: () => limite, delai: 100, surResultats }))!
  return { r, portee, surResultats }
}

/** Laisse passer le délai de frappe et la réponse simulée. */
async function laisserRepondre(): Promise<void> {
  await nextTick()
  await vi.advanceTimersByTimeAsync(100)
  await nextTick()
}

beforeEach(() => {
  vi.useFakeTimers()
  api.rechercherPersonnes.mockReset()
})
afterEach(() => vi.useRealTimers())

describe('etatDeRecherche', () => {
  const base = { texte: 'bo', enCours: false, erreur: null, repondu: true, nombre: 0 }

  it('ne dit rien sous deux caractères', () => {
    expect(etatDeRecherche({ ...base, texte: 'b' })).toBe('inactif')
    expect(etatDeRecherche({ ...base, texte: '  b  ' })).toBe('inactif')
  })

  it('attend la première réponse avant de dire « personne »', () => {
    expect(etatDeRecherche({ ...base, repondu: false })).toBe('chargement')
    expect(etatDeRecherche({ ...base, enCours: true, nombre: 3 })).toBe('chargement')
  })

  it('distingue la panne, le vide et les résultats', () => {
    expect(etatDeRecherche({ ...base, erreur: new Error('réseau') })).toBe('erreur')
    expect(etatDeRecherche(base)).toBe('vide')
    expect(etatDeRecherche({ ...base, nombre: 2 })).toBe('resultats')
  })
})

describe('estAnnulation', () => {
  it('reconnaît l’annulation du client et celle du navigateur, pas une panne', () => {
    expect(estAnnulation(new ErreurApi(0, CODE_ANNULE, 'Requête interrompue.'))).toBe(true)
    expect(estAnnulation(new DOMException('aborted', 'AbortError'))).toBe(true)
    expect(estAnnulation(new ErreurApi(0, CODE_RESEAU, "Le serveur n'a pas répondu."))).toBe(false)
    expect(estAnnulation(null)).toBe(false)
  })
})

describe('curseurSuivant', () => {
  it('fait le tour dans les deux sens', () => {
    expect(curseurSuivant(0, 1, 3)).toBe(1)
    expect(curseurSuivant(2, 1, 3)).toBe(0)
    expect(curseurSuivant(0, -1, 3)).toBe(2)
    expect(curseurSuivant(4, 1, 0)).toBe(0)
  })
})

describe('useRecherchePersonnes', () => {
  it('n’appelle l’API qu’après le délai, une fois pour la dernière saisie', async () => {
    api.rechercherPersonnes.mockResolvedValue(reponse([resultat(1, 'Ambre Bourrache')]))
    const { r, portee } = monter()

    r.texte.value = 'bo'
    await nextTick()
    r.texte.value = 'bou'
    await nextTick()
    expect(r.etat.value).toBe('chargement')
    expect(api.rechercherPersonnes).not.toHaveBeenCalled()

    await laisserRepondre()

    expect(api.rechercherPersonnes).toHaveBeenCalledOnce()
    expect(api.rechercherPersonnes.mock.calls[0]?.[0]).toBe('bou')
    expect(api.rechercherPersonnes.mock.calls[0]?.[1]).toBe(8)
    expect(r.etat.value).toBe('resultats')
    expect(r.resultats.value.map((x) => x.personId)).toEqual([1])
    portee.stop()
  })

  it('dit « vide » seulement quand le serveur a répondu sans personne', async () => {
    api.rechercherPersonnes.mockResolvedValue(reponse([]))
    const { r, portee } = monter()

    r.texte.value = 'zz'
    await nextTick()
    expect(r.etat.value).toBe('chargement')
    await laisserRepondre()

    expect(r.etat.value).toBe('vide')
    portee.stop()
  })

  it('ne dit jamais « vide » sur une panne, même après des résultats', async () => {
    api.rechercherPersonnes.mockResolvedValueOnce(reponse([resultat(1, 'Ambre Bourrache')]))
    api.rechercherPersonnes.mockRejectedValueOnce(new ErreurApi(0, CODE_RESEAU, "Le serveur n'a pas répondu."))
    const { r, portee, surResultats } = monter()

    r.texte.value = 'bo'
    await laisserRepondre()
    expect(r.etat.value).toBe('resultats')

    r.texte.value = 'bou'
    await laisserRepondre()

    expect(r.etat.value).toBe('erreur')
    // Les résultats d'avant ne répondent pas à « bou » : ils partent, et l'arbre abonné l'apprend.
    expect(r.resultats.value).toEqual([])
    expect(surResultats).toHaveBeenLastCalledWith('bou', [])
    portee.stop()
  })

  it('montre une panne comme une panne, et « Réessayer » relance', async () => {
    api.rechercherPersonnes.mockRejectedValueOnce(new ErreurApi(0, CODE_RESEAU, "Le serveur n'a pas répondu."))
    const { r, portee } = monter()

    r.texte.value = 'bo'
    await laisserRepondre()

    expect(r.etat.value).toBe('erreur')
    expect(r.message.value).toBe("Le serveur n'a pas répondu. Vérifiez votre connexion.")

    api.rechercherPersonnes.mockResolvedValueOnce(reponse([resultat(2, 'Marne Sureau')]))
    r.reessayer()
    expect(r.etat.value).toBe('chargement')
    await laisserRepondre()

    expect(r.etat.value).toBe('resultats')
    expect(api.rechercherPersonnes).toHaveBeenCalledTimes(2)
    portee.stop()
  })

  it('annule la requête dépassée, sans afficher l’annulation', async () => {
    const signaux: AbortSignal[] = []
    api.rechercherPersonnes.mockImplementation(
      (_q: string, _l: number, signal: AbortSignal) =>
        new Promise((_ok, echec) => {
          signaux.push(signal)
          signal.addEventListener('abort', () => echec(new ErreurApi(0, CODE_ANNULE, 'Requête interrompue.')))
        }),
    )
    const { r, portee } = monter()

    r.texte.value = 'bo'
    await laisserRepondre()
    r.texte.value = 'bou'
    await nextTick()

    expect(signaux[0]?.aborted).toBe(true)
    await nextTick()
    expect(r.etat.value).toBe('chargement')
    expect(r.erreur.value).toBeNull()
    portee.stop()
  })

  it('ignore une réponse arrivée après une saisie plus récente', async () => {
    let repondre: (x: ReponseDeRecherche) => void = () => undefined
    // La première requête ignore l'annulation et répond quand même, en retard.
    api.rechercherPersonnes.mockReturnValueOnce(new Promise((ok) => (repondre = ok)))
    api.rechercherPersonnes.mockResolvedValueOnce(reponse([resultat(3, 'Opale Sureau')]))
    const { r, portee } = monter()

    r.texte.value = 'bo'
    await laisserRepondre()
    r.texte.value = 'so'
    await laisserRepondre()
    repondre(reponse([resultat(9, 'Périmée')]))
    await nextTick()

    expect(r.resultats.value.map((x) => x.personId)).toEqual([3])
    portee.stop()
  })

  it('se vide sous deux caractères et le publie', async () => {
    api.rechercherPersonnes.mockResolvedValue(reponse([resultat(1, 'Ambre Bourrache')], true))
    const { r, portee, surResultats } = monter()

    r.texte.value = 'bo'
    await laisserRepondre()
    expect(r.tronque.value).toBe(true)
    expect(surResultats).toHaveBeenLastCalledWith('bo', [expect.objectContaining({ personId: 1 })])

    r.texte.value = 'b'
    await nextTick()

    expect(r.etat.value).toBe('inactif')
    expect(r.resultats.value).toEqual([])
    expect(r.tronque.value).toBe(false)
    expect(surResultats).toHaveBeenLastCalledWith('b', [])
    portee.stop()
  })

  it('rend la réponse entière d’un `chercher` fourni, avec la limite demandée', async () => {
    const chercher = vi.fn((_q: string, _l: number, _s: AbortSignal) =>
      Promise.resolve({ ...reponse([resultat(4, 'Ilda Sureau')]), propositions: ['#12/p1'] }),
    )
    const portee = effectScope()
    const r = portee.run(() => useRecherchePersonnes({ limite: () => 10, delai: 100, chercher }))!

    r.texte.value = 'il'
    await laisserRepondre()

    expect(api.rechercherPersonnes).not.toHaveBeenCalled()
    expect(chercher.mock.calls[0]?.slice(0, 2)).toEqual(['il', 10])
    expect(r.reponse.value?.propositions).toEqual(['#12/p1'])
    expect(r.etat.value).toBe('resultats')
    portee.stop()
  })

  it('annule la requête en vol quand la portée se ferme', async () => {
    const signaux: AbortSignal[] = []
    api.rechercherPersonnes.mockImplementation((_q: string, _l: number, signal: AbortSignal) => {
      signaux.push(signal)
      return new Promise(() => undefined)
    })
    const { r, portee } = monter()

    r.texte.value = 'bo'
    await laisserRepondre()
    portee.stop()

    expect(signaux[0]?.aborted).toBe(true)
  })

  it('vider() ferme tout, tout de suite', async () => {
    api.rechercherPersonnes.mockResolvedValue(reponse([resultat(1, 'Ambre Bourrache')]))
    const { r, portee } = monter()
    r.texte.value = 'bo'
    await laisserRepondre()

    r.vider()

    expect(r.texte.value).toBe('')
    expect(r.resultats.value).toEqual([])
    expect(r.etat.value).toBe('inactif')
    portee.stop()
  })
})
