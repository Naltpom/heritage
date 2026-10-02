/**
 * Le service worker (`public/sw.js`) **ne touche pas aux vidéos**.
 *
 * Un lecteur vidéo vit de requêtes partielles (`Range: bytes=…` → `206`). Un
 * service worker qui les relaierait par `respondWith(fetch(…))` les ferait
 * passer par un chemin que Safari, notamment, lit mal : la vidéo resterait
 * noire sur certains iPhone, sans une erreur dans la console du développeur
 * qui teste sur un ordinateur. La règle tient donc en deux phrases, et ces cas
 * la tiennent :
 *
 * - le `fetch` n'intercepte que les **navigations** ;
 * - et pas même une navigation vers `/videos/` (un `.mp4` ouvert dans un onglet).
 *
 * Le script est exécuté tel quel dans un bac à sable `node:vm`, avec un `self`
 * de quelques lignes : c'est le fichier servi qu'on éprouve, pas une copie.
 */
import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { runInNewContext } from 'node:vm'
import { describe, expect, it } from 'vitest'

type Ecouteur = (evenement: unknown) => void

function chargerLeServiceWorker(): Map<string, Ecouteur> {
  const ecouteurs = new Map<string, Ecouteur>()
  const self = {
    addEventListener: (nom: string, f: Ecouteur) => ecouteurs.set(nom, f),
    skipWaiting: () => Promise.resolve(),
    clients: { claim: () => Promise.resolve() },
  }
  const source = readFileSync(fileURLToPath(new URL('../../public/sw.js', import.meta.url)), 'utf8')
  runInNewContext(source, { self, URL, Response, fetch: () => Promise.resolve(new Response('')) })
  return ecouteurs
}

/** Envoie une requête au `fetch` du service worker ; vrai s'il y a répondu. */
function intercepte(requete: { url: string; mode: string; headers?: Record<string, string> }): boolean {
  const surFetch = chargerLeServiceWorker().get('fetch')
  if (surFetch === undefined) throw new Error('Le service worker n’écoute pas « fetch ».')
  let repondu = false
  surFetch({
    request: requete,
    respondWith: () => {
      repondu = true
    },
  })
  return repondu
}

describe('le service worker et les vidéos', () => {
  it('laisse passer la lecture d’une vidéo, requête partielle comprise', () => {
    expect(
      intercepte({ url: 'https://heritage.test/videos/bienvenue.mp4', mode: 'no-cors', headers: { Range: 'bytes=0-' } }),
    ).toBe(false)
  })

  it('laisse passer l’affiche et les sous-titres', () => {
    expect(intercepte({ url: 'https://heritage.test/videos/bienvenue.jpg', mode: 'no-cors' })).toBe(false)
    expect(intercepte({ url: 'https://heritage.test/videos/bienvenue.vtt', mode: 'cors' })).toBe(false)
  })

  it('laisse passer même une vidéo ouverte dans un onglet (une navigation)', () => {
    expect(intercepte({ url: 'https://heritage.test/videos/decouvrir.mp4', mode: 'navigate' })).toBe(false)
  })

  it('continue de répondre aux navigations de l’application — la page hors connexion', () => {
    expect(intercepte({ url: 'https://heritage.test/aide?video=decouvrir', mode: 'navigate' })).toBe(true)
  })
})
