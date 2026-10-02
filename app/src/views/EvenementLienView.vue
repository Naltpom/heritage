<script setup lang="ts">
/**
 * `/e/<clé>` — une invitation ouverte par son **lien personnel**, sans compte
 * ni session.
 *
 * La vue ne fait que charger : elle lit la clé, appelle les deux routes
 * publiques et passe un état à `ReponseParLien`, qui rend tout. Route `public`,
 * `cadre: false` : ni en-tête applicatif ni barre du bas — une personne sans
 * compte n'a rien à y trouver. C'est la forme de `TirageLienView`.
 *
 * Le client d'API ne redirige pas vers la connexion ici : les deux appels
 * portent `sansRattrapage`. Une `404` — clé inconnue, révoquée ou remplacée,
 * même réponse — devient « ce lien n'est plus valable » ; une `429` reste une
 * erreur, que `MessageErreur` sait faire patienter.
 *
 * La clé arrive en **prop** (`props: true` sur la route) : la vue reste
 * rendable hors routeur.
 */
import { onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { estErreurApi, lireAnnonceParLien, repondreParLien, type ReponseAInvitation } from '@/lib/api'
import ReponseParLien, { type EtatDuLienDEvenement } from '@/components/evenements-familiaux/ReponseParLien.vue'

const { cle } = defineProps<{ cle: string }>()

const etat = ref<EtatDuLienDEvenement>({ type: 'chargement' })
const enCours = ref<ReponseAInvitation | null>(null)
const erreurDeReponse = ref<unknown>(null)
let avorteur: AbortController | null = null

function etatDApresErreur(e: unknown): EtatDuLienDEvenement {
  return estErreurApi(e) && e.statut === 404 ? { type: 'invalide' } : { type: 'erreur', erreur: e }
}

async function charger(): Promise<void> {
  avorteur?.abort()
  const courant = new AbortController()
  avorteur = courant
  etat.value = { type: 'chargement' }

  try {
    etat.value = { type: 'pret', lecture: await lireAnnonceParLien(cle, courant.signal) }
  } catch (e) {
    if (courant.signal.aborted) return
    etat.value = etatDApresErreur(e)
  }
}

async function repondre(reponse: ReponseAInvitation): Promise<void> {
  if (enCours.value !== null) return
  enCours.value = reponse
  erreurDeReponse.value = null

  try {
    // La réponse est la lecture relue : on remplace, sans recoller de morceaux.
    etat.value = { type: 'pret', lecture: await repondreParLien(cle, reponse) }
  } catch (e) {
    // Révoqué entre-temps : l'écran entier le dit, pas un message sous les boutons.
    if (estErreurApi(e) && e.statut === 404) etat.value = { type: 'invalide' }
    else erreurDeReponse.value = e
  } finally {
    enCours.value = null
  }
}

onMounted(charger)
watch(() => cle, charger)
onBeforeUnmount(() => avorteur?.abort())
</script>

<template>
  <ReponseParLien
    :etat="etat"
    :en-cours="enCours"
    :erreur-de-reponse="erreurDeReponse"
    @repondre="repondre"
    @reessayer="charger"
  />
</template>
