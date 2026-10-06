/**
 * Le routeur.
 *
 * **Mode historique**, et non hash : il va de pair avec le
 * `try_files $uri $uri/ /index.html` de `nginx.conf` et avec le repli du serveur
 * de développement de Vite. Retirer l'un casse l'autre — et un lien d'invitation
 * ou un lien de notification collé dans un navigateur vierge est justement une
 * route profonde, pas un cas limite.
 *
 * **La table des routes est dans `routes.ts`**, et ce fichier ne porte plus que
 * l'instance et les deux gardes. Le partage n'est pas cosmétique :
 * `createWebHistory()` lit `window.location` au chargement du module, donc tout
 * ce qui vit ici est hors de portée d'un test. La table, elle — la liste des
 * écrans, qui peut les ouvrir, vers où partent les refus —, est précisément ce
 * qu'il faut pouvoir vérifier.
 *
 * `routes` est réexportée ci-dessous pour que rien de ce qui l'importait ne
 * casse ; un test, lui, importe `@/router/routes` directement, sans réveiller
 * `createWebHistory()`.
 *
 * Le rattrapage des écrans disparus après une mise en production
 * (`routeur.onError`, `vite:preloadError`, un rechargement gardé) n'est pas
 * ici : il vit dans `rechargement.ts`, éprouvé sur un routeur en mémoire, et
 * `main.ts` le branche avant la navigation initiale.
 */

import { createRouter, createWebHistory } from 'vue-router'
import { assurerSessionChargee, useSession } from '@/composables/useSession'
import { regimeDeSession, resoudreAcces, routeParDefaut } from './acces'
// La table vit dans `routes.ts` : elle y est importable par un test, alors
// que ce fichier-ci appelle `createWebHistory()` dès son chargement.
import { routes, sectionDe, titreDEntete } from './routes'
import { definirPage } from '@/composables/entete'
import { useTiroirs } from '@/composables/useTiroirs'

export { routes }

const routeur = createRouter({
  history: createWebHistory(),
  routes,
  // Revenir en haut à chaque écran, sauf retour arrière : le canevas de l'arbre
  // gère son propre cadrage, il ne dépend pas du défilement de la page.
  scrollBehavior(_vers, _de, position) {
    return position ?? { top: 0 }
  },
})

routeur.beforeEach(async (vers) => {
  // La garde ne décide rien tant que la session n'est pas connue. L'appel est
  // mémoïsé : un seul `GET /api/session` pour toute la vie de l'onglet.
  await assurerSessionChargee()
  const { session } = useSession()
  const regime = regimeDeSession(session.value)

  if (vers.name === 'racine') return routeParDefaut(regime)

  const decision = resoudreAcces(vers.meta.acces, regime, vers.fullPath)
  return decision.autorise ? true : decision.redirection
})

const { fermerTiroirs: fermerLesTiroirs } = useTiroirs()

routeur.afterEach((vers) => {
  const titre = typeof vers.meta.titre === 'string' ? vers.meta.titre : ''
  document.title = titre.length > 0 ? `${titre} — Heritage` : 'Heritage'

  // Ce que l'en-tête affiche de l'écran : il ne lit pas la route lui-même.
  definirPage({
    section: sectionDe(String(vers.name ?? ''), vers.meta),
    // Dans Explorer, « Explorer » : la rangée d'onglets nomme déjà l'écran.
    titre: titreDEntete(String(vers.name ?? ''), vers.meta),
    parent: vers.meta.parent ?? null,
  })

  // Les tiroirs du mobile se referment en changeant de page. C'est ici et non
  // dans l'en-tête : `entete-rendu.test.ts` simule `vue-router` en n'en
  // exportant que `useRouter`, et un `useRoute()` dans l'en-tête ferait lever
  // tous ses cas d'un coup. C'est aussi le bon endroit — refermer un tiroir
  // parce qu'on a navigué est une affaire de navigation.
  fermerLesTiroirs()
})

export default routeur
