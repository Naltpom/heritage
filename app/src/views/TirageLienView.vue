<script setup lang="ts">
/**
 * `/t/<clé>` — le tirage d'un participant, ouvert par son **lien personnel**,
 * sans compte ni session.
 *
 * La vue ne fait que charger : elle lit la clé, appelle les deux routes
 * publiques et passe un état à `SuiviParLien`, qui rend tout. Route `public`,
 * `cadre: false` : ni en-tête applicatif ni barre du bas — une personne sans
 * compte n'a rien à y trouver, et « Se connecter » lui dirait le contraire.
 *
 * Le client d'API ne redirige pas vers la connexion ici : les deux appels
 * portent `sansRattrapage` (voir `lib/api/tirages.ts`). Une `404` — clé
 * inconnue, révoquée ou remplacée, même réponse — devient « ce lien n'est plus
 * valable » ; une `429` (trop d'essais depuis cette adresse) reste une erreur,
 * que `MessageErreur` sait faire patienter.
 *
 * La clé arrive en **prop** (`props: true` sur la route), pas par `useRoute()` :
 * la vue reste rendable hors routeur.
 */
import { onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { definirPresenceParLien, estErreurApi, lireParLien, type PresenceAuTirage } from '@/lib/api'
import SuiviParLien, { type EtatDuLien } from '@/components/tirages/SuiviParLien.vue'

const { cle } = defineProps<{ cle: string }>()

const etat = ref<EtatDuLien>({ type: 'chargement' })
const enregistrement = ref(false)
const erreurDePresence = ref<unknown>(null)
let avorteur: AbortController | null = null

function etatDApresErreur(e: unknown): EtatDuLien {
  return estErreurApi(e) && e.statut === 404 ? { type: 'invalide' } : { type: 'erreur', erreur: e }
}

async function charger(): Promise<void> {
  avorteur?.abort()
  const courant = new AbortController()
  avorteur = courant
  etat.value = { type: 'chargement' }

  try {
    etat.value = { type: 'pret', lecture: await lireParLien(cle, courant.signal) }
  } catch (e) {
    if (courant.signal.aborted) return
    etat.value = etatDApresErreur(e)
  }
}

async function changerPresence(presence: PresenceAuTirage): Promise<void> {
  if (enregistrement.value) return
  enregistrement.value = true
  erreurDePresence.value = null

  try {
    // La réponse est la lecture relue : on remplace, sans recoller de morceaux.
    etat.value = { type: 'pret', lecture: await definirPresenceParLien(cle, presence) }
  } catch (e) {
    // Révoqué entre-temps : l'écran entier le dit, pas un message sous la présence.
    if (estErreurApi(e) && e.statut === 404) etat.value = { type: 'invalide' }
    else erreurDePresence.value = e
  } finally {
    enregistrement.value = false
  }
}

onMounted(charger)
watch(() => cle, charger)
onBeforeUnmount(() => avorteur?.abort())
</script>

<template>
  <SuiviParLien
    :etat="etat"
    :enregistrement="enregistrement"
    :erreur-de-presence="erreurDePresence"
    @presence="changerPresence"
    @reessayer="charger"
  />
</template>
