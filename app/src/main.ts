/**
 * Démarrage de l'application.
 *
 * L'ordre des trois lignes qui suivent la création du routeur n'est pas
 * indifférent :
 *
 * 1. `installerRattrapageSession(routeur)` — le client d'API doit savoir où
 *    renvoyer un lecteur dont la session est tombée **avant** le premier appel.
 * 2. `assurerSessionChargee()` — la garde de route ne peut rien décider sans la
 *    session. On l'attend ici pour que le premier écran rendu soit le bon, et
 *    non l'écran de connexion remplacé une fraction de seconde plus tard : sur
 *    un lien profond (invitation, notification), ce clignotement se voit.
 * 3. `mount()`.
 *
 * L'appel de l'étape 2 est mémoïsé et ne rejette jamais : la garde le réclame
 * aussi, et il n'y aura qu'un seul `GET /api/session` pour toute la vie de
 * l'onglet.
 *
 * Deux filets se posent **avant** `mount()`, et le premier avant même
 * `use(routeur)`, qui lance la navigation initiale :
 *
 * - `installerRattrapageChargement(routeur)` (`router/rechargement.ts`) — un
 *   écran paresseux qui n'existe plus sur le serveur (mise en production
 *   pendant que l'onglet était ouvert) recharge la page **une fois** vers sa
 *   destination, au lieu d'un clic qui ne fait rien ;
 * - `installerFiletDErreurs(app, …)` (`composables/erreursGlobales.ts`) —
 *   `app.config.errorHandler` et `unhandledrejection` : ce que personne n'a
 *   attrapé est journalisé et dit, par l'avis global, sans bloquer l'écran.
 */
import { createApp } from 'vue'
import App from './App.vue'
import routeur from './router'
import { assurerSessionChargee, installerRattrapageSession } from './composables/useSession'
// Importé tôt : l'invitation d'installation peut partir avant le premier écran.
import { enregistrerServiceWorker } from './composables/installation'
import { demarrerPush } from './composables/usePush'
import { installerFiletDErreurs } from './composables/erreursGlobales'
import { installerRattrapageChargement } from './router/rechargement'
import './styles/main.css'

async function demarrer(): Promise<void> {
  installerRattrapageSession(routeur)
  const rattrapage = installerRattrapageChargement(routeur)
  enregistrerServiceWorker()

  await assurerSessionChargee()

  const app = createApp(App)
  installerFiletDErreurs(app, { signalerEchecDeChargement: rattrapage.signalerEchec })
  app.use(routeur).mount('#app')

  // 4. Le push, **après** le montage et sans l'attendre : à chaque session
  //    connectée, l'abonnement du téléphone est vérifié et renvoyé au serveur.
  //    C'est ce qui l'empêche de « casser » à une mise en production
  //    (voir l'en-tête de `usePush.ts`).
  demarrerPush()
}

void demarrer()
