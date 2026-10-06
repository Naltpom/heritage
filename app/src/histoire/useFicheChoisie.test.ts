import { beforeEach, describe, expect, it, vi } from 'vitest'
import { effectScope, nextTick, ref } from 'vue'

/**
 * Ce que ces tests protègent : la lecture de la fiche choisie dans l'arbre.
 *
 * Le défaut d'origine : un échec était avalé (`.catch(() => {})`) et le volet
 * affichait « Lecture de la fiche… » pour toujours. La panne doit se dire, et
 * « Réessayer » relire ; une lecture dépassée ne doit rien afficher du tout.
 */
const api = vi.hoisted(() => ({ lireFichePersonne: vi.fn() }))
vi.mock('@/lib/api', async (original) => ({
  ...(await original<typeof import('@/lib/api')>()),
  lireFichePersonne: api.lireFichePersonne,
}))

import { CODE_ANNULE, CODE_RESEAU, ErreurApi } from '@/lib/api'
import { useFicheChoisie } from './useFicheChoisie'

const attendre = async (): Promise<void> => {
  await nextTick()
  await new Promise((fin) => setTimeout(fin, 0))
}

function monter() {
  const id = ref<number | null>(null)
  const portee = effectScope()
  const lecture = portee.run(() => useFicheChoisie(id))!
  return { id, lecture, portee }
}

const fiche = (id: number) => ({ id, visibilite: 'complete', parente: 'grand-père' })

beforeEach(() => {
  api.lireFichePersonne.mockReset()
})

describe('la fiche de la personne choisie', () => {
  it('se lit, et donne la parenté calculée par le serveur', async () => {
    api.lireFichePersonne.mockResolvedValue(fiche(44))
    const { id, lecture, portee } = monter()

    id.value = 44
    await attendre()

    expect(lecture.etat.value).toBe('lue')
    expect(lecture.parente.value).toBe('grand-père')
    expect(lecture.ficheLue.value?.id).toBe(44)
    portee.stop()
  })

  it('dit la panne au lieu d’attendre pour toujours, et « Réessayer » relit', async () => {
    api.lireFichePersonne.mockRejectedValueOnce(new ErreurApi(0, CODE_RESEAU, "Le serveur n'a pas répondu."))
    const { id, lecture, portee } = monter()

    id.value = 44
    await attendre()
    expect(lecture.etat.value).toBe('erreur')
    expect(lecture.erreur.value).toBeInstanceOf(ErreurApi)

    api.lireFichePersonne.mockResolvedValueOnce(fiche(44))
    lecture.relire()
    expect(lecture.etat.value).toBe('lecture')
    await attendre()

    expect(lecture.etat.value).toBe('lue')
    expect(lecture.erreur.value).toBeNull()
    portee.stop()
  })

  it('n’affiche pas l’annulation d’une lecture dépassée', async () => {
    api.lireFichePersonne.mockImplementation(
      (_id: number, signal: AbortSignal) =>
        new Promise((_ok, echec) => signal.addEventListener('abort', () => echec(new ErreurApi(0, CODE_ANNULE, 'Requête interrompue.')))),
    )
    const { id, lecture, portee } = monter()

    id.value = 44
    await nextTick()
    id.value = 45
    await attendre()

    expect(lecture.etat.value).toBe('lecture')
    expect(lecture.erreur.value).toBeNull()
    portee.stop()
  })

  it('ne lit rien pour une fiche proposée, sans identifiant', async () => {
    api.lireFichePersonne.mockReturnValue(new Promise(() => undefined))
    const { id, lecture, portee } = monter()
    id.value = 44
    await nextTick()
    id.value = null
    await nextTick()

    expect(lecture.etat.value).toBe('aucune')
    portee.stop()
  })
})
