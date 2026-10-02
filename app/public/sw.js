/*
 * Le service worker de Heritage — il rend l'application INSTALLABLE, et il
 * affiche les notifications PUSH. Rien d'autre.
 *
 * Il ne met RIEN en cache : ni les pages, ni l'API. Les données de l'arbre
 * suivent le cercle de visibilité du lecteur, et une copie laissée sur le
 * téléphone survivrait à une déconnexion ou à un changement de compte. Le
 * navigateur garde son propre cache HTTP, réglé par nginx (`no-cache` sur
 * index.html, cache long sur /assets empreintés) : c'est suffisant.
 *
 * Seule faveur : hors connexion, une navigation reçoit une page qui le dit,
 * plutôt que l'écran d'erreur du navigateur.
 *
 * LE PUSH (contrat partagé avec l'API, `NotificationService::notifier()`) :
 *
 * - 'push' : la charge est `{id, titre, corps, linkRoute, linkParams, kind}`.
 *   Chaque push DOIT afficher une notification (`userVisibleOnly: true`) :
 *   une charge illisible en affiche donc une générique plutôt que rien.
 *   Le `tag` par identifiant évite les doublons si le même envoi arrive deux
 *   fois. Les fenêtres ouvertes sont prévenues pour relire la cloche.
 * - 'notificationclick' : on ouvre `/notification/<id>?r=…&p=…`, la route
 *   `notification.ouvrir` du front, qui marque la notification lue puis
 *   résout le lien par son NOM de route (`lienDeNotification`). Le service
 *   worker ne construit donc aucune URL d'écran : une refonte du routage ne
 *   casse pas les notifications déjà reçues. Le chemin `/notification/:id`
 *   est, lui, un contrat : ne pas le renommer.
 * - 'pushsubscriptionchange' : le navigateur a fait tourner l'abonnement ; on
 *   renvoie le nouveau au serveur (le cookie de session part avec, même
 *   origine). Complément seulement : l'application refait ce travail à chaque
 *   démarrage (`usePush.ts`), car tous les navigateurs n'émettent pas cet
 *   événement.
 *
 * Ce fichier est servi à une adresse FIXE (`/sw.js`, portée `/`) : l'abonnement
 * push appartient à cet enregistrement, et survit donc au remplacement du
 * script à chaque mise en production.
 */
self.addEventListener('install', () => self.skipWaiting())
self.addEventListener('activate', (e) => e.waitUntil(self.clients.claim()))

const HORS_LIGNE = `<!doctype html><html lang="fr"><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Heritage — hors connexion</title><body style="margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:#141517;color:#e8e6e2;font:16px system-ui,sans-serif;text-align:center;padding:24px"><div><p style="font-weight:600">Pas de connexion</p><p style="color:#9a948c">Heritage a besoin d'internet pour afficher l'arbre. Réessayez dès que le réseau revient.</p></div></body></html>`

/*
 * Le `fetch` ne touche QUE les navigations. Tout le reste — l'API, les actifs,
 * et surtout les VIDÉOS (`/videos/*.mp4`) — n'est pas intercepté du tout :
 * pas de `respondWith`, le navigateur fait sa requête comme sans service worker.
 * C'est ce qui garde intactes les requêtes partielles (`Range: bytes=…` →
 * `206`) dont un lecteur vidéo vit : un `respondWith(fetch(e.request))` les
 * relaierait, et Safari, notamment, lit mal une vidéo dont les réponses
 * partielles passent par un service worker.
 *
 * Les vidéos sont aussi écartées NOMMÉMENT, même en navigation (un lien direct
 * vers un `.mp4` ouvert dans un onglet) : rien ne doit jamais les relayer.
 */
self.addEventListener('fetch', (e) => {
  if (e.request.mode !== 'navigate') return
  if (new URL(e.request.url).pathname.startsWith('/videos/')) return
  e.respondWith(
    fetch(e.request).catch(
      () => new Response(HORS_LIGNE, { status: 503, headers: { 'Content-Type': 'text/html; charset=utf-8' } }),
    ),
  )
})

/* ------------------------------------------------------------------ */
/* Push                                                                */
/* ------------------------------------------------------------------ */

const ICONE = '/icons/icone-192.png'

/** L'adresse que le clic ouvre. Sans `linkRoute`, ni `r` ni `p` : la route
 *  d'ouverture retombe alors sur l'accueil. */
function adresseDeNotification(charge) {
  let url = '/notification/' + encodeURIComponent(String(charge.id))
  if (typeof charge.linkRoute === 'string' && charge.linkRoute.length > 0) {
    url += '?r=' + encodeURIComponent(charge.linkRoute)
    if (charge.linkParams !== null && typeof charge.linkParams === 'object') {
      url += '&p=' + encodeURIComponent(JSON.stringify(charge.linkParams))
    }
  }
  return url
}

self.addEventListener('push', (e) => {
  let charge = null
  try {
    charge = e.data ? e.data.json() : null
  } catch {
    charge = null
  }

  const valide = charge !== null && typeof charge === 'object' && Number.isInteger(charge.id)
  const titre = valide && typeof charge.titre === 'string' && charge.titre ? charge.titre : 'Heritage'
  const options = {
    body: valide && typeof charge.corps === 'string' ? charge.corps : 'Vous avez une nouvelle notification.',
    icon: ICONE,
    badge: ICONE,
    data: { url: valide ? adresseDeNotification(charge) : '/' },
  }
  if (valide) options.tag = 'notification-' + charge.id

  e.waitUntil(
    Promise.all([
      self.registration.showNotification(titre, options),
      // L'application ouverte relit son compteur tout de suite.
      self.clients
        .matchAll({ type: 'window', includeUncontrolled: true })
        .then((fenetres) => fenetres.forEach((f) => f.postMessage({ type: 'heritage:notification' })))
        .catch(() => undefined),
    ]),
  )
})

self.addEventListener('notificationclick', (e) => {
  e.notification.close()
  const url = new URL((e.notification.data && e.notification.data.url) || '/', self.location.origin).href

  e.waitUntil(
    (async () => {
      const fenetres = await self.clients.matchAll({ type: 'window', includeUncontrolled: true })
      const fenetre = fenetres.find((f) => new URL(f.url).origin === self.location.origin)
      if (fenetre) {
        try {
          await fenetre.focus()
          await fenetre.navigate(url)
          return
        } catch {
          // Fenêtre non contrôlée ou fermée entre-temps : on en ouvre une.
        }
      }
      await self.clients.openWindow(url)
    })(),
  )
})

self.addEventListener('pushsubscriptionchange', (e) => {
  e.waitUntil(
    (async () => {
      let abonnement = e.newSubscription || null
      if (!abonnement && e.oldSubscription && e.oldSubscription.options) {
        abonnement = await self.registration.pushManager.subscribe(e.oldSubscription.options)
      }
      if (!abonnement) return
      const json = abonnement.toJSON()
      await fetch('/api/push/abonnement', {
        method: 'PUT',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify({ endpoint: json.endpoint, keys: json.keys }),
      })
    })().catch(() => undefined),
  )
})
