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
 */
import { createApp } from 'vue'
import App from './App.vue'
import routeur from './router'
import { assurerSessionChargee, installerRattrapageSession } from './composables/useSession'
// Importé tôt : l'invitation d'installation peut partir avant le premier écran.
import { enregistrerServiceWorker } from './composables/installation'
import './styles/main.css'

async function demarrer(): Promise<void> {
  installerRattrapageSession(routeur)
  enregistrerServiceWorker()

  await assurerSessionChargee()

  createApp(App).use(routeur).mount('#app')
}

void demarrer()
