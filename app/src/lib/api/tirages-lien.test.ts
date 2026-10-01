import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { surSessionPerdue } from './client'
import { CODE_AUTHENTIFICATION_REQUISE, ErreurApi } from './erreurs'
import { rechercherPersonnes } from './arbre'
import {
  creerLienDuParticipant,
  definirPresenceParLien,
  lireParLien,
  revoquerLienDuParticipant,
} from './tirages'

/**
 * Ce que ces tests protègent : le lien personnel d'un participant (`/t/<clé>`)
 * et la recherche « vivants seulement » des tirages.
 *
 * 1. **Les deux routes publiques ne renvoient jamais vers la connexion.** La
 *    personne qui ouvre le lien n'a pas de compte : une 401 inattendue y est un
 *    défaut de câblage, et le rattrapage global l'enverrait sur un formulaire
 *    qui ne lui sert à rien.
 * 2. La clé est **encodée** dans le chemin, jamais recollée brute.
 * 3. `?vivants=1` n'est envoyé **que** si on le demande : les formulaires de
 *    proposition et les actes demandés désignent des défunts.
 */

interface AppelObserve {
  url: string
  methode: string
  corps: string | null
}

let appels: AppelObserve[] = []

function poserReponse(reponse: () => Response): void {
  vi.stubGlobal('fetch', (entree: RequestInfo | URL, init?: RequestInit) => {
    appels.push({
      url: String(entree),
      methode: init?.method ?? 'GET',
      corps: typeof init?.body === 'string' ? init.body : null,
    })
    return Promise.resolve(reponse())
  })
}

function json(corps: unknown, statut: number): Response {
  return new Response(JSON.stringify(corps), {
    status: statut,
    headers: { 'Content-Type': 'application/json; charset=utf-8' },
  })
}

const LECTURE = {
  tirage: { nom: 'Noël des Romarin', lieu: 'Fleurance' },
  edition: { annee: 2026, date: '2026-12-24', statut: 'ouverte', passee: false },
  participant: { personId: 4, libelle: 'Léa Romarin', presence: 'sur_place', peutChangerPresence: true },
  attributions: [],
}

beforeEach(() => {
  appels = []
})

afterEach(() => {
  vi.unstubAllGlobals()
  surSessionPerdue(() => undefined)
})

describe('le lien personnel, côté client', () => {
  it('lit le tirage par sa clé, encodée dans le chemin', async () => {
    poserReponse(() => json(LECTURE, 200))

    const lecture = await lireParLien('a/b c')

    expect(appels[0]?.url).toBe('/api/tirages/lien/a%2Fb%20c')
    expect(lecture.participant.libelle).toBe('Léa Romarin')
  })

  it('ne déclenche pas le rattrapage de session sur une 401', async () => {
    const rappel = vi.fn()
    surSessionPerdue(rappel)
    poserReponse(() => json({ erreur: { code: CODE_AUTHENTIFICATION_REQUISE, message: 'Session requise.' } }, 401))

    await expect(lireParLien('cle')).rejects.toBeInstanceOf(ErreurApi)
    await expect(definirPresenceParLien('cle', 'absente')).rejects.toBeInstanceOf(ErreurApi)
    expect(rappel).not.toHaveBeenCalled()
  })

  it('rend une 404 comme une ErreurApi de statut 404, sans rien distinguer', async () => {
    poserReponse(() => json({ erreur: { code: 'ressource_introuvable', message: 'Ressource introuvable.' } }, 404))

    await expect(lireParLien('inconnue')).rejects.toMatchObject({ statut: 404 })
  })

  it('règle la présence par un PUT sur la clé', async () => {
    poserReponse(() => json({ ...LECTURE, participant: { ...LECTURE.participant, presence: 'a_distance' } }, 200))

    const lecture = await definirPresenceParLien('cle', 'a_distance')

    expect(appels[0]).toEqual({
      url: '/api/tirages/lien/cle/presence',
      methode: 'PUT',
      corps: JSON.stringify({ presence: 'a_distance' }),
    })
    expect(lecture.participant.presence).toBe('a_distance')
  })

  it('crée et révoque le lien d’un participant', async () => {
    poserReponse(() => json({ cle: 'xyz', url: '/t/xyz' }, 201))
    expect(await creerLienDuParticipant(7, 12)).toEqual({ cle: 'xyz', url: '/t/xyz' })

    poserReponse(() => new Response(null, { status: 204 }))
    await revoquerLienDuParticipant(7, 12)

    expect(appels.map((a) => `${a.methode} ${a.url}`)).toEqual([
      'POST /api/tirages/7/participants/12/lien',
      'DELETE /api/tirages/7/participants/12/lien',
    ])
  })
})

describe('la recherche des tirages : vivants seulement', () => {
  it('n’envoie pas `vivants` par défaut', async () => {
    poserReponse(() => json({ elements: [], tronque: false }, 200))

    await rechercherPersonnes('Rom', 10)

    expect(appels[0]?.url).toBe('/api/arbre/recherche?q=Rom&limite=10')
  })

  it('envoie `vivants=1` quand on le demande', async () => {
    poserReponse(() => json({ elements: [], tronque: false }, 200))

    await rechercherPersonnes('Rom', 10, undefined, true)

    expect(appels[0]?.url).toBe('/api/arbre/recherche?q=Rom&limite=10&vivants=1')
  })
})
