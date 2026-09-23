/*
 * Le service worker de Heritage — il rend l'application INSTALLABLE, et c'est tout.
 *
 * Il ne met RIEN en cache : ni les pages, ni l'API. Les données de l'arbre
 * suivent le cercle de visibilité du lecteur, et une copie laissée sur le
 * téléphone survivrait à une déconnexion ou à un changement de compte. Le
 * navigateur garde son propre cache HTTP, réglé par nginx (`no-cache` sur
 * index.html, cache long sur /assets empreintés) : c'est suffisant.
 *
 * Seule faveur : hors connexion, une navigation reçoit une page qui le dit,
 * plutôt que l'écran d'erreur du navigateur.
 */
self.addEventListener('install', () => self.skipWaiting())
self.addEventListener('activate', (e) => e.waitUntil(self.clients.claim()))

const HORS_LIGNE = `<!doctype html><html lang="fr"><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Heritage — hors connexion</title><body style="margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:#141517;color:#e8e6e2;font:16px system-ui,sans-serif;text-align:center;padding:24px"><div><p style="font-weight:600">Pas de connexion</p><p style="color:#9a948c">Heritage a besoin d'internet pour afficher l'arbre. Réessayez dès que le réseau revient.</p></div></body></html>`

self.addEventListener('fetch', (e) => {
  if (e.request.mode !== 'navigate') return
  e.respondWith(
    fetch(e.request).catch(
      () => new Response(HORS_LIGNE, { status: 503, headers: { 'Content-Type': 'text/html; charset=utf-8' } }),
    ),
  )
})
