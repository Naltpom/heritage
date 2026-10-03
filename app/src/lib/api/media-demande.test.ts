import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { proposerLeMedia } from './propositions'

/**
 * Un média déposé par un membre naît `en_attente` et n'entre dans la file de
 * l'administration que par une demande `ajout_media` (§ 6.4). La galerie d'une fiche
 * ne la créait pas : la photo restait orpheline, « en attente » pour toujours et
 * absente des demandes (signalé le 03/10/2026). Ces cas tiennent la couture.
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
    appels.push({ url: String(entree), methode: init?.method ?? 'GET', corps: typeof init?.body === 'string' ? JSON.parse(init.body) : null })
    return Promise.resolve(new Response(JSON.stringify({ id: 9, statut: 'en_attente' }), { status: 201, headers: { 'Content-Type': 'application/json' } }))
  })
})
afterEach(() => vi.unstubAllGlobals())

const MEDIA = { id: 4656, kind: 'portrait', status: 'en_attente', caption: null, sha256: 'abc', changeRequestId: null }

describe('la demande qui porte un média déposé', () => {
  it('est créée et soumise pour un média en attente', async () => {
    await proposerLeMedia(MEDIA, 4)

    expect(appels).toHaveLength(1)
    expect(appels[0]!.methode).toBe('POST')
    expect(appels[0]!.url).toContain('/demandes')
    expect(appels[0]!.corps).toMatchObject({
      kind: 'ajout_media',
      soumettre: true,
      targetKind: 'personne',
      targetId: 4,
      ops: [{ opKind: 'ajout_media', subjectId: 4, valueKind: 'media', field: null, newValue: { mediaId: 4656, kind: 'portrait', sha256: 'abc' } }],
    })
  })

  it('n’est pas créée pour un média déjà actif (dépôt d’administrateur) ni déjà porté', async () => {
    expect(await proposerLeMedia({ ...MEDIA, status: 'actif' }, 4)).toBeNull()
    expect(await proposerLeMedia({ ...MEDIA, changeRequestId: 12 }, 4)).toBeNull()
    expect(appels).toHaveLength(0)
  })
})
