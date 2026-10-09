import { createSSRApp, defineComponent, h, ref, type Ref } from 'vue'
import { renderToString } from 'vue/server-renderer'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { monterEnMemoire } from '@/test-utils/rendu-memoire'
import { useCorrespondance } from './useTiroirs'

/**
 * `useCorrespondance` — une requête média, réactive. L'arbre s'en sert pour ses
 * deux volets (`lg`, puis 90 rem). Pas de DOM : la fenêtre est une
 * `MediaQueryList` écrite à la main, posée sur `globalThis.matchMedia`.
 */

interface ListeFactice {
  matches: boolean
  ecouteurs: Set<(ev: { matches: boolean }) => void>
  addEventListener: (type: 'change', e: (ev: { matches: boolean }) => void) => void
  removeEventListener: (type: 'change', e: (ev: { matches: boolean }) => void) => void
}

function listeFactice(matches: boolean): ListeFactice {
  const ecouteurs = new Set<(ev: { matches: boolean }) => void>()
  return {
    matches,
    ecouteurs,
    addEventListener: (_type, e) => ecouteurs.add(e),
    removeEventListener: (_type, e) => ecouteurs.delete(e),
  }
}

/** Un composant qui lit la requête et nous en confie la valeur. */
function sonde(requete: string, recu: { valeur: Readonly<Ref<boolean>> | null }) {
  return defineComponent({
    setup() {
      const valeur = useCorrespondance(requete)
      recu.valeur = valeur
      return () => h('p', valeur.value ? 'oui' : 'non')
    },
  })
}

afterEach(() => {
  vi.unstubAllGlobals()
})

describe('useCorrespondance', () => {
  it('vaut `false` hors navigateur, sans rien demander à une fenêtre absente', async () => {
    const recu: { valeur: Readonly<Ref<boolean>> | null } = { valeur: null }
    const html = await renderToString(createSSRApp(sonde('(width >= 64rem)', recu)))

    expect(html).toBe('<p>non</p>')
  })

  it('lit la requête dès la construction, puis la suit', async () => {
    const liste = listeFactice(true)
    const matchMedia = vi.fn(() => liste)
    vi.stubGlobal('matchMedia', matchMedia)
    const recu: { valeur: Readonly<Ref<boolean>> | null } = { valeur: null }

    await monterEnMemoire(sonde('(width >= 90rem)', recu))

    expect(matchMedia).toHaveBeenCalledWith('(width >= 90rem)')
    expect(recu.valeur?.value).toBe(true)

    liste.matches = false
    for (const e of liste.ecouteurs) e({ matches: false })
    expect(recu.valeur?.value).toBe(false)
  })

  it('se détache de la fenêtre quand le composant s’en va', async () => {
    const liste = listeFactice(false)
    vi.stubGlobal('matchMedia', () => liste)
    const recu: { valeur: Readonly<Ref<boolean>> | null } = { valeur: null }
    const Sonde = sonde('(width >= 64rem)', recu)
    const affichee = ref(true)

    await monterEnMemoire(defineComponent({ setup: () => () => (affichee.value ? h(Sonde) : null) }))
    expect(liste.ecouteurs.size).toBe(1)

    affichee.value = false
    await Promise.resolve()
    await new Promise((fin) => setTimeout(fin, 0))
    expect(liste.ecouteurs.size).toBe(0)
  })
})
