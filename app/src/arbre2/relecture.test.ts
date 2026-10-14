import { describe, expect, it, vi } from 'vitest'
import { effectScope, nextTick, shallowRef } from 'vue'
import type { FichePersonne } from '@/lib/api'
import { ErreurApi } from '@/lib/api'
import { useLectureDeLaFiche } from './lectureFiche'
import { useRelectureDeLArbre, type CibleDeVisibilite } from './relecture'

/**
 * Ce que ces tests protègent : quand `/arbre` relit son graphe hors du premier
 * chargement, et ce que le volet dit d'une fiche qu'il n'a pas pu lire. Rien de tout
 * cela ne se voit avant qu'un administrateur quitte « voir comme » et reste devant le
 * graphe du membre, ou qu'un réseau qui hoquette laisse « Lecture de la fiche… » sans
 * fin.
 */
function documentFactice(): CibleDeVisibilite & { etat: DocumentVisibilityState; changer: (e: DocumentVisibilityState) => void; ecouteurs: Set<() => void> } {
  const ecouteurs = new Set<() => void>()
  const doc = {
    etat: 'visible' as DocumentVisibilityState,
    get visibilityState() {
      return doc.etat
    },
    ecouteurs,
    addEventListener: (_: 'visibilitychange', f: () => void) => void ecouteurs.add(f),
    removeEventListener: (_: 'visibilitychange', f: () => void) => void ecouteurs.delete(f),
    changer(e: DocumentVisibilityState) {
      doc.etat = e
      for (const f of ecouteurs) f()
    },
  }
  return doc
}

describe('la relecture de l’arbre', () => {
  it('recharge quand le lecteur change — « Quitter la vue empruntée » ne navigue pas —, et abandonne ce qui était en vol', async () => {
    const identite = shallowRef<string | null>('compte:1|voirComme:7|par:')
    const nouveauLecteur = vi.fn()
    const portee = effectScope()
    const r = portee.run(() => useRelectureDeLArbre({ identite: () => identite.value, nouveauLecteur, revalider: vi.fn(), document: null }))!
    const enVol = r.signal()

    identite.value = 'compte:1|voirComme:|par:'
    await nextTick()

    expect(nouveauLecteur).toHaveBeenCalledTimes(1)
    expect(enVol.aborted).toBe(true)
    // Les requêtes du nouveau lecteur partent sur un signal neuf.
    expect(r.signal().aborted).toBe(false)
    portee.stop()
  })

  it('ne recharge rien pour la même identité (une session relue), ni pour personne (déconnexion)', async () => {
    const identite = shallowRef<string | null>('compte:1|voirComme:|par:')
    const nouveauLecteur = vi.fn()
    const portee = effectScope()
    const r = portee.run(() => useRelectureDeLArbre({ identite: () => identite.value, nouveauLecteur, revalider: vi.fn(), document: null }))!

    identite.value = 'compte:1|voirComme:|par:'
    await nextTick()
    expect(nouveauLecteur).not.toHaveBeenCalled()

    const enVol = r.signal()
    identite.value = null
    await nextTick()
    expect(nouveauLecteur).not.toHaveBeenCalled()
    expect(enVol.aborted).toBe(true)
    portee.stop()
  })

  it('revalide au retour sur l’onglet, pas en le quittant', () => {
    const doc = documentFactice()
    const revalider = vi.fn()
    const portee = effectScope()
    portee.run(() => useRelectureDeLArbre({ identite: () => 'a', nouveauLecteur: vi.fn(), revalider, document: doc }))

    doc.changer('hidden')
    expect(revalider).not.toHaveBeenCalled()
    doc.changer('visible')
    expect(revalider).toHaveBeenCalledTimes(1)
    portee.stop()
  })

  it('au démontage, abandonne les requêtes en vol et n’écoute plus l’onglet', () => {
    const doc = documentFactice()
    const revalider = vi.fn()
    const portee = effectScope()
    const r = portee.run(() => useRelectureDeLArbre({ identite: () => 'a', nouveauLecteur: vi.fn(), revalider, document: doc }))!
    const enVol = r.signal()

    portee.stop()

    expect(enVol.aborted).toBe(true)
    expect(doc.ecouteurs.size).toBe(0)
    doc.changer('hidden')
    doc.changer('visible')
    expect(revalider).not.toHaveBeenCalled()
  })
})

