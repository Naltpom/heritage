/**
 * Ce que l'en-tête doit savoir de l'écran courant — sans jamais lire la route.
 *
 * `EnTeteApplication` n'a pas le droit d'appeler `useRoute()` : ses tests
 * simulent `vue-router` avec `useRouter` seul. Le routeur publie donc ici, à
 * chaque navigation (`router/index.ts`, `afterEach`), la **section** active
 * (l'onglet allumé), le **titre** de l'écran et, pour un écran de détail, le
 * **parent** vers lequel revenir. L'écran, lui, peut ajouter un **résumé**
 * (« 467 personnes · 9 générations ») et le **nombre de filtres actifs**, que le
 * bouton filtres du mobile affiche en pastille.
 *
 * État de portée module, comme `useTiroirs` : l'en-tête et les écrans ne sont
 * pas dans la même branche de l'arbre de composants.
 */
import { readonly, ref } from 'vue'

/** Les quatre entrées de la navigation (barre du bas, onglets du bureau),
 *  plus les zones qui n'en allument aucune.
 *
 *  `administration` est le **mode administration** (handoff « mode
 *  Administration », 1c) : toute route `administration.*`. L'en-tête passe
 *  alors en fond accent et la navigation montre les quatre entrées de
 *  l'administration à la place des quatre du membre (`composables/modeAdmin.ts`). */
export type Section = 'accueil' | 'arbre' | 'explorer' | 'moi' | 'administration' | 'autre'

export interface ParentDeDetail {
  /** Nom de route du parent. */
  nom: string
  /** Libellé du retour : « ‹ Arbre ». */
  libelle: string
}

const section = ref<Section>('autre')
/** Le nom de la route courante. Seul le mode administration s'en sert : ses
 *  quatre entrées partagent une section, l'entrée allumée se lit au nom. */
const nomDeRoute = ref('')
const titre = ref('')
const resume = ref('')
const parent = ref<ParentDeDetail | null>(null)
const filtresActifs = ref(0)
const rechercheLocale = ref(false)

/** Appelé par le routeur à chaque navigation. Remet à zéro ce que l'écran avait publié. */
export function definirPage(p: {
  section: Section
  titre: string
  parent: ParentDeDetail | null
  /** L'écran a sa propre recherche sur téléphone : l'en-tête n'affiche pas sa loupe. */
  rechercheLocale?: boolean
  /** Le nom de la route (`administration.utilisateurs`). */
  nom?: string
}): void {
  section.value = p.section
  nomDeRoute.value = p.nom ?? ''
  titre.value = p.titre
  parent.value = p.parent
  rechercheLocale.value = p.rechercheLocale ?? false
  resume.value = ''
  filtresActifs.value = 0
}

/** L'écran publie son résumé, sous le titre, sur mobile. */
export function definirResume(texte: string): void {
  resume.value = texte
}

/** L'écran publie le nombre de ses filtres actifs (pastille du bouton filtres). */
export function publierFiltresActifs(n: number): void {
  filtresActifs.value = Math.max(0, Math.floor(n))
}

export function useEntete() {
  return {
    section: readonly(section),
    nomDeRoute: readonly(nomDeRoute),
    titre: readonly(titre),
    resume: readonly(resume),
    parent: readonly(parent),
    filtresActifs: readonly(filtresActifs),
    rechercheLocale: readonly(rechercheLocale),
  }
}

/** Remise à zéro entre deux tests. */
export function reinitialiserEntete(): void {
  definirPage({ section: 'autre', titre: '', parent: null })
}
