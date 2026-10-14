/**
 * **Quand `/arbre` relit son graphe** — en dehors du premier chargement et d'un dépôt.
 *
 *  1. **Un autre lecteur** (`identiteDuLecteur` change : connexion sous un autre
 *     compte, entrée ou sortie de « voir comme », incarnation). Le graphe affiché a été
 *     filtré pour l'ANCIEN lecteur : il ne doit pas rester à l'écran une seconde de
 *     plus. Jusqu'au 2026-10-14, « Quitter la vue empruntée » (`QuitterVueEmpruntee`,
 *     qui relit la session sans naviguer) laissait l'administrateur devant le graphe
 *     du membre qu'il venait de quitter. Les requêtes en vol pour l'ancien lecteur
 *     sont abandonnées, puis `nouveauLecteur()` recharge.
 *  2. **Le retour sur l'onglet** (`visibilitychange` → `visible`) : `revalider()`
 *     présente l'`ETag` gardé ; sur `304`, rien ne bouge, sinon l'arbre se redessine
 *     autour du repère du lecteur. Un arbre laissé ouvert dans un onglet la veille ne
 *     montre plus celui de la veille.
 *  3. **Le démontage** : toute requête en vol est abandonnée (`signal()`), sa réponse
 *     n'écrit plus rien.
 *
 * Sans DOM propre : le document est injecté, pour être testé sans navigateur.
 */
import { getCurrentScope, onScopeDispose, watch } from 'vue'

/** Ce qu'on lit du document : son état de visibilité, et l'événement qui le signale. */
export interface CibleDeVisibilite {
  readonly visibilityState: DocumentVisibilityState
  addEventListener(type: 'visibilitychange', ecouteur: () => void): void
  removeEventListener(type: 'visibilitychange', ecouteur: () => void): void
}

export interface OptionsDeRelecture {
  /** L'identité du lecteur (`identiteDuLecteur(session)`), relue à chaque changement de session. */
  identite: () => string | null
  /** Un autre lecteur : l'arbre du précédent est oublié, celui-ci rechargé. */
  nouveauLecteur: () => void
  /** Retour sur l'onglet : revalider en silence. */
  revalider: () => void
  /** Le document ; `null` hors navigateur. */
  document?: CibleDeVisibilite | null
}

export interface RelectureDeLArbre {
  /** Le signal des requêtes de l'arbre pour le lecteur courant : abandonné au changement de lecteur et au démontage. */
  signal: () => AbortSignal
}

export function useRelectureDeLArbre(o: OptionsDeRelecture): RelectureDeLArbre {
  let controleur = new AbortController()
  const doc = o.document === undefined ? (typeof document === 'undefined' ? null : document) : o.document

  watch(o.identite, (nouvelle, ancienne) => {
    if (nouvelle === ancienne) return
    // Ce qui est en vol l'est pour l'ancien lecteur.
    controleur.abort()
    controleur = new AbortController()
    // Plus personne (déconnexion) : la garde de route s'en charge, rien à recharger.
    if (nouvelle !== null) o.nouveauLecteur()
  })

  const surVisibilite = (): void => {
    if (doc?.visibilityState === 'visible') o.revalider()
  }
  doc?.addEventListener('visibilitychange', surVisibilite)

  if (getCurrentScope()) {
    onScopeDispose(() => {
      doc?.removeEventListener('visibilitychange', surVisibilite)
      controleur.abort()
    })
  }

  return { signal: () => controleur.signal }
}
