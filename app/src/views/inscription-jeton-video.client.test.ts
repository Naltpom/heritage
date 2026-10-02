/**
 * L'écran du lien d'invitation, « Commencer » et la vidéo « Bienvenue » —
 * **montés et cliqués** (`test-utils/rendu-memoire.ts`).
 *
 * Ce que ces cas tiennent, parce que c'est ce qui se casserait sans bruit :
 *
 * - rien ne s'ouvre de soi-même : l'écran ne propose qu'un geste, « Commencer » ;
 * - **la première fois**, « Commencer » lance la vidéo (un clic : elle peut
 *   parler) ; **à la fin, à la croix ou sur un échec**, on part au formulaire
 *   de rattachement, sans repasser par cet écran ;
 * - **une fois par navigateur** : la marque `heritage.videos.bienvenue.vue`
 *   est posée quand le fichier s'est chargé — pas à l'ouverture, pour qu'une
 *   vidéo absente ne « consomme » pas la première fois — et, la marque posée,
 *   « Commencer » va droit au formulaire ;
 * - un lien refusé n'offre aucun bouton.
 *
 * Le cadre d'entrée est remplacé par une boîte nue : il branche le clavier du
 * téléphone sur de vrais éléments, que ce rendu sans DOM n'a pas.
 */
import { defineComponent, h } from 'vue'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { boutonNomme, cliquer, monterEnMemoire, parAttribut, un, attendre, type Noeud } from '@/test-utils/rendu-memoire'

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

const FORMULAIRE = [{ name: 'inscription.rattachement' }]

describe('« Commencer » et la vidéo « Bienvenue » sur l’écran du lien d’invitation', () => {
  it('ne lance rien d’elle-même : un seul bouton, « Commencer »', async () => {
    const racine = await monter()

    expect(lecteur(racine)).toBeNull()
    expect(un(racine, boutonNomme('Commencer'))).not.toBeNull()
    expect(un(racine, parAttribut('data-revoir-video'))).toBeNull()
  })

  it('lance la vidéo au premier « Commencer »', async () => {
    const racine = await monter()

    await cliquer(un(racine, boutonNomme('Commencer')))

    expect(lecteur(racine)?.attributs['data-video']).toBe('bienvenue')
    expect(etat.push).toEqual([])
  })

  it('n’offre aucun bouton sur un lien refusé', async () => {
    etat.verifierJeton = () => Promise.reject(new ErreurApi(404, 'invitation_invalide', 'Ce lien n’est plus valable.'))
    const racine = await monter()

    expect(lecteur(racine)).toBeNull()
    expect(un(racine, boutonNomme('Commencer'))).toBeNull()
  })

  it('mène au formulaire à la fin de la vidéo', async () => {
    const racine = await monter()
    await cliquer(un(racine, boutonNomme('Commencer')))

    await evenementVideo(racine, 'ended')
    await new Promise((fin) => setTimeout(fin, 360))
    await attendre()

    expect(lecteur(racine)).toBeNull()
    expect(etat.push).toEqual(FORMULAIRE)
  })

  it('mène au formulaire dès la croix', async () => {
    const racine = await monter()
    await cliquer(un(racine, boutonNomme('Commencer')))

    await cliquer(un(racine, parAttribut('data-fermer-video')))

    expect(lecteur(racine)).toBeNull()
    expect(etat.push).toEqual(FORMULAIRE)
  })

  it('mène au formulaire si la vidéo ne charge pas, sans la retenir « vue »', async () => {
    const racine = await monter()
    await cliquer(un(racine, boutonNomme('Commencer')))

    await evenementVideo(racine, 'error')

    expect(etat.push).toEqual(FORMULAIRE)
    expect(donnees[CLE_BIENVENUE_VUE]).toBeUndefined()
  })

  it('retient « déjà vue » quand la vidéo s’est chargée — pas avant', async () => {
    const racine = await monter()
    await cliquer(un(racine, boutonNomme('Commencer')))
    expect(donnees[CLE_BIENVENUE_VUE]).toBeUndefined()

    await evenementVideo(racine, 'loadedmetadata')

    expect(donnees[CLE_BIENVENUE_VUE]).toBe('1')
  })

  it('va droit au formulaire sur un navigateur qui l’a déjà montrée', async () => {
    donnees[CLE_BIENVENUE_VUE] = '1'
    const racine = await monter()

    await cliquer(un(racine, boutonNomme('Commencer')))

    expect(lecteur(racine)).toBeNull()
    expect(etat.push).toEqual(FORMULAIRE)
  })

  it('ne lève pas quand le stockage du navigateur refuse tout, et mène au formulaire', async () => {
    vi.stubGlobal('localStorage', {
      getItem: () => {
        throw new Error('SecurityError')
      },
      setItem: () => {
        throw new Error('SecurityError')
      },
    })
    const racine = await monter()

    // Sans mémoire, on ne sait pas si elle a été vue : on ne l'impose pas.
    await cliquer(un(racine, boutonNomme('Commencer')))
    expect(etat.push).toEqual(FORMULAIRE)
  })
})
