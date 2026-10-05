/**
 * **La portée des onglets d'Explorer** — décision du 05/10/2026.
 *
 * Chronologie, Carte, Calendrier et Stats ne montrent que l'arbre du lecteur :
 * la famille reliée à sa fiche, sans les personnes sans lien connu. Seule
 * l'administration a une bascule « Mon arbre / Toute la base », posée une fois
 * au-dessus des onglets et partagée par les quatre — changer d'onglet ne la
 * remet pas à zéro. Elle est retenue par ce navigateur (une commodité : sans
 * stockage, on repart sur « Mon arbre »).
 *
 * Pour un membre, la portée vaut toujours `arbre` ; le serveur la refuserait de
 * toute façon (il répond `arbre` à un `tout` qui ne vient pas d'un administrateur).
 */
import { computed, ref, type ComputedRef } from 'vue'
import { useSession } from '@/composables/useSession'
import type { PorteeExplorer } from '@/lib/api'

const CLE = 'heritage.explorer.portee'

function lire(): PorteeExplorer {
  try {
    return globalThis.localStorage?.getItem(CLE) === 'tout' ? 'tout' : 'arbre'
  } catch {
    return 'arbre'
  }
}

const choisie = ref<PorteeExplorer>(lire())

/** Les deux portées de la bascule, dans l'ordre affiché. */
export const PORTEES = [
  { valeur: 'arbre', libelle: 'Mon arbre', titre: 'Seulement la famille reliée à ma fiche' },
  { valeur: 'tout', libelle: 'Toute la base', titre: 'Toutes les fiches, personnes sans lien connu comprises' },
] as const satisfies readonly { valeur: PorteeExplorer; libelle: string; titre: string }[]

/** La portée effective : celle choisie pour un administrateur, `arbre` pour tout autre. */
export function porteeEffective(administrateur: boolean, choix: PorteeExplorer): PorteeExplorer {
  return administrateur ? choix : 'arbre'
}

export function usePorteeExplorer(): {
  portee: ComputedRef<PorteeExplorer>
  bascule: ComputedRef<boolean>
  changerPortee: (valeur: PorteeExplorer) => void
} {
  const { administrateur } = useSession()

  return {
    portee: computed(() => porteeEffective(administrateur.value, choisie.value)),
    bascule: computed(() => administrateur.value),
    changerPortee(valeur: PorteeExplorer): void {
      choisie.value = valeur
      try {
        globalThis.localStorage?.setItem(CLE, valeur)
      } catch {
        // Stockage indisponible (navigation privée) : la bascule vaut pour la session.
      }
    },
  }
}
