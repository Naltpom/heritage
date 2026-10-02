/**
 * L'écran du lien d'invitation et la vidéo « Bienvenue » — **montés et
 * cliqués** (`test-utils/rendu-memoire.ts`).
 *
 * Ce que ces cas tiennent, parce que c'est ce qui se casserait sans bruit :
 *
 * - la vidéo s'ouvre d'elle-même **après** un jeton reconnu, et **jamais**
 *   sur un lien refusé ;
 * - **une fois par navigateur** : la marque `heritage.videos.bienvenue.vue`
 *   est posée quand le fichier s'est chargé — pas à l'ouverture, pour qu'une
 *   vidéo absente ne « consomme » pas la première fois ;
 * - à la fin, à la croix ou sur un échec, le lecteur disparaît et **l'écran
 *   d'inscription reste intact** — « Commencer » compris ;
 * - « Revoir la vidéo de présentation » la rouvre.
 *
 * Le cadre d'entrée est remplacé par une boîte nue : il branche le clavier du
 * téléphone sur de vrais éléments, que ce rendu sans DOM n'a pas.
 */
import { defineComponent, h } from 'vue'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { boutonNomme, cliquer, monterEnMemoire, parAttribut, texte, un, attendre, type Noeud } from '@/test-utils/rendu-memoire'

const etat = vi.hoisted(() => ({
  verifierJeton: null as unknown as (jeton: string) => Promise<unknown>,
  push: [] as unknown[],
}))

vi.mock('vue-router', () => ({
  useRoute: () => ({ params: { jeton: 'jeton-d-essai' }, query: {} }),
  useRouter: () => ({
    push: (cible: unknown) => {
      etat.push.push(cible)
      return Promise.resolve()
    },
  }),
}))

vi.mock('@/lib/api', async (original) => ({
  ...(await original<typeof import('@/lib/api')>()),
  verifierJeton: (jeton: string) => etat.verifierJeton(jeton),
}))

vi.mock('@/components/inscription/CadreEntree.vue', async () => {
  const { defineComponent: dc, h: hh } = await import('vue')
  return {
    default: dc({
      name: 'CadreEntree',
      setup: (_, { slots }) => () => hh('div', { 'data-cadre': '' }, [slots['default']?.(), slots['pied']?.()]),
    }),
  }
})

import { ErreurApi } from '@/lib/api'
import { CLE_BIENVENUE_VUE } from '@/lib/videos'
import InscriptionJetonView from './InscriptionJetonView.vue'

const LienFactice = defineComponent({
  name: 'RouterLink',
  props: { to: { type: [String, Object], default: '' } },
  setup: (_, { slots }) => () => h('a', { href: '#' }, slots['default']?.()),
})

/** Un `localStorage` en mémoire, remis à neuf à chaque cas. */
let donnees: Record<string, string> = {}

beforeEach(() => {
  donnees = {}
  etat.push = []
  etat.verifierJeton = () => Promise.resolve({ mode: 'recherche' })
  vi.stubGlobal('localStorage', {
    getItem: (cle: string) => donnees[cle] ?? null,
    setItem: (cle: string, valeur: string) => {
      donnees[cle] = valeur
    },
  })
})

afterEach(() => {
  vi.unstubAllGlobals()
})

const monter = (): Promise<Noeud> => monterEnMemoire(InscriptionJetonView, { RouterLink: LienFactice })
const lecteur = (racine: Noeud): Noeud | null => un(racine, parAttribut('data-lecteur-video'))

async function evenementVideo(racine: Noeud, nom: string): Promise<void> {
  const video = un(racine, (n) => n.tag === 'video')
  const ecouteur = video?.ecouteurs[nom]
  if (typeof ecouteur !== 'function') throw new Error(`Pas d'écouteur « ${nom} ».`)
  ecouteur({ target: video })
  await attendre()
}

describe('la vidéo « Bienvenue » sur l’écran du lien d’invitation', () => {
  it('s’ouvre d’elle-même une fois le jeton reconnu, par-dessus l’écran prêt', async () => {
    const racine = await monter()

    expect(lecteur(racine)?.attributs['data-video']).toBe('bienvenue')
    // L'écran est déjà là, dessous : la vidéo ne le remplace pas.
    expect(un(racine, boutonNomme('Commencer'))).not.toBeNull()
  })

  it('ne s’ouvre pas sur un lien refusé', async () => {
    etat.verifierJeton = () => Promise.reject(new ErreurApi(404, 'invitation_invalide', 'Ce lien n’est plus valable.'))
    const racine = await monter()

    expect(lecteur(racine)).toBeNull()
    expect(un(racine, parAttribut('data-revoir-video'))).toBeNull()
  })

  it('ne s’ouvre pas sur une panne du serveur', async () => {
    etat.verifierJeton = () => Promise.reject(new ErreurApi(503, 'service_indisponible', 'Réessayez.'))
    const racine = await monter()

    expect(lecteur(racine)).toBeNull()
  })

  it('ne s’ouvre plus d’elle-même sur un navigateur qui l’a déjà montrée', async () => {
    donnees[CLE_BIENVENUE_VUE] = '1'
    const racine = await monter()

    expect(lecteur(racine)).toBeNull()
    expect(un(racine, boutonNomme('Commencer'))).not.toBeNull()
  })

  it('retient « déjà vue » quand la vidéo s’est chargée — pas avant', async () => {
    const racine = await monter()
    expect(donnees[CLE_BIENVENUE_VUE]).toBeUndefined()

    await evenementVideo(racine, 'loadedmetadata')

    expect(donnees[CLE_BIENVENUE_VUE]).toBe('1')
  })

  it('ne retient rien si la vidéo ne charge pas, et laisse l’écran intact', async () => {
    const racine = await monter()

    await evenementVideo(racine, 'error')

    expect(lecteur(racine)).toBeNull()
    expect(donnees[CLE_BIENVENUE_VUE]).toBeUndefined()
    expect(un(racine, boutonNomme('Commencer'))).not.toBeNull()
  })

  it('disparaît à la croix, et « Commencer » mène toujours au rattachement', async () => {
    const racine = await monter()

    await cliquer(un(racine, parAttribut('data-fermer-video')))
    expect(lecteur(racine)).toBeNull()

    await cliquer(un(racine, boutonNomme('Commencer')))
    expect(etat.push).toEqual([{ name: 'inscription.rattachement' }])
  })

  it('disparaît à la fin de la vidéo', async () => {
    const racine = await monter()

    await evenementVideo(racine, 'ended')
    await new Promise((fin) => setTimeout(fin, 360))
    await attendre()

    expect(lecteur(racine)).toBeNull()
  })

  it('se rouvre par « Revoir la vidéo de présentation »', async () => {
    donnees[CLE_BIENVENUE_VUE] = '1'
    const racine = await monter()
    const lien = un(racine, parAttribut('data-revoir-video'))
    expect(texte(lien!).trim()).toBe('Revoir la vidéo de présentation')

    await cliquer(lien)

    expect(lecteur(racine)?.attributs['data-video']).toBe('bienvenue')
  })

  it('ne lève pas quand le stockage du navigateur refuse tout', async () => {
    vi.stubGlobal('localStorage', {
      getItem: () => {
        throw new Error('SecurityError')
      },
      setItem: () => {
        throw new Error('SecurityError')
      },
    })
    const racine = await monter()

    // Sans mémoire, pas de lecture d'office (elle reviendrait à chaque fois) ;
    // l'inscription, elle, est là.
    expect(lecteur(racine)).toBeNull()
    expect(un(racine, boutonNomme('Commencer'))).not.toBeNull()
  })
})