describe('la lecture de la fiche choisie', () => {
  const complete = (id: number) => ({ visibilite: 'complete', id, parente: 'cousin' }) as unknown as FichePersonne

  it('passe par la lecture, puis rend la fiche et sa parenté', async () => {
    const personId = shallowRef<number | null>(12)
    const lire = vi.fn((id: number) => Promise.resolve(complete(id)))
    const portee = effectScope()
    const l = portee.run(() => useLectureDeLaFiche(() => personId.value, lire))!

    expect(l.etat.value.etat).toBe('lecture')
    await Promise.resolve()
    expect(l.etat.value).toEqual({ etat: 'complete', fiche: complete(12) })
    portee.stop()
  })

  it('ne lit rien pour un nœud en attente (sans personne)', () => {
    const lire = vi.fn()
    const portee = effectScope()
    const l = portee.run(() => useLectureDeLaFiche(() => null, lire))!
    expect(l.etat.value.etat).toBe('aucune')
    expect(lire).not.toHaveBeenCalled()
    portee.stop()
  })

  it('dit l’échec avec le message d’`analyserErreur`, et « Réessayer » relance', async () => {
    let echoue = true
    const lire = vi.fn((id: number) =>
      echoue ? Promise.reject(new ErreurApi(0, 'client_reseau_indisponible', '')) : Promise.resolve(complete(id)),
    )
    const portee = effectScope()
    const l = portee.run(() => useLectureDeLaFiche(() => 5, lire))!
    await Promise.resolve()
    await Promise.resolve()

    expect(l.etat.value).toEqual({ etat: 'erreur', message: "Le serveur n'a pas répondu. Vérifiez votre connexion." })

    echoue = false
    l.reessayer()
    expect(l.etat.value.etat).toBe('lecture')
    await Promise.resolve()
    expect(l.etat.value.etat).toBe('complete')
    expect(lire).toHaveBeenCalledTimes(2)
    portee.stop()
  })

  it('une fiche que le serveur rend restreinte se dit restreinte, pas « Lecture… » sans fin', async () => {
    const portee = effectScope()
    const l = portee.run(() =>
      useLectureDeLaFiche(() => 3, () => Promise.resolve({ visibilite: 'restreinte', id: 3 } as unknown as FichePersonne)),
    )!
    await Promise.resolve()
    expect(l.etat.value).toEqual({ etat: 'restreinte' })
    portee.stop()
  })

  it('choisir une autre personne abandonne la lecture en cours, dont la réponse n’écrit rien', async () => {
    const personId = shallowRef<number | null>(1)
    const signaux: AbortSignal[] = []
    let rendre: ((f: FichePersonne) => void) | null = null
    const lire = vi.fn((id: number, signal: AbortSignal) => {
      signaux.push(signal)
      return id === 1 ? new Promise<FichePersonne>((ok) => (rendre = ok)) : Promise.resolve(complete(id))
    })
    const portee = effectScope()
    const l = portee.run(() => useLectureDeLaFiche(() => personId.value, lire))!

    personId.value = 2
    await nextTick()
    await Promise.resolve()
    expect(signaux[0]!.aborted).toBe(true)
    expect(l.etat.value).toEqual({ etat: 'complete', fiche: complete(2) })

    rendre!(complete(1))
    await Promise.resolve()
    expect(l.etat.value).toEqual({ etat: 'complete', fiche: complete(2) })
    portee.stop()
  })
})
