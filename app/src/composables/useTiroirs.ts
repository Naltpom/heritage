/**
 * L'état des deux tiroirs, partagé par l'en-tête et par les écrans.
 *
 * Il est de portée **module** et non fourni par injection : l'en-tête porte les
 * deux boutons, les écrans portent les barres d'outils, et les deux ne sont pas
 * dans la même branche de l'arbre de composants. Un `provide`/`inject` devrait
 * traverser `App.vue` et le `<RouterView>`, c'est-à-dire ajouter une plomberie
 * à tout le produit pour un bouton.
 *
 * ────────────────────────────────────────────────────────────────────
 * **`window.matchMedia` n'est touché qu'à la première ouverture.** Ni à
 * l'évaluation du module, ni dans `setup()`. Les composants de ce produit sont
 * rendus côté serveur dans les tests — environnement node, sans `window` — et
 * un accès au montage ferait tomber toutes les suites de rendu d'un coup.
 * Attendre la première ouverture est aussi la bonne logique : il n'y a rien à
 * refermer avant.
 * ────────────────────────────────────────────────────────────────────
 */
import {
  computed,
  onBeforeUnmount,
  onMounted,
  readonly,
  ref,
  type ComputedRef,
  type Ref,
} from 'vue'
import {
  REQUETE_MOBILE,
  basculer,
  creerEtatTiroirs,
  declarerOutils,
  fermer,
  retirerOutils,
  surveillerLargeur,
  type TiroirOuvert,
} from './tiroirs'

const etat = creerEtatTiroirs()
const ouvert = ref<TiroirOuvert>('aucun')
const barres = ref(0)

let detacher: (() => void) | null = null

function synchroniser(): void {
  ouvert.value = etat.ouvert
  barres.value = etat.barres
}

/** Branché à la première ouverture seulement — voir l'en-tête. */
function surveiller(): void {
  if (detacher !== null) return
  if (typeof globalThis.matchMedia !== 'function') return

  detacher = surveillerLargeur(globalThis.matchMedia(REQUETE_MOBILE), (mobile) => {
    if (!mobile) {
      fermer(etat)
      synchroniser()
    }
  })
}

export interface Tiroirs {
  ouvert: Readonly<Ref<TiroirOuvert>>
  outilsDisponibles: ComputedRef<boolean>
  basculerTiroir: (cible: Exclude<TiroirOuvert, 'aucun'>) => void
  fermerTiroirs: () => void
  /** Un tiroir est-il ouvert ? Les écrans s'en servent pour céder la touche
   *  Échap : elle doit refermer le tiroir avant de désélectionner quoi que ce
   *  soit derrière lui. */
  unTiroirEstOuvert: () => boolean
}

export function useTiroirs(): Tiroirs {
  return {
    ouvert: readonly(ouvert),
    outilsDisponibles: computed(() => barres.value > 0),
    basculerTiroir: (cible) => {
      surveiller()
      basculer(etat, cible)
      synchroniser()
    },
    fermerTiroirs: () => {
      fermer(etat)
      synchroniser()
    },
    unTiroirEstOuvert: () => etat.ouvert !== 'aucun',
  }
}

/** Appelé par `TiroirOutils` au montage et au démontage. Le compteur, plutôt
 *  qu'un booléen, survit au recouvrement des deux écrans pendant une
 *  transition de route. */
export function annoncerBarreDOutils(): () => void {
  declarerOutils(etat)
  synchroniser()
  return () => {
    retirerOutils(etat)
    synchroniser()
  }
}

/** Remise à zéro entre deux tests — même raison d'être qu'`oublierEtag()` :
 *  un état de portée module survit d'un cas au suivant. */
export function reinitialiserTiroirs(): void {
  etat.ouvert = 'aucun'
  etat.barres = 0
  detacher?.()
  detacher = null
  synchroniser()
}

/**
 * La largeur courante, sous forme réactive — `true` en deçà du seuil mobile.
 *
 * Elle emploie `REQUETE_MOBILE`, donc **la chaîne exacte** que Tailwind compile
 * pour `max-md:` : CSS et JavaScript ne peuvent pas diverger.
 *
 * `false` hors navigateur, ce qui est le bon défaut : les composants de ce
 * produit sont rendus côté serveur dans les tests, et un rendu SSR doit donner
 * la forme large — celle qui ne déplace rien.
 *
 * ────────────────────────────────────────────────────────────────────
 * **La valeur est lue à la construction, pas au montage.**
 *
 * Elle l'était dans `onMounted`, et cela veut dire quelque chose de précis :
 * dans un navigateur étroit, le **premier rendu** donnait la forme large, puis
 * un second rendu la corrigeait. Toute structure qui dépend de ce drapeau —
 * un `v-if`, et jadis un `<Teleport>` — changeait donc de forme juste après le
 * montage, pour rien. Sur téléphone, c'est cette bascule-là qui cassait le
 * rendu en production.
 *
 * `matchMedia` n'a pas besoin du DOM monté : c'est une propriété de la fenêtre.
 * On la lit donc tout de suite, et le premier rendu est déjà le bon. Le garde
 * `typeof … !== 'function'` couvre le rendu SSR des tests, où il n'y a pas de
 * fenêtre du tout.
 * ────────────────────────────────────────────────────────────────────
 */
export function useEstMobile(): Readonly<Ref<boolean>> {
  const disponible = typeof globalThis.matchMedia === 'function'
  const liste = disponible ? globalThis.matchMedia(REQUETE_MOBILE) : null
  const estMobile = ref(liste?.matches ?? false)

  onMounted(() => {
    if (liste === null) return
    // Relu une fois : entre la construction et le montage, une image peut
    // s'être chargée et faire apparaître une barre de défilement.
    estMobile.value = liste.matches
  })

  if (liste !== null) {
    const detacherCelui = surveillerLargeur(liste, (mobile) => {
      estMobile.value = mobile
    })
    onBeforeUnmount(detacherCelui)
  }

  return readonly(estMobile)
}
