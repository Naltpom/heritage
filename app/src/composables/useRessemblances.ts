/**
 * **Les ressemblances d'une saisie**, cherchées pendant qu'on tape — le formulaire
 * « Ajouter une personne » s'en sert pour dire « est-ce déjà… ? » avant qu'on
 * dépose un doublon.
 *
 * Le modèle est celui de `SelecteurPersonne.vue` (délai, `AbortController`, deux
 * appels en parallèle), et pour les mêmes raisons :
 *
 * - **400 ms de calme avant d'appeler.** On tape un nom lettre à lettre ; appeler à
 *   chaque frappe ferait dix requêtes pour une réponse, et le serveur les
 *   compterait toutes. Le délai est plus long que celui d'une recherche (250 ms) :
 *   ici rien ne s'attend sous le doigt, la réponse vient se poser à côté.
 * - **Une frappe annule ce qui part** : le minuteur, et la requête en vol.
 * - **Un jeton contre les réponses tardives.** Une requête annulée peut encore
 *   répondre — l'annulation est un souhait, pas une garantie — et une réponse plus
 *   ancienne n'écrit jamais par-dessus une plus récente.
 * - **Deux appels, `allSettled`.** L'arbre et les propositions n'ont rien à
 *   s'apprendre : l'échec de l'un ne vide pas l'autre. Celui qui échoue se tait —
 *   ce panneau est une aide, pas une étape, et une panne ne doit pas empêcher de
 *   proposer.
 * - **Rien sans fiche comparable** (`ficheDeComparaison`), et **rien si le critère
 *   n'a pas changé** : la note, les liens, les photos et la case « décès attesté »
 *   ne relancent pas la recherche.
 *
 * **Éteint** quand `actif()` est faux : sous la bascule « voir comme un membre »
 * — « C'est elle » y serait un bouton qui ne peut rien écrire — et, plus tard, en
 * mode complétion d'une proposition, où l'on complète une fiche au lieu d'en
 * chercher une. Éteint, il oublie ce qu'il montrait.
 */
import { computed, onScopeDispose, ref, shallowRef, watch } from 'vue'
import {
  chercherRessemblancesArbre,
  chercherRessemblancesBrouillons,
  LIMITE_DES_RESSEMBLANCES,
  type RessemblanceDansLArbre,
  type RessemblanceProposition,
} from '@/lib/api'
import type { SaisieBrouillon } from '@/components/proposition/creation'
import { cleDeComparaison, ficheDeComparaison } from '@/components/proposition/ressemblances'

/** Le calme attendu avant d'appeler, en millisecondes. */
export const DELAI_DES_RESSEMBLANCES_MS = 400

export interface OptionsRessemblances {
  /** Faux sous la bascule « voir comme un membre », et en mode complétion. */
  actif: () => boolean
  /** La demande à ne pas parcourir — celle qu'on complète. `null` en création. */
  sauf?: () => number | null
}

export function useRessemblances(brouillon: () => SaisieBrouillon, options: OptionsRessemblances) {
  const fiche = computed(() => ficheDeComparaison(brouillon()))

  /**
   * Ce qui déclenche un appel, en une chaîne : un `watch` sur une chaîne ne se
   * réveille que si elle **change** — taper la note laisse la même chaîne, donc
   * aucun appel.
   */
  const declencheur = computed<string | null>(() => {
    if (!options.actif() || fiche.value === null) return null
    return `${cleDeComparaison(fiche.value)}|${options.sauf?.() ?? ''}`
  })

  const arbre = shallowRef<RessemblanceDansLArbre[]>([])
  const propositions = shallowRef<RessemblanceProposition[]>([])
  /** Les clés écartées d'un « Ce n'est pas elle » (`cleDeRessemblance`). */
  const ecartees = ref<string[]>([])
  const enCours = ref(false)

  let minuterie: ReturnType<typeof setTimeout> | null = null
  let controleur: AbortController | null = null
  /** Le dernier appel lancé : une réponse d'un appel plus ancien n'écrit rien. */
  let jeton = 0

  function annuler(): void {
    if (minuterie !== null) clearTimeout(minuterie)
    minuterie = null
    controleur?.abort()
    controleur = null
    jeton++
    enCours.value = false
  }

  function vider(): void {
    arbre.value = []
    propositions.value = []
  }

  async function chercher(): Promise<void> {
    minuterie = null
    const comparee = fiche.value
    if (comparee === null) return
    const monJeton = ++jeton
    const c = new AbortController()
    controleur = c
    enCours.value = true

    const [deLArbre, desPropositions] = await Promise.allSettled([
      chercherRessemblancesArbre(comparee, LIMITE_DES_RESSEMBLANCES, c.signal),
      chercherRessemblancesBrouillons(comparee, { limite: LIMITE_DES_RESSEMBLANCES, sauf: options.sauf?.() ?? null }, c.signal),
    ])
    if (monJeton !== jeton) return

    // Celui qui échoue se vide — ses résultats d'avant parlaient d'une autre
    // saisie —, l'autre garde les siens.
    arbre.value = deLArbre.status === 'fulfilled' ? deLArbre.value.elements : []
    propositions.value = desPropositions.status === 'fulfilled' ? desPropositions.value.elements : []
    controleur = null
    enCours.value = false
  }

  watch(declencheur, (cle) => {
    annuler()
    if (cle === null) {
      vider()
      return
    }
    // Les résultats d'avant restent à l'écran jusqu'à la réponse : le panneau ne
    // clignote pas à chaque lettre.
    minuterie = setTimeout(() => void chercher(), DELAI_DES_RESSEMBLANCES_MS)
  })

  // Démonté (la page quittée, le volet détruit) : rien ne reste en vol.
  onScopeDispose(annuler)

  /** « Ce n'est pas elle » : la carte s'en va, pour cette saisie. */
  function ecarter(cle: string): void {
    if (!ecartees.value.includes(cle)) ecartees.value = [...ecartees.value, cle]
  }

  /** Une saisie neuve : rien en vol, rien à l'écran, rien d'écarté. */
  function reinitialiser(): void {
    annuler()
    vider()
    ecartees.value = []
  }

  return { arbre, propositions, ecartees, enCours, ecarter, reinitialiser }
}
