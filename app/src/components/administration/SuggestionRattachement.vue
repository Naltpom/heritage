<script setup lang="ts">
/**
 * La suggestion de l'analyse automatique (Gemini), sur une carte « À rattacher ».
 *
 * **Elle éclaire, elle ne décide pas.** Aucun rattachement ne part d'ici sans
 * un clic de l'administrateur, et les boutons « Rattacher à … » que ce
 * composant porte ne font que le rapporter à la carte, qui agit.
 *
 * Sept états, décidés par `rattachements.ts` (`EtatDAnalyse`) :
 *
 *  - `lecture` — l'écran relit la recherche déjà faite (gratuit) ;
 *  - `aucune` — **rien n'est lancé tout seul** (3 octobre 2026) : une phrase,
 *    et « Lancer la recherche » ;
 *  - `attente` et `en_cours` — la recherche prend 20 à 60 secondes, et les
 *    cartes passent **l'une après l'autre** : une carte peut attendre son tour ;
 *  - `prete` — verdict, résumé, fiche désignée, autres pistes, création ; et,
 *    si la transcription a changé depuis, « La transcription a changé :
 *    relancez la recherche » (`perimee`) ;
 *  - `non_configuree` — pas de clé : une phrase neutre, **pas** de « Relancer »,
 *    qui échouerait toujours pareil ;
 *  - `echec` — le message, et « Relancer la recherche ».
 *
 * Le bouton de recherche porte, à côté, **ce qu'il reste du quota** quand le
 * serveur le dit (`quota`) — sinon rien : on n'invente pas un compteur.
 *
 * Dans tous les cas, la carte reste utilisable à la main : c'est ce que la
 * dernière phrase de chaque état non prêt rappelle.
 *
 * `data-analyse` porte l'état sur le balisage, comme `data-rattachement` sur la
 * mention voisine : c'est la prise des tests de rendu.
 */
import { computed } from 'vue'
import type { SuggestionDeRattachement } from '@/lib/api'
import BoutonBase from '@/components/base/BoutonBase.vue'
import {
  LIBELLES_CONFIANCE,
  LIBELLES_VERDICT,
  libelleDuLancement,
  libelleDuQuota,
  type EtatDAnalyse,
  type QuotaRestant,
} from './rattachements'

const {
  analyse,
  suspendu = false,
  occupe = false,
  motifDeBlocage = null,
  avis = null,
  quota = null,
  infobulle,
} = defineProps<{
  analyse: EtatDAnalyse
  /** Écritures éteintes (bascule « voir comme »). */
  suspendu?: boolean
  /** Une action de la carte est en cours : les boutons attendent. */
  occupe?: boolean
  /** Pourquoi « Lancer la recherche » attend (transcription en cours, correction non enregistrée). */
  motifDeBlocage?: string | null
  /** Un avis qui ne bloque pas (« le message vocal n'est pas transcrit »). */
  avis?: string | null
  /** Ce qu'il reste du quota global, si le serveur l'a dit. */
  quota?: QuotaRestant | null
  infobulle?: string | undefined
}>()

const emit = defineEmits<{
  /** « Lancer la recherche » ou « Relancer la recherche » : un appel au modèle. */
  lancer: []
  rattacher: [personId: number, displayName: string]
}>()

const lancement = computed(() => libelleDuLancement(analyse))
const quotaEpuise = computed(() => quota !== null && quota.restant === 0)
const perimee = computed(() => analyse.etat === 'prete' && analyse.suggestion.perimee === true)

const suggestion = computed<SuggestionDeRattachement | null>(() =>
  analyse.etat === 'prete' ? analyse.suggestion : null,
)

/** Passe par une fonction plutôt que par le gabarit : le rétrécissement du
 *  `v-if` ne traverse pas la fermeture d'un gestionnaire d'événement. */
function rattacherLaFiche(): void {
  const fiche = suggestion.value?.fiche ?? null
  if (fiche !== null && !fiche.dejaRattachee) emit('rattacher', fiche.personId, fiche.displayName)
}

