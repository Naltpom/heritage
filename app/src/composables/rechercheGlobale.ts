/**
 * La recherche de l'en-tête, partagée avec l'écran qui sait « voler » vers une
 * personne.
 *
 * L'en-tête porte le champ et appelle l'API (`rechercherPersonnes`, jamais un
 * index local). Il publie ici les résultats et le curseur. Un écran capable de
 * montrer les personnes sur place — l'arbre — **s'abonne** : il met en évidence
 * les résultats, cadre sa vue sur eux et reçoit le choix final. Sans abonné, le
 * choix ouvre la fiche.
 */
import { readonly, ref, shallowRef } from 'vue'
import type { ResultatDeRecherche } from '@/lib/api'

const resultats = shallowRef<ResultatDeRecherche[]>([])
const curseur = ref(0)
/** Le texte tapé, pour qu'un écran abonné sache quand la recherche est vide. */
const requete = ref('')

type Choix = (r: ResultatDeRecherche) => void
let abonne: Choix | null = null

export function publierResultats(texte: string, liste: ResultatDeRecherche[]): void {
  requete.value = texte
  resultats.value = liste
  curseur.value = 0
}

export function publierCurseur(i: number): void {
  curseur.value = i
}

/** Rend `true` si un écran a pris le choix en charge. */
export function transmettreChoix(r: ResultatDeRecherche): boolean {
  if (!abonne) return false
  abonne(r)
  return true
}

/** L'arbre s'abonne au montage et se désabonne au démontage. */
export function abonnerChoix(f: Choix): () => void {
  abonne = f
  return () => {
    if (abonne === f) abonne = null
  }
}

export function aUnAbonne(): boolean {
  return abonne !== null
}

export function useRechercheGlobale() {
  return { resultats: readonly(resultats), curseur: readonly(curseur), requete: readonly(requete) }
}
