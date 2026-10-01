/**
 * L'installation de Heritage sur l'écran d'accueil.
 *
 * - **Android / Chrome, Edge** : le navigateur émet `beforeinstallprompt` quand
 *   l'application est installable. On le RETIENT (sinon il montre sa propre
 *   bannière au mauvais moment) et on le rejoue au clic sur « Installer ».
 * - **iPhone / Safari** : pas d'événement ; l'installation passe par
 *   « Partager → Sur l'écran d'accueil ». On l'explique.
 * - **Déjà installée** (`display-mode: standalone`) : rien à proposer.
 *
 * L'écouteur est posé au CHARGEMENT du module, importé par `main.ts` : l'événement
 * peut partir avant le montage du premier écran, et un écouteur posé plus tard le
 * manquerait. Rien ici ne touche `window` hors navigateur — les tests rendent
 * côté serveur.
 */
import { readonly, ref } from 'vue'

interface InvitationDInstallation extends Event {
  prompt: () => Promise<void>
  userChoice: Promise<{ outcome: 'accepted' | 'dismissed' }>
}

const invitation = ref<InvitationDInstallation | null>(null)
const installee = ref(false)

const navigateur = typeof window !== 'undefined'

if (navigateur) {
  installee.value =
    globalThis.matchMedia?.('(display-mode: standalone)').matches === true ||
    (navigator as Navigator & { standalone?: boolean }).standalone === true
  window.addEventListener('beforeinstallprompt', (e) => {
    e.preventDefault()
    invitation.value = e as InvitationDInstallation
  })
  window.addEventListener('appinstalled', () => {
    installee.value = true
    invitation.value = null
  })
}

/** iPhone ou iPad sous Safari (iPadOS se présente comme un Mac tactile). */
function surIos(): boolean {
  if (!navigateur) return false
  const ua = navigator.userAgent
  const ios = /iPhone|iPad|iPod/.test(ua) || (ua.includes('Macintosh') && navigator.maxTouchPoints > 1)
  return ios && !/CriOS|FxiOS|EdgiOS/.test(ua)
}

export function useInstallation() {
  return {
    invitation: readonly(invitation),
    installee: readonly(installee),
    ios: surIos(),
    /** Rejoue l'invitation du navigateur ; rend `true` si l'installation est acceptée. */
    async installer(): Promise<boolean> {
      const i = invitation.value
      if (!i) return false
      invitation.value = null
      await i.prompt()
      const { outcome } = await i.userChoice
      return outcome === 'accepted'
    },
  }
}

/**
 * Le service worker : il rend l'application installable **et** reçoit les
 * notifications push (voir `public/sw.js`).
 *
 * Enregistré au **démarrage**, par `main.ts`, que l'invitation d'installation
 * ait lieu ou non : le push s'appuie sur `navigator.serviceWorker.ready`
 * (`usePush.ts`), qui ne se résout qu'une fois un service worker actif.
 *
 * `updateViaCache: 'none'` : le navigateur revérifie `sw.js` sans passer par
 * son cache HTTP à chaque navigation. Une mise en production remplace donc le
 * service worker dès la visite suivante — et l'abonnement push, lui, survit au
 * remplacement : il appartient à l'**enregistrement** (même portée, même
 * adresse `/sw.js`), pas à la version du script. Ne jamais changer cette
 * adresse ni cette portée : ce serait un nouvel enregistrement, sans abonnement.
 *
 * Production seulement : en développement, un service worker resterait
 * accroché d'une session de Vite à l'autre. Le push y est donc « indisponible ».
 */
export function enregistrerServiceWorker(): void {
  if (!navigateur || !import.meta.env.PROD || !('serviceWorker' in navigator)) return
  const enregistrer = (): void => {
    navigator.serviceWorker.register('/sw.js', { scope: '/', updateViaCache: 'none' }).catch(() => {
      // Sans service worker, l'application marche ; elle n'est juste ni
      // installable ni capable de recevoir le push.
    })
  }
  // `load` : ne pas disputer la bande passante du premier écran. Si la page est
  // déjà chargée (module exécuté tard), on n'attend pas un événement passé.
  if (document.readyState === 'complete') enregistrer()
  else window.addEventListener('load', enregistrer, { once: true })
}
