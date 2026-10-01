<script setup lang="ts">
/**
 * L'onglet Historique : ce qui a été ajouté à la fiche et ce qui y a été changé,
 * du plus récent au plus ancien.
 *
 * La moitié de la frise est déjà dans la fiche (sources, notes, documents,
 * datés). L'autre — les **révisions appliquées** — se charge ici, par
 * `lireHistoriqueDePersonne()` (§ 6.11), monté seulement quand l'onglet est
 * ouvert, comme la galerie de l'onglet Sources. La frise s'affiche **sans
 * attendre** : les ajouts d'abord, les révisions s'y rangent à leur arrivée.
 *
 * ## Le rôle choisit la route, jamais l'affichage (§ 10.4)
 *
 * La route du § 6.11 est **réservée aux membres** : un compte en attente reçoit
 * `403 regime_en_attente`. On ne l'appelle donc pas pour lui — ce serait un
 * appel dont on connaît la réponse —, et la frise dit pourquoi elle ne montre
 * que les ajouts. C'est le **choix de la route** que le rôle décide ici, pas ce
 * qu'on dessine d'une charge reçue.
 */
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { lireHistoriqueDePersonne, type FichePersonneComplete, type RevisionDeFiche } from '@/lib/api'
import { useSession } from '@/composables/useSession'
import type { CibleLoupe } from '@/components/medias/medias'
import FriseHistorique from './FriseHistorique.vue'
import { entreesDHistorique, type EtatRevisions } from './historique'

const { fiche } = defineProps<{ fiche: FichePersonneComplete }>()

const emit = defineEmits<{ agrandir: [cible: CibleLoupe] }>()

const { rattache } = useSession()

const revisions = ref<RevisionDeFiche[]>([])
const etat = ref<EtatRevisions>(rattache.value ? 'chargement' : 'reserve')
const erreur = ref<unknown>(null)

/** Le chargement en cours : changer de fiche l'abandonne, pour qu'une réponse
 *  en retard ne range pas les révisions d'une autre personne dans cette frise. */
let controleur: AbortController | null = null

async function charger(): Promise<void> {
  controleur?.abort()
  revisions.value = []
  erreur.value = null
  if (!rattache.value) {
    etat.value = 'reserve'
    return
  }
  const courant = new AbortController()
  controleur = courant
  etat.value = 'chargement'
  try {
    const historique = await lireHistoriqueDePersonne(fiche.id, { signal: courant.signal })
    if (courant.signal.aborted) return
    revisions.value = historique.elements
    etat.value = historique.complet ? 'pret' : 'tronque'
  } catch (cause) {
    if (courant.signal.aborted) return
    erreur.value = cause
    etat.value = 'erreur'
  }
}

watch(() => fiche.id, charger, { immediate: true })
onBeforeUnmount(() => controleur?.abort())

const entrees = computed(() => entreesDHistorique(fiche, revisions.value))
</script>

<template>
  <FriseHistorique
    :entrees="entrees"
    :fiche-id="fiche.id"
    :etat-revisions="etat"
    :erreur="erreur"
    @agrandir="emit('agrandir', $event)"
    @reessayer="charger"
  />
</template>