function horodatage(iso: string): string {
  const date = new Date(iso)
  return Number.isNaN(date.getTime())
    ? iso
    : date.toLocaleString('fr-FR', { day: 'numeric', month: 'long', hour: '2-digit', minute: '2-digit' })
}
</script>

<template>
  <section
    class="flex flex-col gap-2.5 rounded-panneau border border-ligne bg-fond px-3.5 py-3 text-sm"
    :data-analyse="analyse.etat"
    aria-label="Analyse automatique"
  >
    <header class="flex flex-wrap items-center justify-between gap-2">
      <h4 class="text-xs font-semibold tracking-wide text-attenue uppercase">Recherche dans l’arbre (IA)</h4>
    </header>

    <!-- ── Relecture de ce qui a déjà été calculé ─────────────────────── -->
    <p v-if="analyse.etat === 'lecture'" class="text-attenue" role="status">
      Lecture de la recherche déjà faite…
    </p>

    <!-- ── Rien de lancé : c'est à l'administrateur de le faire ──────── -->
    <p v-else-if="analyse.etat === 'aucune'" class="text-attenue" data-aucune>
      Aucune recherche lancée. Elle lit la déclaration — et la transcription du message vocal s’il y en a
      une — puis cherche la personne dans l’arbre (20 à 60 secondes). Vous pouvez aussi décider à la main.
    </p>

    <!-- ── En attente ou en cours ─────────────────────────────────────── -->
    <p v-else-if="analyse.etat === 'attente'" class="text-attenue" role="status">
      En file : les recherches passent l’une après l’autre.
    </p>
    <p v-else-if="analyse.etat === 'en_cours'" class="text-attenue" role="status">
      Recherche en cours (20 à 60 secondes). Vous pouvez décider sans l’attendre.
    </p>

    <!-- ── Pas de clé : un réglage, pas une panne ─────────────────────── -->
    <p v-else-if="analyse.etat === 'non_configuree'" class="text-attenue">
      Recherche automatique indisponible sur cette installation : choisissez une fiche, ou créez-la.
    </p>

    <!-- ── Échec : le message, et de quoi relancer ────────────────────── -->
    <div v-else-if="analyse.etat === 'echec'" class="text-alerte" role="alert">
      <p>{{ analyse.message }}</p>
      <p v-if="analyse.attenteSecondes !== null" class="mt-0.5 text-xs">
        Nouvel essai possible dans {{ Math.ceil(analyse.attenteSecondes / 60) }} min environ.
      </p>
    </div>

    <!-- ── Prête ───────────────────────────────────────────────────────── -->
    <template v-else-if="suggestion !== null">
      <!-- Faite sur une autre version de la transcription : montrée quand
           même (elle peut rester juste), mais signalée en tête. -->
      <p
        v-if="perimee"
        class="rounded-champ border border-accent bg-accent-doux px-2.5 py-1.5 text-accent"
        role="status"
        data-perimee
      >
        La transcription a changé depuis cette recherche : relancez la recherche.
      </p>
      <p class="font-semibold" :data-verdict="suggestion.verdict">{{ LIBELLES_VERDICT[suggestion.verdict] }}</p>
      <p class="whitespace-pre-line [overflow-wrap:anywhere]">{{ suggestion.resume }}</p>

      <ul v-if="suggestion.avertissements.length > 0" class="flex flex-col gap-1 text-xs text-accent">
        <li v-for="(avertissement, index) in suggestion.avertissements" :key="index">⚠ {{ avertissement }}</li>
      </ul>

      <!-- La fiche désignée. Déjà réclamée : montrée quand même, bouton éteint. -->
      <div v-if="suggestion.fiche !== null" class="rounded-champ border border-accent bg-panneau px-3 py-2">
        <p>
          <span class="font-medium">{{ suggestion.fiche.displayName }}</span>
          <span v-if="suggestion.fiche.ligneDates !== ''" class="ml-1.5 font-mono text-xs text-attenue tnum">
            {{ suggestion.fiche.ligneDates }}
          </span>
          <span class="ml-1.5 text-xs text-attenue">· {{ LIBELLES_CONFIANCE[suggestion.fiche.confiance] }}</span>
        </p>
        <p class="mt-0.5 text-attenue">{{ suggestion.fiche.justification }}</p>
        <p v-if="suggestion.fiche.dejaRattachee" class="mt-1 text-xs text-alerte">
          Cette fiche est déjà rattachée à un autre compte.
        </p>
        <div class="mt-2">
          <BoutonBase
            :desactive="suspendu || occupe || suggestion.fiche.dejaRattachee"
            @click="rattacherLaFiche"
          >
            Rattacher à {{ suggestion.fiche.displayName }}
          </BoutonBase>
        </div>
      </div>

      <!-- Les autres pistes : une ligne chacune, rattachables aussi. -->
      <div v-if="suggestion.alternatives.length > 0">
        <p class="mb-1 text-xs font-semibold text-attenue">Autres pistes</p>
        <ul class="flex flex-col gap-1">
          <li
            v-for="piste in suggestion.alternatives"
            :key="piste.personId"
            class="flex flex-wrap items-center justify-between gap-2 rounded-champ border border-ligne bg-panneau px-2.5 py-1.5"
          >
            <span class="min-w-0">
              <span class="font-medium">{{ piste.displayName }}</span>
              <span v-if="piste.ligneDates !== ''" class="ml-1.5 font-mono text-xs text-attenue tnum">
                {{ piste.ligneDates }}
              </span>
              <span class="block text-xs text-attenue">{{ piste.justification }}</span>
            </span>
            <BoutonBase
              variante="secondaire"
              :desactive="suspendu || occupe"
              @click="emit('rattacher', piste.personId, piste.displayName)"
            >
              Rattacher
            </BoutonBase>
          </li>
        </ul>
      </div>

      <!-- La création proposée : un aperçu, l'action est sur la carte. -->
      <div v-if="suggestion.creation !== null">
        <p class="mb-1 text-xs font-semibold text-attenue">
          Création proposée — {{ suggestion.creation.titre }}
          <span class="font-normal">· {{ LIBELLES_CONFIANCE[suggestion.creation.confiance] }}</span>
        </p>
        <p class="text-attenue">{{ suggestion.creation.justification }}</p>
      </div>

      <p class="text-xs text-attenue">
        Calculée le {{ horodatage(suggestion.calculeeLe) }} ({{ suggestion.modele }}). Rien n’est
        rattaché sans votre décision.
      </p>
    </template>

    <!-- ── Le bouton : rien ne part sans lui ──────────────────────────── -->
    <div v-if="lancement !== null" class="flex flex-col gap-1.5 border-t border-ligne pt-2.5" data-lancement>
      <p v-if="avis !== null" class="text-xs text-attenue" data-avis>{{ avis }}</p>
      <div class="flex flex-wrap items-center gap-x-3 gap-y-1.5">
        <BoutonBase
          :variante="analyse.etat === 'aucune' || perimee ? 'principal' : 'secondaire'"
          :desactive="suspendu || occupe || motifDeBlocage !== null || quotaEpuise"
          :title="infobulle ?? motifDeBlocage ?? undefined"
          data-lancer
          @click="emit('lancer')"
        >
          {{ lancement }}
        </BoutonBase>
        <span v-if="quota !== null" class="text-xs text-attenue tnum" data-quota>{{ libelleDuQuota(quota) }}</span>
      </div>
      <p v-if="motifDeBlocage !== null" class="text-xs text-accent" role="status" data-blocage>{{ motifDeBlocage }}</p>
      <p v-else-if="quotaEpuise" class="text-xs text-accent" role="status">
        Le quota du jour est atteint : rattachez à la main, ou réessayez plus tard.
      </p>
    </div>
  </section>
</template>
